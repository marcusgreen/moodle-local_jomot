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

namespace local_jomot;

/**
 * Tests for local_jomot.
 *
 * @package    local_jomot
 * @group      local_jomot
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class local_jomot_test extends \advanced_testcase {
    /**
     * Test that the plugin is installed and recognised by Moodle.
     *
     * @covers \core_plugin_manager
     */
    public function test_plugin_is_installed(): void {
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_jomot');
        $this->assertNotNull($plugininfo, 'local_jomot plugin info should not be null');
        $this->assertEquals('local_jomot', $plugininfo->component);
        $this->assertEquals('local', $plugininfo->type);
        $this->assertEquals('jomot', $plugininfo->name);
    }

    /**
     * Test that the pluginname language string is defined.
     *
     * @covers ::get_string
     */
    public function test_pluginname_string_exists(): void {
        $pluginname = get_string('pluginname', 'local_jomot');
        $this->assertEquals('Just One More Thing', $pluginname);
    }

    /**
     * Test that the plugin version is set correctly.
     *
     * @covers \core_plugin_manager
     */
    public function test_plugin_version(): void {
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_jomot');
        $this->assertNotNull($plugininfo);
        $this->assertGreaterThanOrEqual(
            2026043000,
            $plugininfo->versiondb,
            'Installed version should meet or exceed the declared version'
        );
    }

    /**
     * Test that the plugin page URL is valid.
     *
     * @covers \moodle_url
     */
    public function test_plugin_page_url(): void {
        $url = new \moodle_url('/local/jomot/index.php');
        $this->assertStringContainsString('/local/jomot/index.php', $url->out(false));
    }

    /**
     * Test that index.php exists as a file.
     *
     * @coversNothing
     */
    public function test_index_php_exists(): void {
        global $CFG;
        $this->assertFileExists($CFG->dirroot . '/local/jomot/index.php');
    }

    /**
     * Test that the plugin root directory and key files exist on disk.
     *
     * @coversNothing
     */
    public function test_plugin_files_exist(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/jomot';
        $this->assertDirectoryExists($root);
        $this->assertFileExists($root . '/version.php');
        $this->assertFileExists($root . '/lib.php');
        $this->assertFileExists($root . '/lang/en/local_jomot.php');
    }

    /**
     * Test that an authenticated user can be created and logged in.
     *
     * @coversNothing
     */
    public function test_authenticated_user_can_login(): void {
        global $USER;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertEquals($user->id, $USER->id);
    }
}
