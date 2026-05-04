<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External function to create an aitext question and add it to a quiz.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\external;

use context_course;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Creates a new qtype_aitext question and appends it to an existing quiz.
 *
 * The question is saved into the quiz module's default question category so it
 * lives alongside the quiz and is not shared across the site. The quiz slot is
 * created by the standard quiz_add_quiz_question() helper so all question
 * references and section records are created the same way the editing UI would.
 */
class add_question extends external_api {

    /**
     * Describes the parameters accepted by execute().
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(
                PARAM_INT,
                'ID of the quiz to add the question to'
            ),
            'name' => new external_value(
                PARAM_TEXT,
                'Question name shown in the question bank'
            ),
            'questiontext' => new external_value(
                PARAM_RAW,
                'Question text shown to the student (HTML)'
            ),
            'aiprompt' => new external_value(
                PARAM_RAW,
                'Prompt sent to the AI when evaluating a student response'
            ),
            'markscheme' => new external_value(
                PARAM_RAW,
                'Marking criteria / grading rubric',
                VALUE_DEFAULT,
                ''
            ),
            'model' => new external_value(
                PARAM_RAW,
                'AI model name (empty string = use site default)',
                VALUE_DEFAULT,
                ''
            ),
            'responseformat' => new external_value(
                PARAM_ALPHA,
                'Student response input style: editor, plain, or monospaced',
                VALUE_DEFAULT,
                'editor'
            ),
            'responsefieldlines' => new external_value(
                PARAM_INT,
                'Height of the student response box in lines',
                VALUE_DEFAULT,
                10
            ),
            'defaultmark' => new external_value(
                PARAM_FLOAT,
                'Maximum mark awarded for a correct response',
                VALUE_DEFAULT,
                1.0
            ),
            'spellcheck' => new external_value(
                PARAM_INT,
                'Enable automatic spell-check in the response box (0=no, 1=yes)',
                VALUE_DEFAULT,
                0
            ),
            'minwordlimit' => new external_value(
                PARAM_INT,
                'Minimum number of words required in the response (0 = no limit)',
                VALUE_DEFAULT,
                0
            ),
            'maxwordlimit' => new external_value(
                PARAM_INT,
                'Maximum number of words allowed in the response (0 = no limit)',
                VALUE_DEFAULT,
                0
            ),
            'sampleresponses' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'A sample response used for AI calibration'),
                'Sample responses',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Creates an aitext question and adds it to the given quiz.
     *
     * @param int    $quizid
     * @param string $name
     * @param string $questiontext
     * @param string $aiprompt
     * @param string $markscheme
     * @param string $model
     * @param string $responseformat
     * @param int    $responsefieldlines
     * @param float  $defaultmark
     * @param int    $spellcheck
     * @param int    $minwordlimit
     * @param int    $maxwordlimit
     * @param array  $sampleresponses
     * @return array{questionid: int, slotid: int}
     */
    public static function execute(
        int $quizid,
        string $name,
        string $questiontext,
        string $aiprompt,
        string $markscheme = '',
        string $model = '',
        string $responseformat = 'editor',
        int $responsefieldlines = 10,
        float $defaultmark = 1.0,
        int $spellcheck = 0,
        int $minwordlimit = 0,
        int $maxwordlimit = 0,
        array $sampleresponses = []
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid'             => $quizid,
            'name'               => $name,
            'questiontext'       => $questiontext,
            'aiprompt'           => $aiprompt,
            'markscheme'         => $markscheme,
            'model'              => $model,
            'responseformat'     => $responseformat,
            'responsefieldlines' => $responsefieldlines,
            'defaultmark'        => $defaultmark,
            'spellcheck'         => $spellcheck,
            'minwordlimit'       => $minwordlimit,
            'maxwordlimit'       => $maxwordlimit,
            'sampleresponses'    => $sampleresponses,
        ]);

        $quiz   = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);

        $coursecontext = context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('local/jomot:createquiz', $coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);

        if (!in_array($params['responseformat'], ['editor', 'plain', 'monospaced'], true)) {
            throw new \invalid_parameter_exception('responseformat must be editor, plain, or monospaced');
        }
        if ($params['minwordlimit'] < 0 || $params['maxwordlimit'] < 0) {
            throw new \invalid_parameter_exception('Word limits must not be negative');
        }
        if ($params['minwordlimit'] > 0 && $params['maxwordlimit'] > 0
                && $params['maxwordlimit'] < $params['minwordlimit']) {
            throw new \invalid_parameter_exception('maxwordlimit must not be less than minwordlimit');
        }

        return self::add_to_quiz($params['quizid'], $params);
    }

    /**
     * Creates an aitext question and adds it to a quiz. Safe to call outside web-service context.
     *
     * @param int   $quizid
     * @param array $params Keys: name, questiontext, aiprompt, markscheme, model, responseformat,
     *                      responsefieldlines, defaultmark, spellcheck, minwordlimit, maxwordlimit,
     *                      sampleresponses.
     * @return array{questionid: int, slotid: int}
     */
    public static function add_to_quiz(int $quizid, array $params): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $params = array_merge([
            'markscheme'         => '',
            'model'              => '',
            'responseformat'     => 'editor',
            'responsefieldlines' => 10,
            'defaultmark'        => 1.0,
            'spellcheck'         => 0,
            'minwordlimit'       => 0,
            'maxwordlimit'       => 0,
            'sampleresponses'    => [],
        ], $params);

        $quiz   = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm     = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $quiz->cmid = $cm->id;
        $cmcontext  = context_module::instance($cm->id);
        $category   = question_get_default_category($cmcontext->id, true);

        $resolvedmodel = $params['model'];
        if (empty($resolvedmodel)) {
            $resolvedmodel = explode(',', get_config('tool_aiconnect', 'model') ?: '')[0] ?? '';
        }

        $form                     = new \stdClass();
        $form->category           = "{$category->id},{$cmcontext->id}";
        $form->name               = $params['name'];
        $form->questiontext       = ['text' => $params['questiontext'], 'format' => FORMAT_HTML];
        $form->generalfeedback    = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark        = $params['defaultmark'];
        $form->penalty            = 0;
        $form->status             = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $form->aiprompt           = $params['aiprompt'];
        $form->markscheme         = $params['markscheme'];
        $form->model              = $resolvedmodel;
        $form->responseformat     = $params['responseformat'];
        $form->responsefieldlines = $params['responsefieldlines'];
        $form->spellcheck         = $params['spellcheck'];
        $form->maxbytes           = 0;
        $form->graderinfo         = ['text' => '', 'format' => FORMAT_HTML];
        $form->responsetemplate   = ['text' => '', 'format' => FORMAT_HTML];
        $form->sampleresponses    = $params['sampleresponses'];

        if ($params['minwordlimit'] > 0) {
            $form->minwordenabled = true;
            $form->minwordlimit   = $params['minwordlimit'];
        }
        if ($params['maxwordlimit'] > 0) {
            $form->maxwordenabled = true;
            $form->maxwordlimit   = $params['maxwordlimit'];
        }

        $qtype    = \question_bank::get_qtype('aitext');
        $question = new \stdClass();
        $question->qtype = 'aitext';
        $saved = $qtype->save_question($question, $form);

        quiz_add_quiz_question($saved->id, $quiz, 0, $params['defaultmark']);

        \mod_quiz\quiz_settings::create($quiz->id)
            ->get_grade_calculator()
            ->recompute_quiz_sumgrades();

        $slot = $DB->get_record_sql(
            'SELECT id FROM {quiz_slots} WHERE quizid = ? ORDER BY slot DESC',
            [$quiz->id],
            IGNORE_MULTIPLE
        );

        return [
            'questionid' => (int) $saved->id,
            'slotid'     => (int) ($slot ? $slot->id : 0),
        ];
    }

    /**
     * Describes the return value of execute().
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'ID of the newly created question'),
            'slotid'     => new external_value(PARAM_INT, 'ID of the quiz slot the question was placed in'),
        ]);
    }
}
