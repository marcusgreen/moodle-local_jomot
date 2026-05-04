# local_jomot — Developer Documentation

## What it does

Just One More Thing (`local_jomot`) is a Moodle 5.x local plugin with two responsibilities:

1. **Assignment form integration** — adds a *Generate quiz from assignment* checkbox to every assignment's settings form. The setting is stored per-assignment and round-trips correctly when the form is re-opened.

2. **Automatic quiz creation on submission** — when a student finalises a submission to an assignment where the checkbox is enabled, an adhoc task is queued. The next cron run creates a `mod_quiz` activity in the same course, named after the submitting student.

A **web service** (`local_jomot_create_quiz`) is also exposed so the same quiz-creation logic can be driven externally (e.g. from a mobile app or an AI pipeline).

---

## Plugin structure

```
local/jomot/
├── classes/
│   ├── external/
│   │   └── create_quiz.php        # Web service + internal create() helper
│   ├── task/
│   │   └── create_quiz_adhoc.php  # Adhoc task: creates the quiz under cron
│   └── observer.php               # Event listener: queues the adhoc task
├── db/
│   ├── access.php                 # Capability definitions
│   ├── events.php                 # Event observer registration
│   ├── install.xml                # DB schema (first install only)
│   ├── services.php               # Web service registration
│   └── upgrade.php                # DB upgrade steps for existing installs
├── lang/en/local_jomot.php     # English language strings
├── tests/
│   ├── local_jomot_test.php            # Plugin smoke tests
│   └── external/
│       └── create_quiz_test.php           # Web service unit tests
├── devdoc.md                      # This file
├── index.php                      # Entry point (requires login, blank page)
├── lib.php                        # Moodle lib callbacks
├── settings.php                   # Admin settings page
└── version.php                    # Plugin metadata
```

---

## How the pieces connect

```
Teacher saves assignment form
        │
        ▼
lib.php: coursemodule_edit_post_actions()
        │  upserts local_jomot_assign_config
        ▼
        DB: local_jomot_assign_config.enable_quiz = 1

Student clicks "Submit assignment"
        │
        ▼ mod_assign\event\assessable_submitted fired
        │
        ▼
observer::on_submission()
        │  reads local_jomot_assign_config
        │  enable_quiz == 0 → return (no-op)
        │  enable_quiz == 1 → queue adhoc task
        ▼
        DB: mdl_task_adhoc (courseid, userid)

cron runs
        │
        ▼
create_quiz_adhoc::execute()
        │  fullname($user) → quiz name
        │
        ▼
create_quiz::create()
        │  builds moduleinfo stdClass
        │
        ▼
add_moduleinfo()  ← Moodle core
        │  creates quiz, grade item, calendar events
        ▼
        DB: mdl_quiz, mdl_course_modules, …
```

---

## Key files

### `add_question.php`

Contains three static methods:

| Method | Visibility | Purpose |
|---|---|---|
| `execute()` | public | Web service entry point — validates context, checks capabilities, then delegates to `add()` |
| `add()` | public | Internal entry point — no web-service validation, safe to call from cron/tasks |
| `build_question_data()` | private | Builds the `stdClass` required by `create_question()` which is eventually passed to `question_bank::add_question`; used by both `execute()` and `add()` |

`execute()` must not be called from cron because `validate_context()` calls `$PAGE->set_context()` and `require_login()`, which behave differently outside an HTTP request. `add()` skips that overhead and calls `create_question()` directly with sensible defaults that can be overridden via the `$overrides` array.

### Web service: `local_jomot_add_question`

Creates a `mod_quiz` activity via `add_moduleinfo()`. All grade items, calendar events, and completion records are created identically to the course editor.

### Required capabilities

Both must be held in the course context:

| Capability | Default holders |
|---|---|
| `local/jomot:addquestion` | `editingteacher`, `manager` |
| `moodle/question:add` | `editingteacher`, `manager` |

### Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `courseid` | `int` | required | Target course |
| `name` | `string` | required | Question name (max 255 chars) |
| `questiontext` | `string` | `''` | Question text HTML |
| `questiontextformat` | `int` | `FORMAT_HTML` | Text format |
| `generalfeedback` | `string` | `''` | General feedback HTML |
| `generalfeedbackformat` | `int` | `FORMAT_HTML` | Text format |
| `defaultmark` | `float` | `1.0` | Default mark |
| `penalty` | `float` | `0.3333333` | Penalty for wrong attempt |
| `qtype` | `string` | `'multichoice'` | Question type |
| `categoryid` | `int` | required | Question category |
| `answers` | `array` | required | Array of answers |

### Return value

```json
{ "questionid": 123 }
```

### Hardcoded defaults

| Setting | Value |
|---|---|
| `length` | `1` |
| `fraction` | `1.0` |
| `feedback` | `''` |

### `lib.php`

Contains two lib-based callbacks that Moodle discovers via `get_plugins_with_function`.

**`local_jomot_coursemodule_standard_elements($formwrapper, $mform)`**

Called for every course-module edit form. Returns early unless the module is `assign`. When editing an assignment it:
- Adds a *Just One More Thing* collapsible header.
- Adds an `advcheckbox` element named `local_jomot_enable_quiz`.
- When `?update=<cmid>` is present (edit mode), reads `local_jomot_assign_config` and calls `setDefault` with the saved value so the checkbox reflects current state.

**`local_jomot_coursemodule_edit_post_actions($data, $course)`**

Called by Moodle after `add_moduleinfo()` / `update_moduleinfo()` completes, so `$data->instance` is always the assignment instance ID. Performs an upsert on `local_jomot_assign_config`: updates `enable_quiz` + `timemodified` if a row exists, inserts with `timecreated` + `timemodified` if not.

### `db/events.php`

Registers the observer for `\mod_assign\event\assessable_submitted` with `internal => false`. The `internal => false` flag tells Moodle to defer firing the observer until the surrounding database transaction commits, so the adhoc task is never queued for a submission that was rolled back.

### `classes/observer.php`

`observer::on_submission()` is the event callback. It:
1. Resolves the assignment instance from `$event->contextinstanceid` (the CMID).
2. Checks `local_jomot_assign_config.enable_quiz`; returns immediately if not set.
3. Queues a `create_quiz_adhoc` task with `courseid` and `userid` as custom data.

Nothing slow or fallible happens in this method — the actual quiz creation is deferred to cron.

### `classes/task/create_quiz_adhoc.php`

Extends `\core\task\adhoc_task`. Runs under cron as the admin user, so capability checks pass without needing to impersonate the teacher. `execute()`:
1. Loads the student record by `userid`.
2. Calls `fullname($user)` to get the name in the site's configured format.
3. Calls `create_quiz::create()` with the course ID and that name.
4. Writes progress to the cron log via `mtrace()`.

If the task throws an exception, Moodle's task runner marks it as failed and will retry it on the next cron run.

### `classes/external/create_quiz.php`

Contains three static methods:

| Method | Visibility | Purpose |
|---|---|---|
| `execute()` | public | Web service entry point — validates context, checks capabilities, then delegates to `create()` |
| `create()` | public | Internal entry point — no web-service validation, safe to call from cron/tasks |
| `build_moduleinfo()` | private | Builds the `stdClass` required by `add_moduleinfo()`; used by both `execute()` and `create()` |

`execute()` must not be called from cron because `validate_context()` calls `$PAGE->set_context()` and `require_login()`, which behave differently outside an HTTP request. `create()` skips that overhead and calls `add_moduleinfo()` directly with sensible defaults that can be overridden via the `$overrides` array.

### `db/install.xml`

XMLDB schema run only on a fresh install. For existing installations, use `db/upgrade.php` — changes to `install.xml` alone have no effect once the plugin is already present in the DB.

### `db/upgrade.php`

`xmldb_local_jomot_upgrade($oldversion)` contains one step per version bump. Always end a step with `upgrade_plugin_savepoint(true, YYYYMMDDNN, 'local', 'jomot')`.

> **Gotcha:** If `version.php` was bumped *before* `upgrade.php` existed, the DB may already hold the target version number, causing the `if ($oldversion < X)` condition to be silently skipped. Always use a new, higher version number when adding a step after the fact.

---

## Database schema

### `local_jomot_assign_config`

One row per assignment instance. The unique key on `assignmentid` enforces this and makes the upsert logic in `lib.php` a simple exists-check.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT(10)` PK | Auto-increment |
| `assignmentid` | `INT(10)` UNIQUE | References `mdl_assign.id` |
| `enable_quiz` | `INT(1)` | `0` = off, `1` = on |
| `timecreated` | `INT(10)` | Unix timestamp |
| `timemodified` | `INT(10)` | Unix timestamp, updated on every save |

---

## Installation

1. Copy `jomot/` into `<moodleroot>/local/`.
2. Log in as admin → *Site administration > Notifications*.
3. Moodle creates the DB table, registers the capability, and activates the web service.

Re-initialise PHPUnit after installing if you run tests:

```bash
php admin/tool/phpunit/cli/init.php
```

## Upgrading an existing install

Bump `$plugin->version` in `version.php`, add a matching step in `db/upgrade.php`, then run:

```bash
php admin/cli/upgrade.php --non-interactive
```

## Testing the submission flow manually

1. Edit an assignment — enable the *Generate quiz from assignment* checkbox and save.
2. Log in as a student and submit the assignment.
3. Run cron:
   ```bash
   php admin/cli/cron.php
   ```
4. The quiz named after the student should appear in the course.

---

## Web service: `local_jomot_create_quiz`

Creates a `mod_quiz` activity via `add_moduleinfo()`. All grade items, calendar events, and completion records are created identically to the course editor.

### Required capabilities

Both must be held in the course context:

| Capability | Default holders |
|---|---|
| `local/jomot:createquiz` | `editingteacher`, `manager` |
| `moodle/course:manageactivities` | `editingteacher`, `manager` |

### Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `courseid` | `int` | required | Target course |
| `name` | `string` | required | Quiz name (max 255 chars) |
| `intro` | `string` | `''` | Introduction HTML |
| `introformat` | `int` | `FORMAT_HTML` | Text format |
| `timeopen` | `int` | `0` | Open timestamp (0 = no restriction) |
| `timeclose` | `int` | `0` | Close timestamp (0 = no restriction) |
| `timelimit` | `int` | `0` | Seconds (0 = no limit) |
| `grade` | `float` | `10.0` | Maximum grade |
| `attempts` | `int` | `0` | Max attempts (0 = unlimited) |
| `questionsperpage` | `int` | `0` | 0 = all on one page |
| `shuffleanswers` | `int` | `1` | Shuffle answer options |
| `preferredbehaviour` | `string` | `'deferredfeedback'` | Question behaviour |
| `visible` | `int` | `1` | Visible to students |
| `sectionnum` | `int` | `0` | Course section to place quiz in |

### Return value

```json
{ "cmid": 123, "quizid": 45, "name": "Jane Smith" }
```

### Hardcoded defaults

| Setting | Value |
|---|---|
| `overduehandling` | `'autoabandon'` |
| `grademethod` | `QUIZ_GRADEHIGHEST` |
| `navmethod` | `'free'` |
| Review options | `AFTER_CLOSE` only |
| Completion | disabled |

---

## Running tests

```bash
# Full plugin suite
php vendor/bin/phpunit --testsuite local_jomot_testsuite

# Single method
php vendor/bin/phpunit --testsuite local_jomot_testsuite --filter test_create_quiz_minimal
```

### Test coverage

**`tests/local_jomot_test.php`**

| Test | Verifies |
|---|---|
| `test_plugin_is_installed` | Plugin manager recognises the plugin |
| `test_pluginname_string_exists` | `pluginname` lang string resolves to "Just One More Thing" |
| `test_plugin_version` | Installed DB version meets minimum |
| `test_plugin_page_url` | `moodle_url` produces expected path |
| `test_index_php_exists` | `index.php` is on disk |
| `test_plugin_files_exist` | `version.php`, `lib.php`, lang file are on disk |
| `test_authenticated_user_can_login` | Data generator creates a user and sets session |

**`tests/external/create_quiz_test.php`**

| Test | Verifies |
|---|---|
| `test_create_quiz_minimal` | Minimal call creates quiz; `cmid`, `quizid`, DB record correct |
| `test_create_quiz_with_settings` | Optional settings persisted correctly |
| `test_create_quiz_hidden` | `visible=0` written to `course_modules` |
| `test_create_quiz_in_section` | Quiz placed in specified section |
| `test_invalid_timeclose_before_timeopen` | Inverted times throw `invalid_parameter_exception` |
| `test_create_quiz_requires_capability` | Student gets `required_capability_exception` |
| `test_create_quiz_invalid_course` | Bad course ID throws `dml_missing_record_exception` |
| `test_create_multiple_quizzes` | Two quizzes get distinct IDs |
| `test_quiz_section_created` | `quiz_sections` row created for new quiz |

New tests should extend `\advanced_testcase` and call `$this->resetAfterTest()`.

---

## Code style

```bash
phpcs --standard=phpcs.xml local/jomot/
phpcbf --standard=phpcs.xml local/jomot/
```

Lang string keys must be in alphabetical order (enforced by PHPCS).

---

## Common next steps

- **Act on the quiz** — after `create_quiz_adhoc` runs, query `mdl_quiz` by course to find the new quiz and populate it with AI-generated questions.
- **Add a page** — create a PHP file, set `$PAGE->set_url / set_context / set_title`, output between `$OUTPUT->header()` and `$OUTPUT->footer()`.
- **Add a capability** — declare it in `db/access.php`, check with `require_capability('local/jomot:newcap', $context)`.
- **Add a scheduled task** — declare it in `db/tasks.php`, implement under `classes/task/`.
- **Prevent duplicate quizzes** — store the `quizid` back into `local_jomot_assign_config` after creation; skip the task if that column is already populated.
