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
 * Library functions.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook to add elements to the assignment settings form.
 *
 * @param \moodleform_mod $formwrapper
 * @param \MoodleQuickForm $mform
 */
function local_jomot_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    if ($formwrapper->get_current()->modulename !== 'assign') {
        return;
    }

    $mform->addElement('header', 'local_jomot_header', get_string('pluginname', 'local_jomot'));

    $mform->addElement('advcheckbox', 'local_jomot_enable_quiz',
        get_string('enable_quiz_label', 'local_jomot'),
        get_string('enable_quiz_desc', 'local_jomot'),
        ['group' => 1], [0, 1]);

    $mform->addHelpButton('local_jomot_enable_quiz', 'enable_quiz', 'local_jomot');

    $mform->addElement('text', 'local_jomot_numquestions',
        get_string('numquestions_label', 'local_jomot'), ['size' => 4]);
    $mform->setType('local_jomot_numquestions', PARAM_INT);
    $mform->setDefault('local_jomot_numquestions', \local_jomot\constants::DEFAULT_NUMQUESTIONS);
    $mform->hideIf('local_jomot_numquestions', 'local_jomot_enable_quiz', 'eq', 0);

    $mform->addElement('advcheckbox', 'local_jomot_quizvisible',
        get_string('quizvisible_label', 'local_jomot'),
        get_string('quizvisible_desc', 'local_jomot'),
        ['group' => 1], [0, 1]);
    $mform->setDefault('local_jomot_quizvisible', 0);
    $mform->addHelpButton('local_jomot_quizvisible', 'quizvisible', 'local_jomot');
    $mform->hideIf('local_jomot_quizvisible', 'local_jomot_enable_quiz', 'eq', 0);

    $mform->addElement('textarea', 'local_jomot_prompt',
        get_string('prompt_label', 'local_jomot'), ['rows' => 4, 'cols' => 50]);
    $mform->setType('local_jomot_prompt', PARAM_TEXT);
    $mform->addHelpButton('local_jomot_prompt', 'prompt', 'local_jomot');
    $mform->hideIf('local_jomot_prompt', 'local_jomot_enable_quiz', 'eq', 0);

    $quizoptions = [0 => get_string('templatequiz_none', 'local_jomot')]
        + local_jomot_get_template_quizzes((int)$formwrapper->get_course()->id);
    $mform->addElement('select', 'local_jomot_templatequiz',
        get_string('templatequiz_label', 'local_jomot'), $quizoptions);
    $mform->setType('local_jomot_templatequiz', PARAM_INT);
    $mform->setDefault('local_jomot_templatequiz', 0);
    $mform->addHelpButton('local_jomot_templatequiz', 'templatequiz', 'local_jomot');
    $mform->hideIf('local_jomot_templatequiz', 'local_jomot_enable_quiz', 'eq', 0);

    $mform->addElement(
        'text',
        'local_jomot_questionmark',
        get_string('questionmark_label', 'local_jomot'),
        ['size' => 6]
    );
    $mform->setType('local_jomot_questionmark', PARAM_RAW_TRIMMED);
    $mform->setDefault('local_jomot_questionmark', 1);
    $mform->addHelpButton('local_jomot_questionmark', 'questionmark', 'local_jomot');
    $mform->hideIf('local_jomot_questionmark', 'local_jomot_enable_quiz', 'eq', 0);

    $mform->addElement(
        'text',
        'local_jomot_quizgrade',
        get_string('quizgrade_label', 'local_jomot'),
        ['size' => 6]
    );
    $mform->setType('local_jomot_quizgrade', PARAM_RAW_TRIMMED);
    $mform->setDefault('local_jomot_quizgrade', '');
    $mform->addHelpButton('local_jomot_quizgrade', 'quizgrade', 'local_jomot');
    $mform->hideIf('local_jomot_quizgrade', 'local_jomot_enable_quiz', 'eq', 0);

    // Override defaults with saved values when editing an existing assignment.
    $update = optional_param('update', 0, PARAM_INT);
    if ($update) {
        $cm = get_coursemodule_from_id('assign', $update);
        if ($cm) {
            $config = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $cm->instance]);
            if ($config) {
                $mform->setDefault('local_jomot_enable_quiz', (int)$config->enable_quiz);
                $mform->setDefault('local_jomot_numquestions', max(1, (int)$config->numquestions ?: \local_jomot\constants::DEFAULT_NUMQUESTIONS));
                $mform->setDefault('local_jomot_quizvisible', (int)$config->quizvisible);
                $mform->setDefault('local_jomot_prompt', $config->prompt ?? '');
                $mform->setDefault('local_jomot_templatequiz', (int)($config->templatequiz ?? 0));
                $mform->setDefault('local_jomot_questionmark', format_float((float)($config->questionmark ?? 1), -1));
                $quizgrade = $config->quizgrade === null ? '' : format_float((float)$config->quizgrade, -1);
                $mform->setDefault('local_jomot_quizgrade', $quizgrade);
            }
        }
    }
}

/**
 * Returns quizzes in a course usable as generation templates, keyed by quiz id.
 *
 * When the admin 'templatetag' setting is non-empty, only quizzes whose activity
 * carries that tag are returned; otherwise every quiz in the course is listed.
 *
 * @param int $courseid
 * @return array<int, string> Quiz id => quiz name.
 */
function local_jomot_get_template_quizzes(int $courseid): array {
    global $DB;

    if (!$courseid) {
        return [];
    }

    $params   = ['courseid' => $courseid];
    $tagjoin  = '';
    $tagwhere = '';

    $templatetag = trim((string) get_config('local_jomot', 'templatetag'));
    if ($templatetag !== '') {
        $tagjoin = "
              JOIN {tag_instance} ti ON ti.itemid = cm.id
                                    AND ti.component = 'core'
                                    AND ti.itemtype = 'course_modules'
              JOIN {tag} t ON t.id = ti.tagid";
        $tagwhere = ' AND ' . $DB->sql_like('t.rawname', ':templatetag', false);
        $params['templatetag'] = $DB->sql_like_escape($templatetag);
    }

    $quizzes = $DB->get_records_sql("
        SELECT q.id, q.name
          FROM {quiz} q
          JOIN {course_modules} cm ON cm.instance = q.id
          JOIN {modules} m ON m.id = cm.module
          $tagjoin
         WHERE q.course = :courseid
           AND m.name = 'quiz'
           AND cm.deletioninprogress = 0
           $tagwhere
      ORDER BY q.name", $params);

    $options = [];
    foreach ($quizzes as $q) {
        $options[$q->id] = format_string($q->name);
    }

    return $options;
}

/**
 * Hook to validate the Just One More Thing fields on the assignment settings form.
 *
 * @param \moodleform_mod $formwrapper
 * @param array $data Submitted form data.
 * @return array Errors keyed by element name.
 */
function local_jomot_coursemodule_validation($formwrapper, $data) {
    $errors = [];

    if (($data['modulename'] ?? '') !== 'assign' || empty($data['local_jomot_enable_quiz'])) {
        return $errors;
    }

    $questionmark = unformat_float($data['local_jomot_questionmark'] ?? '', true);
    if ($questionmark === false || $questionmark === null || $questionmark <= 0) {
        $errors['local_jomot_questionmark'] = get_string('err_questionmark', 'local_jomot');
    }

    $quizgrade = trim((string)($data['local_jomot_quizgrade'] ?? ''));
    if ($quizgrade !== '') {
        $quizgrade = unformat_float($quizgrade, true);
        if ($quizgrade === false || $quizgrade < 0) {
            $errors['local_jomot_quizgrade'] = get_string('err_quizgrade', 'local_jomot');
        }
    }

    return $errors;
}

/**
 * Hook to save Just One More Thing settings after the assignment form is submitted.
 *
 * @param \stdClass $data
 * @param \stdClass $course
 * @return \stdClass
 */
function local_jomot_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    if ($data->modulename !== 'assign' || empty($data->instance)) {
        return $data;
    }

    $enablequiz   = isset($data->local_jomot_enable_quiz) ? (int)$data->local_jomot_enable_quiz : 0;
    $numquestions = min(
        \local_jomot\constants::MAX_NUMQUESTIONS,
        max(1, isset($data->local_jomot_numquestions) ? (int)$data->local_jomot_numquestions : \local_jomot\constants::DEFAULT_NUMQUESTIONS)
    );
    $quizvisible = isset($data->local_jomot_quizvisible) ? (int)$data->local_jomot_quizvisible : 0;
    $prompt      = isset($data->local_jomot_prompt) ? clean_param($data->local_jomot_prompt, PARAM_TEXT) : '';
    $templatequiz = isset($data->local_jomot_templatequiz) ? (int)$data->local_jomot_templatequiz : 0;
    $questionmark = unformat_float($data->local_jomot_questionmark ?? '', true);
    if (!$questionmark || $questionmark <= 0) {
        $questionmark = 1.0;
    }
    // An empty grade means "use the template quiz grade, or the plugin default".
    $quizgrade = trim((string)($data->local_jomot_quizgrade ?? ''));
    $quizgrade = $quizgrade === '' ? null : unformat_float($quizgrade, true);
    if ($quizgrade === false || ($quizgrade !== null && $quizgrade < 0)) {
        $quizgrade = null;
    }

    $existing = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $data->instance]);

    if ($existing) {
        $existing->enable_quiz   = $enablequiz;
        $existing->numquestions  = $numquestions;
        $existing->quizvisible   = $quizvisible;
        $existing->prompt        = $prompt;
        $existing->templatequiz  = $templatequiz;
        $existing->questionmark  = $questionmark;
        $existing->quizgrade     = $quizgrade;
        $existing->timemodified  = time();
        $DB->update_record('local_jomot_assign_config', $existing);
    } else {
        $DB->insert_record('local_jomot_assign_config', (object)[
            'assignmentid' => $data->instance,
            'enable_quiz'  => $enablequiz,
            'numquestions' => $numquestions,
            'quizvisible'  => $quizvisible,
            'prompt'       => $prompt,
            'templatequiz' => $templatequiz,
            'questionmark' => $questionmark,
            'quizgrade'    => $quizgrade,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    return $data;
}
