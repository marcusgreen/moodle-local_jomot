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
 * PHPUnit tests for local_jomot\external\create_quiz.
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
 * Test suite for the create_quiz external function.
 *
 * @covers \local_jomot\external\create_quiz
 */
final class create_quiz_test extends advanced_testcase {
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

        $generator    = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $this->setUser($this->teacher);
    }

    /**
     * A quiz created with only required fields is stored correctly.
     */
    public function test_create_quiz_minimal(): void {
        global $DB;

        $result = create_quiz::execute($this->course->id, 'My Test Quiz');

        $this->assertArrayHasKey('cmid', $result);
        $this->assertArrayHasKey('quizid', $result);
        $this->assertArrayHasKey('name', $result);

        $this->assertGreaterThan(0, $result['cmid']);
        $this->assertGreaterThan(0, $result['quizid']);
        $this->assertSame('My Test Quiz', $result['name']);

        // Verify the course module exists.
        $cm = get_coursemodule_from_id('quiz', $result['cmid'], $this->course->id, false, MUST_EXIST);
        $this->assertEquals($result['quizid'], $cm->instance);

        // Verify the quiz record.
        $quiz = $DB->get_record('quiz', ['id' => $result['quizid']], '*', MUST_EXIST);
        $this->assertEquals($this->course->id, $quiz->course);
        $this->assertSame('My Test Quiz', $quiz->name);
    }

    /**
     * Optional quiz settings are persisted to the database.
     */
    public function test_create_quiz_with_settings(): void {
        global $DB;

        $timeopen  = mktime(9, 0, 0, 6, 1, 2027);
        $timeclose = mktime(17, 0, 0, 6, 30, 2027);

        $result = create_quiz::execute(
            courseid:           $this->course->id,
            name:               'Settings Quiz',
            intro:              '<p>Test intro</p>',
            introformat:        FORMAT_HTML,
            timeopen:           $timeopen,
            timeclose:          $timeclose,
            timelimit:          3600,
            grade:              100.0,
            attempts:           3,
            questionsperpage:   5,
            shuffleanswers:     1,
            preferredbehaviour: 'immediatefeedback',
            visible:            1,
            sectionnum:         0,
        );

        $quiz = $DB->get_record('quiz', ['id' => $result['quizid']], '*', MUST_EXIST);

        $this->assertEquals($timeopen, $quiz->timeopen);
        $this->assertEquals($timeclose, $quiz->timeclose);
        $this->assertEquals(3600, $quiz->timelimit);
        $this->assertEquals(100.0, (float) $quiz->grade);
        $this->assertEquals(3, $quiz->attempts);
        $this->assertEquals(5, $quiz->questionsperpage);
        $this->assertEquals(1, $quiz->shuffleanswers);
        $this->assertSame('immediatefeedback', $quiz->preferredbehaviour);
    }

    /**
     * A quiz created as hidden must have visible = 0 in course_modules.
     */
    public function test_create_quiz_hidden(): void {
        global $DB;

        $result = create_quiz::execute(
            courseid: $this->course->id,
            name:     'Hidden Quiz',
            visible:  0,
        );

        $cm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $this->assertEquals(0, $cm->visible);
    }

    /**
     * The quiz must appear in the course section specified.
     */
    public function test_create_quiz_in_section(): void {
        global $DB;

        // Create an extra section so section 1 exists.
        $this->getDataGenerator()->create_course_section(['course' => $this->course->id, 'section' => 1]);

        $result = create_quiz::execute(
            courseid:   $this->course->id,
            name:       'Section Quiz',
            sectionnum: 1,
        );

        $cm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $section = $DB->get_record(
            'course_sections',
            ['id' => $cm->section, 'course' => $this->course->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(1, $section->section);
    }

    /**
     * Passing timeclose earlier than timeopen must throw an invalid_parameter_exception.
     */
    public function test_invalid_timeclose_before_timeopen(): void {
        $this->expectException(\invalid_parameter_exception::class);

        create_quiz::execute(
            courseid:  $this->course->id,
            name:      'Bad Times Quiz',
            timeopen:  time() + 3600,
            timeclose: time() + 100,
        );
    }

    /**
     * A student without the required capability must not be able to create a quiz.
     */
    public function test_create_quiz_requires_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        create_quiz::execute($this->course->id, 'Student Should Fail');
    }

    /**
     * Passing a non-existent course ID must throw a dml_missing_record_exception.
     */
    public function test_create_quiz_invalid_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        create_quiz::execute(999999, 'No Course Quiz');
    }

    /**
     * Multiple quizzes can be created in the same course independently.
     */
    public function test_create_multiple_quizzes(): void {
        $result1 = create_quiz::execute($this->course->id, 'Quiz Alpha');
        $result2 = create_quiz::execute($this->course->id, 'Quiz Beta');

        $this->assertNotEquals($result1['cmid'], $result2['cmid']);
        $this->assertNotEquals($result1['quizid'], $result2['quizid']);
    }

    /**
     * Passing a useremail restricts the quiz to that user's profile email and
     * hides it from everyone else.
     */
    public function test_create_quiz_restricted_to_user_email(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Jo',
            'lastname'  => 'Student',
            'email'     => 'jo.student@example.com',
        ]);

        $result = create_quiz::create(
            $this->course->id,
            'Assignment One',
            ['useremail' => $student->email]
        );

        $cm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $this->assertNotEmpty($cm->availability, 'availability JSON should be set');

        $tree = json_decode($cm->availability, true);
        $this->assertSame('&', $tree['op']);
        $this->assertSame([false], $tree['showc'], 'restriction should be hidden from others');

        $condition = $tree['c'][0];
        $this->assertSame('profile', $condition['type']);
        $this->assertSame('email', $condition['sf']);
        $this->assertSame('isequalto', $condition['op']);
        $this->assertSame($student->email, $condition['v']);

        // Name is prefixed once with the student's name.
        $this->assertSame('jo-student_Assignment One', $result['name']);
    }

    /**
     * Without a useremail the quiz has no availability restriction.
     */
    public function test_create_quiz_no_email_no_restriction(): void {
        global $DB;

        $result = create_quiz::create($this->course->id, 'Open Quiz');

        $cm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $this->assertEmpty($cm->availability, 'availability should be unset when no useremail given');
    }

    /**
     * The adhoc task created from a submission restricts the quiz to the
     * submitting student's email.
     */
    public function test_adhoc_task_restricts_to_submitter(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Sam',
            'lastname'  => 'Jones',
            'email'     => 'sam.jones@example.com',
        ]);

        $task = new \local_jomot\task\create_quiz_adhoc();
        $task->set_custom_data([
            'courseid'       => $this->course->id,
            'userid'         => $student->id,
            'assignmentname' => 'Essay',
            'submissiontext' => '',
            'numquestions'   => 1,
            'templatequiz'   => 0,
        ]);

        // The task calls mtrace(); capture it so the test is not flagged risky.
        ob_start();
        $task->execute();
        ob_end_clean();

        $quiz = $DB->get_record('quiz', ['name' => 'sam-jones_Essay'], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST);

        $tree = json_decode($cm->availability, true);
        $this->assertSame('email', $tree['c'][0]['sf']);
        $this->assertSame($student->email, $tree['c'][0]['v']);
    }

    /**
     * Full path: a real assignment submission fires the observer, which queues the
     * adhoc task, which creates a quiz locked to the submitting student's email.
     *
     * This exercises the whole chain (event -> observer -> adhoc task -> create_quiz)
     * rather than calling create_quiz::create() directly, proving the generated quiz
     * is access-restricted to exactly the student who submitted.
     */
    public function test_submission_creates_quiz_restricted_to_submitter_email(): void {
        global $DB;

        $generator = $this->getDataGenerator();

        // Student who will submit the assignment.
        $student = $generator->create_user([
            'firstname' => 'Pat',
            'lastname'  => 'Lee',
            'email'     => 'pat.lee@example.com',
        ]);
        $generator->enrol_user($student->id, $this->course->id, 'student');

        // Assignment configured for Just One More Thing quiz generation.
        $assign = $generator->create_module('assign', [
            'course'                    => $this->course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        // Module creation already seeds a config row via the edit_post_actions hook;
        // update it to enable quiz generation for this assignment.
        $config = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $assign->id]);
        if ($config) {
            $config->enable_quiz = 1;
            $config->numquestions = 1;
            $config->templatequiz = 0;
            $config->quizvisible = 1;
            $config->timemodified = time();
            $DB->update_record('local_jomot_assign_config', $config);
        } else {
            $DB->insert_record('local_jomot_assign_config', (object) [
                'assignmentid' => $assign->id,
                'enable_quiz'  => 1,
                'numquestions' => 1,
                'quizvisible'  => 0,
                'templatequiz' => 0,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        $cm      = get_coursemodule_from_instance('assign', $assign->id, $this->course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Record a submission, as the real submission flow would. Online text is left
        // empty so no AI request is made; the access restriction does not depend on it.
        $submission = (object) [
            'assignment'   => $assign->id,
            'userid'       => $student->id,
            'timecreated'  => time(),
            'timemodified' => time(),
            'status'       => 'submitted',
            'groupid'      => 0,
            'attemptnumber' => 0,
            'latest'       => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        // Fire the event the observer listens for; this queues the adhoc task.
        $event = \mod_assign\event\assessable_submitted::create([
            'context'  => $context,
            'objectid' => $submission->id,
            'userid'   => $student->id,
            'other'    => ['submission_editable' => false],
        ]);
        // Other assign event observers expect the assign instance to be set.
        $event->set_assign(new \assign($context, $cm, $this->course));
        $event->trigger();

        // Run the queued adhoc task, which creates the quiz.
        ob_start();
        $this->runAdhocTasks(\local_jomot\task\create_quiz_adhoc::class);
        ob_end_clean();

        // The quiz is named after the student and locked to their email.
        $quiz = $DB->get_record('quiz', ['name' => 'pat-lee_' . $cm->name], '*', MUST_EXIST);
        $quizcm = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST);

        $this->assertNotEmpty($quizcm->availability, 'generated quiz must carry an access restriction');

        $tree = json_decode($quizcm->availability, true);
        $this->assertSame('&', $tree['op']);
        $this->assertSame([false], $tree['showc'], 'restriction must be hidden from other users');

        $condition = $tree['c'][0];
        $this->assertSame('profile', $condition['type']);
        $this->assertSame('email', $condition['sf']);
        $this->assertSame('isequalto', $condition['op']);
        $this->assertSame($student->email, $condition['v'], 'quiz must be locked to the submitting student email');

        // quizvisible = 1 in config must make the generated quiz visible.
        $this->assertEquals(1, $quizcm->visible, 'quiz must be visible when quizvisible is enabled');
    }

    /**
     * With quizvisible disabled in the assignment config, the generated quiz is hidden.
     */
    public function test_adhoc_task_respects_quizvisible_flag(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Kim',
            'lastname'  => 'Roe',
            'email'     => 'kim.roe@example.com',
        ]);

        // Visible quiz when quizvisible = 1.
        $task = new \local_jomot\task\create_quiz_adhoc();
        $task->set_custom_data([
            'courseid'       => $this->course->id,
            'userid'         => $student->id,
            'assignmentname' => 'Visible',
            'submissiontext' => '',
            'numquestions'   => 1,
            'templatequiz'   => 0,
            'quizvisible'    => 1,
        ]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $quiz = $DB->get_record('quiz', ['name' => 'kim-roe_Visible'], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST);
        $this->assertEquals(1, $cm->visible, 'quizvisible=1 must create a visible quiz');

        // Hidden quiz when quizvisible = 0.
        $task = new \local_jomot\task\create_quiz_adhoc();
        $task->set_custom_data([
            'courseid'       => $this->course->id,
            'userid'         => $student->id,
            'assignmentname' => 'Hidden',
            'submissiontext' => '',
            'numquestions'   => 1,
            'templatequiz'   => 0,
            'quizvisible'    => 0,
        ]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $quiz = $DB->get_record('quiz', ['name' => 'kim-roe_Hidden'], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST);
        $this->assertEquals(0, $cm->visible, 'quizvisible=0 must create a hidden quiz');
    }

    /**
     * The quiz must have a first section row created in quiz_sections.
     */
    public function test_quiz_section_created(): void {
        global $DB;

        $result = create_quiz::execute($this->course->id, 'Section Test Quiz');

        $section = $DB->get_record('quiz_sections', ['quizid' => $result['quizid']]);
        $this->assertNotFalse($section, 'quiz_sections row should be created');
        $this->assertEquals(1, $section->firstslot);
    }
}
