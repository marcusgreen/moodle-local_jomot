<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jomot\task;

use advanced_testcase;

/**
 * Tests for where the adhoc task places the generated quiz on the course page.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jomot\task\create_quiz_adhoc
 */
final class create_quiz_adhoc_test extends advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student whose submission triggers the quiz. */
    private \stdClass $student;

    /**
     * Sets up a course with sections and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course  = $generator->create_course(['numsections' => 3]);
        $this->student = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Jones']);
    }

    /**
     * Runs the adhoc task for an assignment and returns the generated quiz's cm.
     *
     * @param int $contextid Assignment module context id (0 = none).
     * @return \cm_info
     */
    private function run_task(int $contextid): \cm_info {
        global $DB;

        $task = new create_quiz_adhoc();
        $task->set_custom_data([
            'courseid'       => $this->course->id,
            'userid'         => $this->student->id,
            'assignmentname' => 'Essay',
            'contextid'      => $contextid,
            'submissionid'   => 0,
            'numquestions'   => 1,
            'templatequiz'   => 0,
        ]);
        // The task calls mtrace(); capture it so the test is not flagged risky.
        ob_start();
        $task->execute();
        ob_end_clean();

        $quiz = $DB->get_record('quiz', ['course' => $this->course->id], '*', MUST_EXIST);
        return get_fast_modinfo($this->course->id)->get_instances_of('quiz')[$quiz->id];
    }

    /**
     * Returns the ordered cm ids in a section.
     *
     * @param int $sectionnum
     * @return int[]
     */
    private function section_sequence(int $sectionnum): array {
        return array_map('intval', get_fast_modinfo($this->course->id)->sections[$sectionnum] ?? []);
    }

    /**
     * Moves a course module to the end of a section.
     *
     * cmactions::move_end_section() only exists from Moodle 5.2, which deprecates
     * moveto_module(), so use whichever this Moodle version provides.
     *
     * @param int $cmid
     * @param int $sectionnum
     */
    private function move_cm_to_section_end(int $cmid, int $sectionnum): void {
        $section = get_fast_modinfo($this->course->id)->get_section_info($sectionnum);
        $cmactions = \core_courseformat\formatactions::cm($this->course);
        if (method_exists($cmactions, 'move_end_section')) {
            $cmactions->move_end_section($cmid, (int) $section->id);
        } else {
            moveto_module(get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST), $section);
        }
    }

    /**
     * Deletes a course module.
     *
     * cmactions::delete() only exists from Moodle 5.2, which deprecates
     * course_delete_module(), so use whichever this Moodle version provides.
     *
     * @param int $cmid
     */
    private function delete_cm(int $cmid): void {
        $cmactions = \core_courseformat\formatactions::cm($this->course);
        if (method_exists($cmactions, 'delete')) {
            $cmactions->delete($cmid);
        } else {
            course_delete_module($cmid);
        }
    }

    /**
     * The quiz goes in the assignment's section, directly after it.
     */
    public function test_quiz_placed_directly_after_assignment(): void {
        $generator = $this->getDataGenerator();
        $assign = $generator->create_module('assign', ['course' => $this->course->id, 'section' => 2]);
        $page   = $generator->create_module('page', ['course' => $this->course->id, 'section' => 2]);

        $quizcm = $this->run_task((int) \context_module::instance($assign->cmid)->id);

        $this->assertSame(2, (int) $quizcm->sectionnum);
        $this->assertSame([(int) $assign->cmid, (int) $quizcm->id, (int) $page->cmid], $this->section_sequence(2));
    }

    /**
     * When the assignment is last in its section, the quiz is appended after it.
     */
    public function test_quiz_appended_when_assignment_is_last(): void {
        $generator = $this->getDataGenerator();
        $page   = $generator->create_module('page', ['course' => $this->course->id, 'section' => 1]);
        $assign = $generator->create_module('assign', ['course' => $this->course->id, 'section' => 1]);

        $quizcm = $this->run_task((int) \context_module::instance($assign->cmid)->id);

        $this->assertSame([(int) $page->cmid, (int) $assign->cmid, (int) $quizcm->id], $this->section_sequence(1));
    }

    /**
     * An assignment inside a subsection gets its quiz in that subsection.
     */
    public function test_quiz_placed_in_subsection(): void {
        $generator = $this->getDataGenerator();
        $subsection = $generator->create_module('subsection', ['course' => $this->course->id, 'section' => 1]);
        $delegated = get_fast_modinfo($this->course->id)
            ->get_section_info_by_component('mod_subsection', $subsection->id);
        $assign = $generator->create_module('assign', [
            'course'  => $this->course->id,
            'section' => $delegated->section,
        ]);

        $quizcm = $this->run_task((int) \context_module::instance($assign->cmid)->id);

        $this->assertSame((int) $delegated->section, (int) $quizcm->sectionnum);
        $this->assertSame([(int) $assign->cmid, (int) $quizcm->id], $this->section_sequence($delegated->section));
    }

    /**
     * The quiz follows the assignment's current position, not where it was
     * when the task was queued.
     */
    public function test_quiz_follows_assignment_moved_before_task_runs(): void {
        $generator = $this->getDataGenerator();
        $assign = $generator->create_module('assign', ['course' => $this->course->id, 'section' => 1]);
        $contextid = (int) \context_module::instance($assign->cmid)->id;

        $this->move_cm_to_section_end((int) $assign->cmid, 3);

        $quizcm = $this->run_task($contextid);

        $this->assertSame(3, (int) $quizcm->sectionnum);
    }

    /**
     * Without a resolvable assignment the quiz falls back to the General section.
     */
    public function test_quiz_falls_back_to_general_section(): void {
        $quizcm = $this->run_task(0);

        $this->assertSame(0, (int) $quizcm->sectionnum);
    }

    /**
     * A deleted assignment does not stop the quiz being created.
     */
    public function test_quiz_falls_back_when_assignment_deleted(): void {
        $generator = $this->getDataGenerator();
        $assign = $generator->create_module('assign', ['course' => $this->course->id, 'section' => 2]);
        $contextid = (int) \context_module::instance($assign->cmid)->id;
        $this->delete_cm((int) $assign->cmid);

        $quizcm = $this->run_task($contextid);

        $this->assertSame(0, (int) $quizcm->sectionnum);
    }
}
