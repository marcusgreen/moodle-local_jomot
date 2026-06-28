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
 * PHPUnit tests for copying questions from a template quiz in create_quiz.
 *
 * @package    local_jomot
 * @category   external
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\external;

use advanced_testcase;

/**
 * Tests that create_quiz copies questions from a template quiz.
 *
 * @covers \local_jomot\external\create_quiz
 */
final class create_quiz_template_test extends advanced_testcase {
    /** @var \stdClass Course used across tests. */
    private \stdClass $course;

    /** @var \stdClass Editing teacher enrolled in $course. */
    private \stdClass $teacher;

    /**
     * Sets up a course and an editing teacher before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator     = $this->getDataGenerator();
        $this->course  = $generator->create_course();
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $this->setUser($this->teacher);
    }

    /**
     * Builds a template quiz containing $count concrete questions.
     *
     * @param int $count Number of questions to add to the template.
     * @return \stdClass The template quiz record (with cmid set).
     */
    private function make_template_quiz(int $count): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz    = $quizgen->create_instance(['course' => $this->course->id, 'grade' => 100.0]);
        $quiz->cmid = $quiz->cmid ?? get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id)->id;

        $cmcontext = \context_module::instance($quiz->cmid);
        $qgen      = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat       = $qgen->create_question_category(['contextid' => $cmcontext->id]);

        for ($i = 1; $i <= $count; $i++) {
            $q = $qgen->create_question('truefalse', null, ['category' => $cat->id, 'name' => "Template Q$i"]);
            quiz_add_quiz_question($q->id, $quiz, 0, 2.0);
        }

        return $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
    }

    /**
     * Returns the question ids referenced by a quiz's slots, ordered by slot.
     *
     * @param int $quizid
     * @return int[]
     */
    private function slot_question_ids(int $quizid): array {
        global $DB;
        $sql = "SELECT slot.slot, qv.questionid
                  FROM {quiz_slots} slot
                  JOIN {question_references} qr ON qr.itemid = slot.id
                                               AND qr.component = 'mod_quiz'
                                               AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE slot.quizid = ?
              ORDER BY slot.slot";
        $rows = $DB->get_records_sql($sql, [$quizid]);
        return array_map(static fn($r) => (int) $r->questionid, array_values($rows));
    }

    /**
     * Concrete template questions are copied, in order, with maxmark preserved.
     */
    public function test_template_questions_are_copied(): void {
        global $DB;

        $template = $this->make_template_quiz(2);
        $templateqids = $this->slot_question_ids($template->id);
        $this->assertCount(2, $templateqids);

        $result = create_quiz::create($this->course->id, 'Student Quiz', [
            'templatequiz' => $template->id,
        ]);

        // Same questions, same order, added by reference (identical question ids).
        $newqids = $this->slot_question_ids($result['quizid']);
        $this->assertSame($templateqids, $newqids);

        // Per-slot maxmark preserved from the template.
        $maxmarks = $DB->get_fieldset_select('quiz_slots', 'maxmark', 'quizid = ? ORDER BY slot', [$result['quizid']]);
        foreach ($maxmarks as $m) {
            $this->assertEquals(2.0, (float) $m);
        }
    }

    /**
     * A template with no questions yields a quiz with no slots and no error.
     */
    public function test_empty_template_copies_nothing(): void {
        global $DB;

        $template = $this->make_template_quiz(0);

        $result = create_quiz::create($this->course->id, 'Student Quiz', [
            'templatequiz' => $template->id,
        ]);

        $this->assertEquals(0, $DB->count_records('quiz_slots', ['quizid' => $result['quizid']]));
    }

    /**
     * Creating without a template behaves as before (no slots, no error).
     */
    public function test_no_template_no_questions(): void {
        global $DB;

        $result = create_quiz::create($this->course->id, 'Plain Quiz');

        $this->assertEquals(0, $DB->count_records('quiz_slots', ['quizid' => $result['quizid']]));
    }
}
