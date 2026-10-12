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
 * Reads the online text editor content and any attached files. File text is
 * extracted by local_ai_content, which handles plain text, office documents
 * (via the site document converter) and, when its AI backend supports
 * image-to-text, PDFs and images. Extraction results are cached there.
 */
class submission_extractor {
    /**
     * Extract submission content as plain text.
     *
     * @param int $contextid The assignment (module) context id.
     * @param int $submissionid The assign submission id (assign_submission.id).
     * @param int $userid The user AI extraction requests are attributed to (quota, audit).
     * @return array{text: string, skippedfiles: list<array{filename: string, reason: string}>}
     */
    public function extract(int $contextid, int $submissionid, int $userid): array {
        global $DB;

        $onlinetext = '';
        $record = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($record && !empty($record->onlinetext)) {
            $onlinetext = trim(strip_tags($record->onlinetext));
        }

        $fileresult = $this->extract_content_from_files($contextid, $submissionid, $userid);

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
     * Delegates per-file extraction to local_ai_content. Files it does not
     * support, files with no text and files whose extraction fails are skipped
     * and reported with a reason.
     *
     * @param int $contextid The assignment (module) context id.
     * @param int $submissionid The assign submission id.
     * @param int $userid The user AI extraction requests are attributed to.
     * @return array{text: string, skippedfiles: list<array{filename: string, reason: string}>}
     */
    private function extract_content_from_files(int $contextid, int $submissionid, int $userid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'itemid, filepath, filename',
            false
        );

        $extractor = \core\di::get(\local_ai_content\document_extractor::class);
        $alltext = '';
        $skippedfiles = [];

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            $filename = $file->get_filename();

            if (!$extractor->is_file_supported($file)) {
                mtrace("local_jomot: file '{$filename}' ({$file->get_mimetype()}) is not supported - skipping.");
                $skippedfiles[] = ['filename' => $filename, 'reason' => 'skipreason_conversionnotsupported'];
                continue;
            }

            try {
                $text = $extractor->extract_text_from_file($file, $contextid, $userid, 'local_jomot');
            } catch (\Exception $e) {
                mtrace("local_jomot: failed to extract text from '{$filename}': " . $e->getMessage());
                $skippedfiles[] = ['filename' => $filename, 'reason' => 'skipreason_extractionfailed'];
                continue;
            }

            if (trim($text) === '') {
                mtrace("local_jomot: file '{$filename}' contains no text - skipping.");
                $skippedfiles[] = ['filename' => $filename, 'reason' => 'skipreason_nocontent'];
                continue;
            }

            $alltext .= $text . "\n";
            mtrace("local_jomot: text extracted from '{$filename}'.");
        }

        return ['text' => $alltext, 'skippedfiles' => $skippedfiles];
    }
}
