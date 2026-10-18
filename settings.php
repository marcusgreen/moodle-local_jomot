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

    $settings->add(new admin_setting_configtextarea(
        'local_jomot/default_ai_prompt',
        get_string('default_ai_prompt', 'local_jomot'),
        get_string('default_ai_prompt_desc', 'local_jomot'),
        \local_jomot\external\create_quiz::DEFAULT_AI_PROMPT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_jomot/json_prompt',
        get_string('json_prompt', 'local_jomot'),
        get_string('json_prompt_desc', 'local_jomot'),
        \local_jomot\external\create_quiz::DEFAULT_JSON_PROMPT
    ));

    // The core AI subsystem is always available; local_ai_manager only when installed.
    $backends = ['core_ai_subsystem' => get_string('backend_coreai', 'local_jomot')];
    if (core_plugin_manager::instance()->get_plugin_info('local_ai_manager')) {
        $backends['local_ai_manager'] = get_string('backend_localaimanager', 'local_jomot');
    }
    $settings->add(new admin_setting_configselect(
        'local_jomot/backend',
        get_string('backend', 'local_jomot'),
        get_string('backend_desc', 'local_jomot'),
        'core_ai_subsystem',
        $backends
    ));

    $settings->add(new admin_setting_configtext(
        'local_jomot/templatetag',
        get_string('templatetag', 'local_jomot'),
        get_string('templatetag_desc', 'local_jomot'),
        '',
        PARAM_TEXT
    ));
}
