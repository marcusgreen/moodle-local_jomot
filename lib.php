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
 * Library functions.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook to add elements to the assignment settings form.
 *
 * @param \moodleform_mod $formwrapper
 * @param \MoodleQuickForm $mform
 */
function local_jomot_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    if ($formwrapper->get_current()->modulename !== 'assign') {
        return;
    }

    $mform->addElement('header', 'local_jomot_header', get_string('pluginname', 'local_jomot'));

    $mform->addElement('advcheckbox', 'local_jomot_enable_quiz',
        get_string('enable_quiz_label', 'local_jomot'),
        get_string('enable_quiz_desc', 'local_jomot'),
        ['group' => 1], [0, 1]);

    $mform->addHelpButton('local_jomot_enable_quiz', 'enable_quiz', 'local_jomot');

    $mform->addElement('text', 'local_jomot_numquestions',
        get_string('numquestions_label', 'local_jomot'), ['size' => 4]);
    $mform->setType('local_jomot_numquestions', PARAM_INT);
    $mform->setDefault('local_jomot_numquestions', \local_jomot\constants::DEFAULT_NUMQUESTIONS);
    $mform->hideIf('local_jomot_numquestions', 'local_jomot_enable_quiz', 'eq', 0);

    // Override defaults with saved values when editing an existing assignment.
    $update = optional_param('update', 0, PARAM_INT);
    if ($update) {
        $cm = get_coursemodule_from_id('assign', $update);
        if ($cm) {
            $config = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $cm->instance]);
            if ($config) {
                $mform->setDefault('local_jomot_enable_quiz', (int)$config->enable_quiz);
                $mform->setDefault('local_jomot_numquestions', max(1, (int)$config->numquestions ?: \local_jomot\constants::DEFAULT_NUMQUESTIONS));
            }
        }
    }
}

/**
 * Hook to save Just One More Thing settings after the assignment form is submitted.
 *
 * @param \stdClass $data
 * @param \stdClass $course
 * @return \stdClass
 */
function local_jomot_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    if ($data->modulename !== 'assign' || empty($data->instance)) {
        return $data;
    }

    $enablequiz   = isset($data->local_jomot_enable_quiz) ? (int)$data->local_jomot_enable_quiz : 0;
    $numquestions = min(
        \local_jomot\constants::MAX_NUMQUESTIONS,
        max(1, isset($data->local_jomot_numquestions) ? (int)$data->local_jomot_numquestions : \local_jomot\constants::DEFAULT_NUMQUESTIONS)
    );

    $existing = $DB->get_record('local_jomot_assign_config', ['assignmentid' => $data->instance]);

    if ($existing) {
        $existing->enable_quiz   = $enablequiz;
        $existing->numquestions = $numquestions;
        $existing->timemodified  = time();
        $DB->update_record('local_jomot_assign_config', $existing);
    } else {
        $DB->insert_record('local_jomot_assign_config', (object)[
            'assignmentid' => $data->instance,
            'enable_quiz'  => $enablequiz,
            'numquestions' => $numquestions,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    return $data;
}
