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
 * Event fired when Just One More Thing creates a quiz.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\event;

/**
 * Fired after local_jomot successfully creates a quiz in a course.
 */
class quiz_created extends \core\event\base {

    protected function init() {
        $this->data['objecttable'] = 'quiz';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    public static function get_name() {
        return get_string('event_quiz_created', 'local_jomot');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' created quiz with id '{$this->objectid}'" .
               " (cmid {$this->other['cmid']}) named '{$this->other['name']}'" .
               " in course '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/quiz/view.php', ['id' => $this->other['cmid']]);
    }

    protected function validate_data() {
        parent::validate_data();
        if (empty($this->other['cmid'])) {
            throw new \coding_exception('cmid must be set in other.');
        }
        if (!isset($this->other['name'])) {
            throw new \coding_exception('name must be set in other.');
        }
    }
}
