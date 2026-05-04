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
