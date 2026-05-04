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
 * PHPUnit tests for local_jomot\external\add_question.
 *
 * @package    local_jomot
 * @category   external
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\external;

use advanced_testcase;
use context_course;

/**
 * Test suite for the add_question external function.
 *
 * @covers \local_jomot\external\add_question
 */
final class add_question_test extends advanced_testcase {

    /** @var \stdClass Course used across tests. */
    private \stdClass $course;

    /** @var \stdClass Editing teacher enrolled in $course. */
    private \stdClass $teacher;

    /** @var \stdClass Quiz used across tests. */
    private \stdClass $quiz;

    /**
     * Sets up a course, an editing teacher, and a quiz before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator      = $this->getDataGenerator();
        $this->course   = $generator->create_course();
        $this->teacher  = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        /** @var \mod_quiz_generator $quizgen */
        $quizgen     = $generator->get_plugin_generator('mod_quiz');
        $this->quiz  = $quizgen->create_instance(['course' => $this->course->id]);

        $this->setUser($this->teacher);
    }

    /**
     * A question created with only required fields is stored correctly in the
     * question bank and linked to the quiz.
     */
    public function test_add_question_minimal(): void {
        global $DB;

        $result = add_question::execute(
            quizid:       $this->quiz->id,
            name:         'My AI Question',
            questiontext: '<p>Describe the water cycle.</p>',
            aiprompt:     'Evaluate whether the student correctly described evaporation, condensation, and precipitation.',
        );

        $this->assertArrayHasKey('questionid', $result);
        $this->assertArrayHasKey('slotid', $result);
        $this->assertGreaterThan(0, $result['questionid']);
        $this->assertGreaterThan(0, $result['slotid']);

        // Question record exists with correct type.
        $question = $DB->get_record('question', ['id' => $result['questionid']], '*', MUST_EXIST);
        $this->assertSame('aitext', $question->qtype);
        $this->assertSame('My AI Question', $question->name);

        // aitext-specific options exist.
        $options = $DB->get_record('qtype_aitext', ['questionid' => $result['questionid']], '*', MUST_EXIST);
        $this->assertSame(
            'Evaluate whether the student correctly described evaporation, condensation, and precipitation.',
            $options->aiprompt
        );

        // Slot links the question to the quiz.
        $slot = $DB->get_record('quiz_slots', ['id' => $result['slotid']], '*', MUST_EXIST);
        $this->assertEquals($this->quiz->id, $slot->quizid);
    }

    /**
     * Optional settings are stored in the qtype_aitext options row.
     */
    public function test_add_question_with_optional_settings(): void {
        global $DB;

        $result = add_question::execute(
            quizid:             $this->quiz->id,
            name:               'Water Cycle Question',
            questiontext:       '<p>Describe the water cycle.</p>',
            aiprompt:           'Check for evaporation, condensation, precipitation.',
            markscheme:         'Award one mark per correct stage named.',
            responseformat:     'plain',
            responsefieldlines: 15,
            defaultmark:        3.0,
            spellcheck:         1,
            minwordlimit:       50,
            maxwordlimit:       200,
            sampleresponses:    ['Water evaporates, rises, condenses into clouds, then falls as rain.'],
        );

        $options = $DB->get_record('qtype_aitext', ['questionid' => $result['questionid']], '*', MUST_EXIST);
        $this->assertSame('Award one mark per correct stage named.', $options->markscheme);
        $this->assertSame('plain', $options->responseformat);
        $this->assertEquals(15, $options->responsefieldlines);
        $this->assertEquals(1, $options->spellcheck);
        $this->assertEquals(50, $options->minwordlimit);
        $this->assertEquals(200, $options->maxwordlimit);

        $question = $DB->get_record('question', ['id' => $result['questionid']], '*', MUST_EXIST);
        $this->assertEquals(3.0, (float) $question->defaultmark);

        $samples = $DB->get_records('qtype_aitext_sampleresponses', ['question' => $result['questionid']]);
        $this->assertCount(1, $samples);
        $sample = reset($samples);
        $this->assertSame('Water evaporates, rises, condenses into clouds, then falls as rain.', $sample->response);
    }

    /**
     * Two questions added to the same quiz get distinct IDs and slots.
     */
    public function test_add_multiple_questions(): void {
        $result1 = add_question::execute(
            quizid:       $this->quiz->id,
            name:         'Question One',
            questiontext: '<p>First question.</p>',
            aiprompt:     'Prompt one.',
        );
        $result2 = add_question::execute(
            quizid:       $this->quiz->id,
            name:         'Question Two',
            questiontext: '<p>Second question.</p>',
            aiprompt:     'Prompt two.',
        );

        $this->assertNotEquals($result1['questionid'], $result2['questionid']);
        $this->assertNotEquals($result1['slotid'], $result2['slotid']);
    }

    /**
     * An invalid responseformat must throw an invalid_parameter_exception.
     */
    public function test_invalid_responseformat(): void {
        $this->expectException(\invalid_parameter_exception::class);

        add_question::execute(
            quizid:         $this->quiz->id,
            name:           'Bad Format',
            questiontext:   '<p>Question.</p>',
            aiprompt:       'Prompt.',
            responseformat: 'wysiwyg',
        );
    }

    /**
     * maxwordlimit less than minwordlimit must throw an invalid_parameter_exception.
     */
    public function test_inverted_word_limits(): void {
        $this->expectException(\invalid_parameter_exception::class);

        add_question::execute(
            quizid:       $this->quiz->id,
            name:         'Word Limit Question',
            questiontext: '<p>Question.</p>',
            aiprompt:     'Prompt.',
            minwordlimit: 200,
            maxwordlimit: 50,
        );
    }

    /**
     * A student without the required capability must not be able to add a question.
     */
    public function test_add_question_requires_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        add_question::execute(
            quizid:       $this->quiz->id,
            name:         'Should Fail',
            questiontext: '<p>Question.</p>',
            aiprompt:     'Prompt.',
        );
    }

    /**
     * Passing a non-existent quiz ID must throw a dml_missing_record_exception.
     */
    public function test_invalid_quiz_id(): void {
        $this->expectException(\dml_missing_record_exception::class);

        add_question::execute(
            quizid:       999999,
            name:         'No Quiz',
            questiontext: '<p>Question.</p>',
            aiprompt:     'Prompt.',
        );
    }

    /**
     * The question must appear in the quiz's module-level question category.
     */
    public function test_question_placed_in_quiz_category(): void {
        global $DB;

        $cm         = get_coursemodule_from_instance('quiz', $this->quiz->id, $this->course->id, false, MUST_EXIST);
        $cmcontext  = \context_module::instance($cm->id);

        $result = add_question::execute(
            quizid:       $this->quiz->id,
            name:         'Category Check',
            questiontext: '<p>Question.</p>',
            aiprompt:     'Prompt.',
        );

        $bankentry = $DB->get_record_sql(
            'SELECT qbe.questioncategoryid
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              WHERE qv.questionid = ?',
            [$result['questionid']],
            MUST_EXIST
        );

        $category = $DB->get_record('question_categories', ['id' => $bankentry->questioncategoryid], '*', MUST_EXIST);
        $this->assertEquals($cmcontext->id, $category->contextid);
    }
}
