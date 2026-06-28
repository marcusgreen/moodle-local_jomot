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
 * Extracts text from an assignment submission's online text and files.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot;

/**
 * Builds a single block of plain text from everything a student submitted.
 *
 * Reads the online text editor content and any attached files, converting
 * office documents (DOCX, ODT, etc.) to text via the site document converter.
 * PDF and image submissions are not supported and are reported as skipped.
 */
class submission_extractor {
    /**
     * Extract submission content as plain text.
     *
     * @param int $contextid The assignment (module) context id.
     * @param int $submissionid The assign submission id (assign_submission.id).
     * @return array{text: string, skippedfiles: list<array{filename: string, reason: string}>}
     */
    public function extract(int $contextid, int $submissionid): array {
        global $DB;

        $onlinetext = '';
        $record = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($record && !empty($record->onlinetext)) {
            $onlinetext = trim(strip_tags($record->onlinetext));
        }

        $fileresult = $this->extract_content_from_files($contextid, $submissionid);

        return [
            'text' => $this->build_structured_submission($onlinetext, $fileresult['text'], $fileresult['skippedfiles']),
            'skippedfiles' => $fileresult['skippedfiles'],
        ];
    }

    /**
     * Combine online text and file text into one block, labelled when both exist.
     *
     * @param string $onlinetext Online text submission (plain).
     * @param string $filetext Extracted text from files (plain).
     * @param list<array{filename: string, reason: string}> $skippedfiles Files that could not be read.
     * @return string The combined submission text.
     */
    private function build_structured_submission(string $onlinetext, string $filetext, array $skippedfiles): string {
        $hasonline = trim($onlinetext) !== '';
        $hasfiles = trim($filetext) !== '';

        $skippednote = '';
        if (!empty($skippedfiles)) {
            $skippednames = array_column($skippedfiles, 'filename');
            $skippednote = "\n\n[Note: The following files could not be analysed and are not included: "
                . implode(', ', $skippednames) . "]";
        }

        if ($hasonline && $hasfiles) {
            return "[Online text submission]\n" . $onlinetext
                . "\n\n[Submitted files]\n" . $filetext . $skippednote;
        }

        if ($hasonline) {
            return $onlinetext;
        }

        if ($hasfiles) {
            return $filetext . $skippednote;
        }

        return '';
    }

    /**
     * Extract text from all files attached to a submission.
     *
     * Plain text files are read directly; other document types are converted to
     * text via the site document converter. PDF and image files are not supported.
     *
     * @param int $contextid The assignment (module) context id.
     * @param int $submissionid The assign submission id.
     * @return array{text: string, skippedfiles: list<array{filename: string, reason: string}>}
     */
    private function extract_content_from_files(int $contextid, int $submissionid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'itemid, filepath, filename',
            false
        );

        $alltext = '';
        $skippedfiles = [];

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $mimetype = $file->get_mimetype();
            $filename = $file->get_filename();

            // Plain text files: read directly.
            if ($mimetype === 'text/plain') {
                $tempfile = $file->copy_content_to_temp();
                $alltext .= file_get_contents($tempfile) . "\n";
                unlink($tempfile);
                mtrace("local_jomot: text content from '{$filename}' added.");
                continue;
            }

            // Other document types: convert to text via the site document converter.
            $extractedtext = $this->extract_content_via_converter($file);
            if ($extractedtext !== '') {
                $alltext .= $extractedtext . "\n";
                mtrace("local_jomot: text extracted from '{$filename}' via converter.");
                continue;
            }

            mtrace("local_jomot: file '{$filename}' ({$mimetype}) could not be converted - skipping.");
            $skippedfiles[] = [
                'filename' => $filename,
                'reason' => 'skipreason_conversionnotsupported',
            ];
        }

        return ['text' => $alltext, 'skippedfiles' => $skippedfiles];
    }

    /**
     * Convert a file to plain text using the site document converter.
     *
     * Results are cached by content hash so an unchanged file resubmitted later
     * is not converted again.
     *
     * @param \stored_file $file The file to convert.
     * @return string The extracted text, or empty string if conversion is unsupported or fails.
     */
    private function extract_content_via_converter(\stored_file $file): string {
        $contenthash = $file->get_contenthash();
        $cached = $this->get_from_cache($contenthash);
        if ($cached !== null) {
            mtrace("local_jomot: using cached content for '{$file->get_filename()}'.");
            return $cached;
        }

        $converter = new \core_files\converter();
        if (!$converter->can_convert_storedfile_to($file, 'txt')) {
            return '';
        }

        $conversion = $converter->start_conversion($file, 'txt');
        if ($conversion->get('status') !== \core_files\conversion::STATUS_COMPLETE) {
            return '';
        }

        $convertedfile = $conversion->get_destfile();
        if (!$convertedfile) {
            return '';
        }

        $tempfile = $convertedfile->copy_content_to_temp();
        $text = (string) file_get_contents($tempfile);
        unlink($tempfile);

        $this->store_to_cache($contenthash, $text);

        return $text;
    }

    /**
     * Get cached extracted content for a file by its content hash.
     *
     * @param string $contenthash The SHA1 content hash of the file.
     * @return string|null The cached content, or null if not cached.
     */
    private function get_from_cache(string $contenthash): ?string {
        global $DB;

        $record = $DB->get_record('local_jomot_extract_cache', ['contenthash' => $contenthash]);
        return $record ? $record->extractedcontent : null;
    }

    /**
     * Store extracted content in the cache, keyed by content hash.
     *
     * @param string $contenthash The SHA1 content hash of the file.
     * @param string $extractedcontent The extracted text content.
     */
    private function store_to_cache(string $contenthash, string $extractedcontent): void {
        global $DB;

        if ($DB->record_exists('local_jomot_extract_cache', ['contenthash' => $contenthash])) {
            return;
        }

        $record = new \stdClass();
        $record->contenthash = $contenthash;
        $record->extractedcontent = $extractedcontent;
        $record->timecreated = time();
        $DB->insert_record('local_jomot_extract_cache', $record);
    }
}
