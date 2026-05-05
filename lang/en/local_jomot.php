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
 * Language strings.
 *
 * @package    local_jomot
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['jomot:addquestion'] = 'Add an aitext question to a quiz via the Just One More Thing web service';
$string['jomot:createquiz'] = 'Create a quiz via the Just One More Thing web service';
$string['enable_quiz'] = 'Enable Just One More Thing quiz generation';
$string['enable_quiz_desc'] = 'Generate a quiz from this assignment using AI';
$string['enable_quiz_help'] = 'When enabled, Just One More Thing will automatically generate a quiz based on this assignment\'s content after submission.';
$string['enable_quiz_label'] = 'Generate quiz from assignment';
$string['numquestions_label'] = 'Number of questions';
$string['default_ai_prompt'] = 'Default AI prompt';
$string['default_ai_prompt_desc'] = 'Default prompt sent to AI when generating questions from assignment submission text.';
$string['err_ai_parse_failed'] = 'AI response could not be parsed into questions. Check the AI backend configuration and the default prompt format.';
$string['quizvisible_label'] = 'Quiz visible to students';
$string['quizvisible_desc'] = 'Make generated quiz visible to students immediately';
$string['quizvisible_help'] = 'When enabled, the generated quiz will be visible to students as soon as it is created. Disable to review the quiz before releasing it.';
$string['prompt_label'] = 'Additional AI prompt';
$string['prompt_help'] = 'Text appended to the default AI prompt. Use this to give the AI extra instructions about how to generate feedback for this assignment.';
$string['pluginname'] = 'Just One More Thing';
$string['task_create_quiz'] = 'Create Just One More Thing quiz from assignment submission';
$string['event_quiz_created'] = 'Just One More Thing quiz created';
