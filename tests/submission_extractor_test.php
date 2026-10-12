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

        $result = (new submission_extractor())->extract($context->id, $subid, 2);

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
        $result = (new submission_extractor())->extract($context->id, $subid, 2);
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
        $result = (new submission_extractor())->extract($context->id, $subid, 2);
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

        $result = (new submission_extractor())->extract($context->id, 4004, 2);

        $this->assertSame('', $result['text']);
        $this->assertSame([], $result['skippedfiles']);
    }

    /**
     * Mock local_ai_content's extractor so a file is supported and extraction behaves as given.
     *
     * @param callable|string $behaviour Text to return, or a callable run in place of extraction.
     */
    private function mock_extractor(callable|string $behaviour): void {
        $extractor = $this->createMock(\local_ai_content\document_extractor::class);
        $extractor->method('is_file_supported')->willReturn(true);
        if (is_callable($behaviour)) {
            $extractor->method('extract_text_from_file')->willReturnCallback($behaviour);
        } else {
            $extractor->method('extract_text_from_file')->willReturn($behaviour);
        }
        \core\di::set(\local_ai_content\document_extractor::class, $extractor);
    }

    /**
     * Extraction is attributed to the given user and to local_jomot.
     */
    public function test_extraction_passes_user_and_component(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4005;
        $this->add_file($context->id, $subid, 'essay.pdf', '%PDF-1.4 fake');

        $calls = [];
        $this->mock_extractor(function ($file, $contextid, $userid, $component) use (&$calls) {
            $calls[] = [$contextid, $userid, $component];
            return 'PDF text';
        });

        ob_start();
        $result = (new submission_extractor())->extract($context->id, $subid, 42);
        ob_end_clean();

        $this->assertSame([[$context->id, 42, 'local_jomot']], $calls);
        $this->assertStringContainsString('PDF text', $result['text']);
        $this->assertSame([], $result['skippedfiles']);
    }

    /**
     * A file that yields no text is skipped with the no-content reason.
     */
    public function test_empty_extraction_is_skipped(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4006;
        $this->add_file($context->id, $subid, 'blank.pdf', '%PDF-1.4 fake');
        $this->mock_extractor('');

        ob_start();
        $result = (new submission_extractor())->extract($context->id, $subid, 2);
        ob_end_clean();

        $this->assertSame('', $result['text']);
        $this->assertCount(1, $result['skippedfiles']);
        $this->assertSame('skipreason_nocontent', $result['skippedfiles'][0]['reason']);
    }

    /**
     * A file whose extraction throws is skipped and the remaining content is kept.
     */
    public function test_failed_extraction_is_skipped(): void {
        $this->resetAfterTest();
        $context = $this->make_assign_context();
        $subid = 4007;
        $this->add_onlinetext($subid, '<p>Typed answer</p>');
        $this->add_file($context->id, $subid, 'scan.png', 'fakepng');
        $this->mock_extractor(function () {
            throw new \moodle_exception('error_airequestfailed', 'local_ai_content');
        });

        ob_start();
        $result = (new submission_extractor())->extract($context->id, $subid, 2);
        ob_end_clean();

        $this->assertStringContainsString('Typed answer', $result['text']);
        $this->assertCount(1, $result['skippedfiles']);
        $this->assertSame('skipreason_extractionfailed', $result['skippedfiles'][0]['reason']);
    }
}
