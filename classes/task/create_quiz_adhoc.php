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
 * Adhoc task: create a quiz named after a submitting student.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jomot\task;

/**
 * Creates a quiz in a course named after the student who triggered the task.
 *
 * Custom data keys:
 *   - courseid (int): the course to create the quiz in
 *   - userid   (int): the student whose full name becomes the quiz name
 *   - contextid (int): the assignment module context; the quiz is placed directly after it
 */
class create_quiz_adhoc extends \core\task\adhoc_task {
    /**
     * Get the human-readable task name shown in the admin UI.
     *
     * @return string Human-readable task name shown in the admin UI.
     */
    public function get_name(): string {
        return get_string('task_create_quiz', 'local_jomot');
    }

    /**
     * Creates the quiz. Runs as the admin user under cron.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();

        $user = $DB->get_record('user', ['id' => $data->userid], '*', MUST_EXIST);

        mtrace("local_jomot: creating quiz for '{$user->email}' in course {$data->courseid}");

        // Extract submission content (online text plus supported files) now, under
        // cron, where slow document conversion is acceptable.
        $extractor = new \local_jomot\submission_extractor();
        $result = $extractor->extract((int) $data->contextid, (int) $data->submissionid, (int) $user->id);
        foreach ($result['skippedfiles'] as $skipped) {
            mtrace("local_jomot: WARNING file '{$skipped['filename']}' excluded ({$skipped['reason']}).");
        }

        $overrides = self::placement_after_assignment((int) $data->courseid, (int) $data->contextid) + [
            'questionsperpage' => \local_jomot\constants::DEFAULT_QUESTIONSPERPAGE,
            'templatequiz'     => (int) ($data->templatequiz ?? 0),
            'useremail'        => $user->email,
            'visible'          => (int) ($data->quizvisible ?? 0),
            'prompt'           => (string) ($data->prompt ?? ''),
            'questionmark'     => (float) ($data->questionmark ?? 1),
        ];
        // Only override the grade when the teacher set one; otherwise the template
        // quiz grade (or the plugin default) applies.
        if (isset($data->quizgrade)) {
            $overrides['grade'] = (float) $data->quizgrade;
        }

        \local_jomot\external\create_quiz::create(
            (int) $data->courseid,
            $data->assignmentname,
            $overrides,
            $result['text'],
            (int) ($data->numquestions ?? \local_jomot\constants::DEFAULT_NUMQUESTIONS)
        );

        mtrace("local_jomot: quiz created successfully");
    }

    /**
     * Works out where to put the quiz so it sits directly after its assignment,
     * in the same section (or subsection), as a duplicated activity would.
     *
     * Resolved at run time rather than when the task is queued, so the quiz
     * follows the assignment if a teacher moved it in the meantime. Returns no
     * overrides, leaving the quiz in the General section, when the assignment
     * can no longer be found.
     *
     * @param int $courseid
     * @param int $contextid The assignment's module context id.
     * @return array{sectionnum?: int, beforemod?: int|null}
     */
    private static function placement_after_assignment(int $courseid, int $contextid): array {
        $context = $contextid ? \context::instance_by_id($contextid, IGNORE_MISSING) : false;
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return [];
        }

        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->cms[$context->instanceid] ?? null;
        if (!$cm || $cm->deletioninprogress) {
            return [];
        }

        // Insert before whatever follows the assignment; null appends to the section.
        $sequence = array_values($modinfo->sections[$cm->sectionnum] ?? []);
        $position = array_search($cm->id, $sequence);
        $next = $position === false ? null : ($sequence[$position + 1] ?? null);

        return [
            'sectionnum' => (int) $cm->sectionnum,
            'beforemod'  => $next === null ? null : (int) $next,
        ];
    }
}
