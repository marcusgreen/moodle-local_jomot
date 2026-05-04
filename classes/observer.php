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
 * Event observer.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot;

/**
 * Listens for assignment events and queues Just One More Thing adhoc tasks.
 */
class observer {

    /**
     * Fired when a student finalises an assignment submission.
     *
     * Checks whether the assignment has Just One More Thing quiz generation enabled and,
     * if so, queues an adhoc task to create the quiz (the task runs under cron
     * as admin, avoiding the capability problem of acting as the student).
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function on_submission(\mod_assign\event\assessable_submitted $event): void {
        global $DB;

        $cm = get_coursemodule_from_id('assign', $event->contextinstanceid);
        if (!$cm) {
            return;
        }

        $config = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $cm->instance]);
        if (!$config || !$config->enable_quiz) {
            return;
        }

        $submissiontext = '';
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $event->objectid]);
        if ($onlinetext && !empty($onlinetext->onlinetext)) {
            $submissiontext = trim(strip_tags($onlinetext->onlinetext));
        }

        $task = new \local_jomot\task\create_quiz_adhoc();
        $task->set_custom_data([
            'courseid'       => (int) $cm->course,
            'userid'         => (int) $event->userid,
            'assignmentname' => $cm->name,
            'submissiontext' => $submissiontext,
            'numquestions'   => max(1, (int) $config->numquestions ?: constants::DEFAULT_NUMQUESTIONS),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
