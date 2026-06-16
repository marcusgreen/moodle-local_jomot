# Just One More Thing — User Guide

**Just One More Thing** (`local_jomot`) automatically builds a personalised quiz for each
student when they submit an assignment. The generated quiz uses the **AIText** question type,
which marks and gives feedback on free-text responses.

Each quiz:

- Reflects the individual student's own submission, not a generic template
- Appears automatically after the next cron run — no manual step needed
- Is named `firstname-lastname_assignmentname` after the student who submitted
- Can only be seen and taken by the student it is named after (hidden from everyone else)

## Requirements

- Moodle 5.0 or later
- The **AIText** question type (`qtype_aitext`) installed
- The AI bridge tool (`tool_ai_bridge`) and a connection to an external LLM enabled on your site
- The plugin installed by an admin (**Site administration → Notifications**)

## Enabling it on an assignment

1. Open any assignment and choose **Edit settings**.
2. Scroll to the **Just One More Thing** section near the bottom of the form.
3. Tick **Generate quiz from assignment**.
4. Save the form.

![Enabling quiz generation in the assignment settings form](jmot_form.png)

The help icon next to the checkbox explains: *"When enabled, Just One More Thing will
automatically generate a quiz based on this assignment's content after submission."*

## What happens next

When a student submits the assignment, the plugin queues a background task. On the next cron
run that task sends the student's submission to the AI, generates the questions, and creates
the quiz in the course — named after the student, and restricted so only that student can
access it.

Only the **online text** part of a submission is sent to the AI. If a submission has no online
text, the quiz is still created but contains no AI-generated questions.

## Options

When editing the assignment you can also control:

- **Number of questions** — how many questions the generated quiz contains (default 5,
  maximum 50). Questions are shown one per page.
- **Quiz visible to students** — make the quiz visible immediately, or leave it hidden
  (the default) so you can review it before releasing.
- **Additional AI prompt** — extra text appended to the default prompt, to steer how the AI
  generates questions and feedback for this assignment.
- **Template quiz** — pick an existing quiz in the course whose settings (time limit, attempts,
  grade method, review options, etc.) are copied into each generated quiz. Per-student naming,
  visibility and access are still controlled by the plugin. Choose *Default settings (no
  template)* to use the plugin defaults.

## Admin settings

Under **Site administration → Plugins → Local plugins → Just One More Thing**:

- **Default AI prompt** — the base prompt sent to the AI when generating questions. The
  `{numquestions}` placeholder is replaced with the configured number of questions.
- **Template tag** — when set, only quizzes whose activity carries that tag appear in the
  **Template quiz** selector. Leave empty to list every quiz in the course.

## License

GNU GPL v3 or later — see <http://www.gnu.org/copyleft/gpl.html>

## Author

Marcus Green, 2026
