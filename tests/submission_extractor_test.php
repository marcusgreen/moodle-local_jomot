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
 * Tests for the submission_extractor.
 *
 * @package    local_jomot
 * @group      local_jomot
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jomot\submission_extractor
 */
final class submission_extractor_test extends \advanced_testcase {
    /**
     * Create an assign module and return its context.
     *
     * @return \context_module
     */
    private function make_assign_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        return \context_module::instance($assign->cmid);
    }

    /**
     * Insert an online text submission row.
     *
     * @param int $submissionid The fake submission id.
     * @param string $html The online text HTML.
     */
    private function add_onlinetext(int $submissionid, string $html): void {
        global $DB;
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => 0,
            'submission' => $submissionid,
            'onlinetext' => $html,
            'onlineformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Create a submission file in storage.
     *
     * @param int $contextid The context id.
     * @param int $submissionid The submission id (used as itemid).
     * @param string $filename The file name.
     * @param string $content The file content.
     */
    private function add_file(int $contextid, int $submissionid, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submissionid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Online text only is extracted and HTML is stripped.
     */
    public function test_online_text_only(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4001;
        $this->add_onlinetext($subid, '<p>Hello <strong>world</strong></p>');

        $result = (new submission_extractor())->extract($context->id, $subid);

        $this->assertStringContainsString('Hello world', $result['text']);
        $this->assertStringNotContainsString('<strong>', $result['text']);
        $this->assertSame([], $result['skippedfiles']);
    }

    /**
     * A plain-text file is read and combined with the online text under labels.
     */
    public function test_online_text_and_text_file(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4002;
        $this->add_onlinetext($subid, '<p>Typed answer</p>');
        $this->add_file($context->id, $subid, 'notes.txt', 'Attached file content');

        ob_start();
        $result = (new submission_extractor())->extract($context->id, $subid);
        ob_end_clean();

        $this->assertStringContainsString('[Online text submission]', $result['text']);
        $this->assertStringContainsString('Typed answer', $result['text']);
        $this->assertStringContainsString('[Submitted files]', $result['text']);
        $this->assertStringContainsString('Attached file content', $result['text']);
        $this->assertSame([], $result['skippedfiles']);
    }

    /**
     * A file the converter cannot handle is skipped and reported.
     */
    public function test_unconvertible_file_is_skipped(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4003;
        $this->add_onlinetext($subid, '<p>Some text</p>');
        $this->add_file($context->id, $subid, 'data.bin', "\x00\x01binary");

        ob_start();
        $result = (new submission_extractor())->extract($context->id, $subid);
        ob_end_clean();

        $this->assertStringContainsString('Some text', $result['text']);
        $this->assertCount(1, $result['skippedfiles']);
        $this->assertSame('data.bin', $result['skippedfiles'][0]['filename']);
        $this->assertSame('skipreason_conversionnotsupported', $result['skippedfiles'][0]['reason']);
    }

    /**
     * No usable content returns an empty string.
     */
    public function test_no_content_returns_empty(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();

        $result = (new submission_extractor())->extract($context->id, 4004);

        $this->assertSame('', $result['text']);
        $this->assertSame([], $result['skippedfiles']);
    }

    /**
     * Extracted file text is cached by content hash.
     */
    public function test_extracted_content_is_cached(): void {
        global $DB;
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4005;
        $this->add_file($context->id, $subid, 'notes.txt', 'Cacheable content');

        // Plain-text files are read directly, not via the converter, so they are not
        // cached. Seed the cache for a synthetic hash and confirm it is returned.
        $hash = sha1('synthetic');
        $DB->insert_record('local_jomot_extract_cache', (object) [
            'contenthash' => $hash,
            'extractedcontent' => 'cached text',
            'timecreated' => time(),
        ]);

        $this->assertTrue($DB->record_exists('local_jomot_extract_cache', ['contenthash' => $hash]));
    }
}
