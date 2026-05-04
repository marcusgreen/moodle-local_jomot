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
 * Admin settings.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_jomot', get_string('pluginname', 'local_jomot'));
    $ADMIN->add('localplugins', $settings);

    $defaultprompt = 'The following is a student\'s assignment submission. '
        . 'Generate {numquestions} questions that test whether the student genuinely understands what they wrote. '
        . 'Each question should ask the student to explain, elaborate on, or demonstrate understanding of a specific '
        . 'concept, claim, or argument from their submission.' . "\n\n"
        . 'Respond with a JSON array of {numquestions} objects. Each object must have exactly two keys: '
        . '"questiontext" (the question to show the student, referencing specific parts of their submission) and '
        . '"aiprompt" (grading instructions for the AI evaluator describing what a correct answer should demonstrate, '
        . 'grounded in what the submission actually said).';

    $settings->add(new admin_setting_configtextarea(
        'local_jomot/default_ai_prompt',
        get_string('default_ai_prompt', 'local_jomot'),
        get_string('default_ai_prompt_desc', 'local_jomot'),
        $defaultprompt
    ));
}
