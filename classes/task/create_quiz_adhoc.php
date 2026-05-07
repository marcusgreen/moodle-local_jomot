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
 * Adhoc task: create a quiz named after a submitting student.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\task;

/**
 * Creates a quiz in a course named after the student who triggered the task.
 *
 * Custom data keys:
 *   - courseid (int): the course to create the quiz in
 *   - userid   (int): the student whose full name becomes the quiz name
 */
class create_quiz_adhoc extends \core\task\adhoc_task {

    /**
     * @return string Human-readable task name shown in the admin UI.
     */
    public function get_name(): string {
        return get_string('task_create_quiz', 'local_jomot');
    }

    /**
     * Creates the quiz. Runs as the admin user under cron.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();

        $user = $DB->get_record('user', ['id' => $data->userid], '*', MUST_EXIST);
        $name = strtolower($user->firstname) . '-' . strtolower($user->lastname) . '_' . $data->assignmentname;

        mtrace("local_jomot: creating quiz '$name' in course {$data->courseid}");

        \local_jomot\external\create_quiz::create(
            (int) $data->courseid,
            $name,
            ['questionsperpage' => \local_jomot\constants::DEFAULT_QUESTIONSPERPAGE],
            $data->submissiontext ?? '',
            (int) ($data->numquestions ?? \local_jomot\constants::DEFAULT_NUMQUESTIONS)
        );

        mtrace("local_jomot: quiz created successfully");
    }
}
