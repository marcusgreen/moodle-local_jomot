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
 * Web service function definitions for local_jomot.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_jomot_create_quiz' => [
        'classname'    => 'local_jomot\external\create_quiz',
        'methodname'   => 'execute',
        'description'  => 'Create a new quiz module instance in a course',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/jomot:createquiz',
    ],
    'local_jomot_add_question' => [
        'classname'    => 'local_jomot\external\add_question',
        'methodname'   => 'execute',
        'description'  => 'Create a new aitext question and add it to an existing quiz',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/jomot:createquiz',
    ],
];
