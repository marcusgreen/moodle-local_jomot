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

$string['backend'] = 'AI backend';
$string['backend_coreai'] = 'Moodle core AI subsystem';
$string['backend_desc'] = 'Which AI system generates the quiz questions. The AI manager (local_ai_manager) is listed only when it is installed, and uses its "questiongeneration" purpose.';
$string['backend_localaimanager'] = 'AI manager (local_ai_manager)';
$string['default_ai_prompt'] = 'Default AI prompt';
$string['default_ai_prompt_desc'] = 'Default prompt sent to AI when generating questions from assignment submission text.';
$string['enable_quiz'] = 'Enable Just One More Thing quiz generation';
$string['enable_quiz_desc'] = 'Generate a quiz from this assignment using AI';
$string['enable_quiz_help'] = 'When enabled, Just One More Thing will automatically generate a quiz based on this assignment\'s content after submission.';
$string['enable_quiz_label'] = 'Generate quiz from assignment';
$string['err_ai_parse_failed'] = 'AI response could not be parsed into questions. Check the AI backend configuration and the default prompt format.';
$string['err_questionmark'] = 'Marks per question must be a number greater than zero.';
$string['err_quizgrade'] = 'Maximum quiz grade must be empty or a number of zero or more.';
$string['err_retrievingfeedback'] = 'Error retrieving questions from the AI system: {$a}';
$string['err_retrievingfeedback_checkconfig'] = 'Unable to retrieve questions from the AI system. The AI configuration might be wrong; contact your administrator.';
$string['event_quiz_created'] = 'Just One More Thing quiz created';
$string['jomot:addquestion'] = 'Add an aitext question to a quiz via the Just One More Thing web service';
$string['jomot:createquiz'] = 'Create a quiz via the Just One More Thing web service';
$string['numquestions_label'] = 'Number of questions';
$string['pluginname'] = 'Just One More Thing';
$string['prompt'] = 'Additional AI prompt';
$string['prompt_help'] = 'Text appended to the default AI prompt. Use this to give the AI extra instructions about how to generate feedback for this assignment.';
$string['prompt_label'] = 'Additional AI prompt';
$string['questionmark'] = 'Marks per question';
$string['questionmark_help'] = 'Maximum mark for each AI-generated question. Questions copied from a template quiz keep the marks they have in the template.';
$string['questionmark_label'] = 'Marks per question';
$string['quizgrade'] = 'Maximum quiz grade';
$string['quizgrade_help'] = 'Maximum grade for the generated quiz. The total of the question marks is scaled to this grade. Leave empty to use the template quiz grade, or 10 when there is no template.';
$string['quizgrade_label'] = 'Maximum quiz grade';
$string['quizvisible'] = 'Quiz visible to students';
$string['quizvisible_desc'] = 'Make generated quiz visible to students immediately';
$string['quizvisible_help'] = 'When enabled, the generated quiz will be visible to students as soon as it is created. Disable to review the quiz before releasing it.';
$string['quizvisible_label'] = 'Quiz visible to students';
$string['skipreason_conversionnotsupported'] = 'The file type could not be converted to text and was excluded from question generation.';
$string['skipreason_extractionfailed'] = 'Text extraction failed for this file, so it was excluded from question generation.';
$string['skipreason_nocontent'] = 'The file contains no text and was excluded from question generation.';
$string['task_create_quiz'] = 'Create Just One More Thing quiz from assignment submission';
$string['templatequiz'] = 'Template quiz';
$string['templatequiz_help'] = 'Select an existing quiz in this course whose settings (time limit, attempts, grade, review options, etc.) are copied into the quiz generated from this assignment. If a template tag is configured by the administrator, only quizzes carrying that tag are listed. Choose "Default settings" to use the plugin defaults.';
$string['templatequiz_label'] = 'Template quiz';
$string['templatequiz_none'] = 'Default settings (no template)';
$string['templatetag'] = 'Template tag';
$string['templatetag_desc'] = 'If set, only quizzes tagged with this tag (the activity Tags field) are used as templates for generated quizzes. Leave empty to use the default settings.';
