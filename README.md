# Just One More Thing

## Benefit

When a student submits an assignment, the plugin automatically builds a quiz tailored to their work and puts it in the course. It uses the AIText question type which gives feedback and marks on free text responses.

- Each quiz reflects the individual student's own work, not a generic template
- Works in the background — quizzes appear after the next cron run without any intervention
- Quizzes are named after each student that has made a submission.
- Quiz can only be taken by the student it is named after.
- Optionally copy the settings (time limit, attempts, grade method, review options, etc.) from an existing quiz in the course into the generated quizzes.

## Quick Start (Teachers)

1. Open any assignment → **Edit settings**
2. Find the **Just One More Thing** section (bottom of form) → enable **Generate quiz from assignment**
3. (Optional) pick a **Template quiz** to copy settings from, set the number of questions, visibility and an extra AI prompt → Save
4. Once a student submits, their personalised quiz appears automatically in the course

## Template quizzes

Choose an existing quiz in the course as a template and its behavioural, grading and review settings are copied into each generated quiz. Per-student naming, visibility and access remain controlled by the plugin.

Admins can set a **Template tag** (Site administration → Plugins → Just One More Thing). When set, only quizzes whose activity carries that tag appear in the template selector; otherwise every quiz in the course is listed.

## Requirements

- Moodle 5.0 or later
- AIText question type
- Connection to an external LLM must be enabled
- Admin installs the plugin once via **Site administration → Notifications**

## License

GNU GPL v3 or later — see <http://www.gnu.org/copyleft/gpl.html>

## Author

Marcus Green, 2026
