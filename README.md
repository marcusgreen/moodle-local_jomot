# Just One More Thing (`local_jomot`)

A Moodle 5.x local plugin that automatically generates a quiz from an assignment submission using AI.

## What it does

- Adds a **Generate quiz from assignment** checkbox to every assignment's settings form.
- When a student finalises a submission to an enabled assignment, an adhoc task is queued that creates a `mod_quiz` activity in the same course, named after the submitting student.
- Exposes a **web service** (`local_jomot_create_quiz`) so the same quiz-creation logic can be driven externally — for example, from a mobile app or an AI pipeline.

## Requirements

- Moodle 5.0+ (requires `2025041400`)
- PHP 8.x

## Installation

1. Copy the `jomot/` directory into `<moodleroot>/local/`.
2. Log in as admin and navigate to **Site administration → Notifications**.
3. Complete the upgrade to create the database table, register the capability, and activate the web service.

## Usage

1. Edit an assignment and enable the **Generate quiz from assignment** checkbox under the **Just One More Thing** section, then save.
2. A student submits the assignment.
3. Run cron (`php admin/cli/cron.php`) — a quiz named after the student will appear in the course.

## Web service

The `local_jomot_create_quiz` external function creates a `mod_quiz` activity programmatically. Callers must hold `local/jomot:createquiz` and `moodle/course:manageactivities` in the target course context.

## Running tests

```bash
php admin/tool/phpunit/cli/init.php
php vendor/bin/phpunit --testsuite local_jomot_testsuite
```

## License

GNU GPL v3 or later — see <http://www.gnu.org/copyleft/gpl.html>

## Author

Marcus Green, 2026
