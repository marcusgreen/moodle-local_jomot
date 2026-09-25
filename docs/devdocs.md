# Just One More Thing — Developer Guide

This document explains how `local_jomot` works internally: the moving parts, how
data flows from an assignment submission to a generated quiz, and where to look
when changing or debugging it. For the teacher/admin view, see
[`userdocs.md`](userdocs.md).

## Overview

`local_jomot` is a Moodle 5.x local plugin. When a student submits an assignment
that has the feature enabled, the plugin:

1. Extracts the text of the submission (online text plus attached files).
2. Sends that text to an LLM, asking for N questions about the student's own work.
3. Creates a new `mod_quiz` activity in the course, restricted to that student.
4. Optionally copies settings and questions from a teacher-chosen *template quiz*.
5. Adds one `qtype_aitext` question per AI-generated question, so the student's
   free-text answers are themselves marked by AI.

All slow work (file conversion, AI calls, quiz creation) runs in an adhoc task
under cron, never during the student's submission request.

## Dependencies

| Component | Used for | Where |
|---|---|---|
| `local_ai_content` | Extracting text from submitted files (with caching) | `classes/submission_extractor.php` |
| `tool_ai_bridge` | Sending the question-generation prompt to the LLM | `create_quiz::fetch_ai_questions()` |
| `qtype_aitext` | Question type of generated questions; its `backend` setting picks the AI backend | `add_question::add_to_quiz()`, `create_quiz::fetch_ai_questions()` |
| `tool_aiconnect` | Default model name when none is given for a question | `add_question::add_to_quiz()` |
| Site document converter | Converting office documents to text (via `local_ai_content`) | See [`../fileconversion_shim.md`](../fileconversion_shim.md) |

Only `local_ai_content` is declared in `version.php` `$plugin->dependencies`; the
others are assumed to be installed.

## File map

```
local/jomot/
├── classes/
│   ├── constants.php               # DEFAULT_NUMQUESTIONS (5), MAX_NUMQUESTIONS (50), DEFAULT_QUESTIONSPERPAGE (1)
│   ├── observer.php                # Listens for assign submissions, queues the adhoc task
│   ├── submission_extractor.php    # Online text + file text → one plain-text block
│   ├── event/quiz_created.php      # Event fired after a quiz is generated
│   ├── external/create_quiz.php    # Web service + create() used by the task; AI call, template copy
│   ├── external/add_question.php   # Web service + add_to_quiz(); creates aitext questions
│   └── task/create_quiz_adhoc.php  # Adhoc task: extract → create quiz
├── cli/unoconv-shim                # Replacement for broken unoconv binary (see fileconversion_shim.md)
├── db/
│   ├── access.php                  # local/jomot:createquiz
│   ├── events.php                  # Observer registration
│   ├── install.xml                 # local_jomot_assign_config table
│   ├── services.php                # local_jomot_create_quiz, local_jomot_add_question
│   └── upgrade.php
├── lang/en/local_jomot.php
├── lib.php                         # Assignment form hooks, template quiz lookup
├── settings.php                    # Default AI prompt, template tag
├── index.php                       # Placeholder page (login + empty page)
└── tests/                          # PHPUnit + Behat
```

## End-to-end flow

```
Teacher saves assignment settings
  └─ lib.php: local_jomot_coursemodule_edit_post_actions()
       └─ upsert local_jomot_assign_config (enable_quiz, numquestions, quizvisible, prompt, templatequiz)

Student submits assignment
  └─ \mod_assign\event\assessable_submitted
       └─ observer::on_submission()
            ├─ config missing or enable_quiz = 0 → return
            └─ queue create_quiz_adhoc with custom data:
               courseid, userid, assignmentname, contextid, submissionid,
               numquestions, templatequiz, quizvisible, prompt

Cron
  └─ create_quiz_adhoc::execute()
       ├─ submission_extractor::extract(contextid, submissionid, userid)
       │    ├─ assignsubmission_onlinetext → strip_tags
       │    └─ assignsubmission_file files → local_ai_content document_extractor
       └─ create_quiz::create(courseid, assignmentname, overrides, text, numquestions)
            ├─ name = "firstname-lastname_<assignmentname>" (lowercased names)
            ├─ fetch_ai_questions()          ← AI call happens BEFORE any DB writes
            ├─ build_moduleinfo()            ← plugin defaults + per-user availability
            ├─ apply_template_to_moduleinfo()  (if template quiz set)
            ├─ add_moduleinfo()              ← Moodle core creates the quiz
            ├─ copy_template_questions()       (if template quiz set)
            ├─ add_questions_to_quiz()       ← one aitext question per AI result
            └─ trigger \local_jomot\event\quiz_created
```

## Components in detail

### Assignment form integration (`lib.php`)

Moodle calls these legacy callbacks for every course-module form:

- **`local_jomot_coursemodule_standard_elements()`** — returns early unless the
  module is `assign`. Adds a *Just One More Thing* header with:
  `local_jomot_enable_quiz` (checkbox), `local_jomot_numquestions` (int),
  `local_jomot_quizvisible` (checkbox), `local_jomot_prompt` (textarea) and
  `local_jomot_templatequiz` (select). All but the first are hidden via `hideIf`
  until the feature is enabled. When editing (`?update=<cmid>`), saved values are
  loaded from `local_jomot_assign_config` and applied with `setDefault()`.
- **`local_jomot_coursemodule_edit_post_actions()`** — runs after the assignment
  is saved, so `$data->instance` is the assign id. Clamps `numquestions` to
  `1..MAX_NUMQUESTIONS`, cleans the prompt with `PARAM_TEXT`, and upserts the
  config row.
- **`local_jomot_get_template_quizzes($courseid)`** — options for the template
  select. Lists all non-deleted quizzes in the course, or, when the admin
  `templatetag` setting is set, only quizzes whose course module carries that tag
  (case-insensitive match on `tag.rawname`).

### Observer (`classes/observer.php`)

Registered in `db/events.php` for `\mod_assign\event\assessable_submitted` with
`internal => false`, so it only runs after the submission transaction commits.
It does no slow work — it reads the config row and queues a
`create_quiz_adhoc` task carrying the ids needed later. The config values
(`numquestions`, `templatequiz`, `quizvisible`, `prompt`) are snapshotted into the task at
submission time; changing the assignment settings afterwards does not affect
tasks already queued.

### Adhoc task (`classes/task/create_quiz_adhoc.php`)

Runs under cron (as admin, so no capability checks apply). It loads the student,
runs the extractor, logs any skipped files with `mtrace()`, then calls
`create_quiz::create()` with:

| Override | Value |
|---|---|
| `questionsperpage` | `constants::DEFAULT_QUESTIONSPERPAGE` (1) |
| `templatequiz` | from task data |
| `useremail` | the student's email — drives naming and access restriction |
| `visible` | `quizvisible` from task data |
| `prompt` | the assignment's additional AI prompt |

Any exception fails the task; Moodle's task runner retries it with backoff.

### Submission extraction (`classes/submission_extractor.php`)

`extract($contextid, $submissionid, $userid)` returns
`['text' => string, 'skippedfiles' => [['filename', 'reason'], ...]]`.

- **Online text** — read from `assignsubmission_onlinetext.onlinetext`, then
  `strip_tags()` and `trim()`.
- **Files** — every file in `assignsubmission_file / submission_files / <submissionid>`
  is handed to `\local_ai_content\document_extractor`, obtained through
  `\core\di::get()` (which makes it easy to mock in tests). Per file:
  - `is_file_supported()` false → skipped, reason `skipreason_conversionnotsupported`
  - `extract_text_from_file()` throws → skipped, reason `skipreason_extractionfailed`
  - empty result → skipped, reason `skipreason_nocontent`

  Extraction is attributed to `$userid` with component `local_jomot`, which
  matters for AI quotas when `local_ai_content` uses an AI backend for PDFs or
  images. Caching of extracted text is handled by `local_ai_content`; this plugin
  no longer keeps its own cache (the old `local_jomot_extract_cache` table is
  dropped in the `2026092400` upgrade step).

The combined text is built by `build_structured_submission()`:

| Online text | File text | Result |
|---|---|---|
| yes | yes | `[Online text submission]\n…\n\n[Submitted files]\n…` + skipped note |
| yes | no | online text only |
| no | yes | file text + skipped note |
| no | no | `''` |

The skipped note is `[Note: The following files could not be analysed and are not
included: a.mp4, b.zip]`, so the LLM knows part of the submission was missing.

### Quiz creation (`classes/external/create_quiz.php`)

Two entry points share the same logic:

- **`execute()`** — the `local_jomot_create_quiz` web service. Validates
  parameters and context, requires `local/jomot:createquiz` and
  `moodle/course:manageactivities` in the course, checks `timeclose > timeopen`,
  then calls `create()`.
- **`create()`** — no context validation or capability checks; safe for cron.
  Used by the adhoc task.

Key steps inside `create()`:

1. **Naming.** If `useremail` matches a non-deleted user, the quiz name becomes
   `strtolower(firstname) . '-' . strtolower(lastname) . '_' . <name>`.
2. **AI questions first.** `fetch_ai_questions()` runs before any DB writes, so a
   failed or unparseable AI response throws without leaving an empty quiz behind.
   Skipped entirely when the submission text is empty (the quiz is then created
   with no AI questions).
3. **Module info.** `build_moduleinfo()` sets plugin defaults (see below). If
   `useremail` is set, `build_user_availability()` adds a restriction on the
   `email` profile field with `showc => [false]`, so the quiz is hidden from
   everyone else rather than shown greyed out.
4. **Template settings.** `apply_template_to_moduleinfo()` copies behavioural,
   grading and review fields from the template quiz (time limit, attempts, grade
   method, navigation, review options, password → `quizpassword`, etc.). It never
   copies name, intro, visibility, open/close times or availability, so per-student
   naming and access stay intact.
5. **`add_moduleinfo()`** creates the quiz, grade item and calendar events exactly
   as the course editor would.
6. **Template questions.** `copy_template_questions()` reads the template's slots
   via `qbank_helper::get_question_structure()`:
   - concrete question → `quiz_add_quiz_question()` pointing at the **same**
     question bank entry (by reference, keeps `maxmark`), so later edits to a
     template question appear in quizzes generated afterwards;
   - random slot → `structure->add_random_questions()` with the slot's filter
     condition;
   - slots without a ready question version are skipped.
   Template questions take the first slots.
7. **AI questions** are appended by `add_questions_to_quiz()`, named
   `<quiz name> Q1`, `Q2`, … via `add_question::add_to_quiz()`.
8. **Event.** `\local_jomot\event\quiz_created` is triggered in the course context
   with `other = ['cmid', 'name']`.

Plugin defaults set by `build_moduleinfo()` (unless a template overrides them):

| Setting | Default |
|---|---|
| `visible` | `0` (hidden) |
| `grade` | `10.0` |
| `attempts` | `0` (unlimited) |
| `questionsperpage` | `1` |
| `preferredbehaviour` | `deferredfeedback` |
| `grademethod` | `1` (highest grade) |
| `overduehandling` | `autoabandon` |
| `navmethod` | `free` |
| Review options | after the quiz closes only (`0x04`) |
| Completion | off |

### AI prompt and response

`build_ai_prompt()` builds the prompt in three parts:

1. the `local_jomot/default_ai_prompt` admin setting (with a hard-coded
   fallback), with `{numquestions}` replaced;
2. the assignment's *Additional AI prompt*, if any, under
   `Additional instructions from the teacher:`. It travels from
   `local_jomot_assign_config.prompt` through the task's `prompt` custom data to
   the `prompt` override of `create_quiz::create()`;
3. the submission, wrapped in `<<<SUBMISSION_START>>>` / `<<<SUBMISSION_END>>>`
   with an instruction that it is untrusted content. This is a basic
   prompt-injection guard; keep the submission last and do not remove it.

The request goes through `\tool_ai_bridge\ai_bridge` in the course context, using
the backend configured for `qtype_aitext` (`core_ai_subsystem` if unset).

`parse_ai_questions_response()` strips markdown code fences and accepts:

- a JSON array of objects with `questiontext` (required) and `aiprompt`, or
- a single such object.

Anything else throws `err_ai_parse_failed` (with the first 500 chars of the raw
response in the debug info), which fails the task so it retries. Results are
capped at `numquestions`.

### Question creation (`classes/external/add_question.php`)

- **`execute()`** — the `local_jomot_add_question` web service. Same capability
  checks as `create_quiz`, plus validation of `responseformat`
  (`editor|plain|monospaced`) and word limits.
- **`add_to_quiz($quizid, $params)`** — no context checks; used by `create_quiz`.
  Saves a `qtype_aitext` question with status *ready* into the **quiz module's**
  default question category (so questions are not shared across the course),
  adds it to the end of the quiz with `quiz_add_quiz_question()` and recomputes
  the quiz's sum of grades. If no `model` is given, the first model listed in
  `tool_aiconnect/model` is used.

Returns `['questionid' => int, 'slotid' => int]`.

## Database

### `local_jomot_assign_config`

One row per assignment (unique key on `assignmentid`).

| Column | Type | Notes |
|---|---|---|
| `id` | int(10) | PK |
| `assignmentid` | int(10) | `assign.id`, unique |
| `enable_quiz` | int(1) | 0/1 |
| `numquestions` | int(4) | default 5, clamped 1–50 on save |
| `quizvisible` | int(1) | 0 = hidden (default) |
| `prompt` | text | additional per-assignment AI instructions |
| `templatequiz` | int(10) | `quiz.id` of the template, 0 = none |
| `timecreated` / `timemodified` | int(10) | Unix timestamps |

Schema changes need both `db/install.xml` (fresh installs) and a step in
`db/upgrade.php` with a new, higher version number and matching
`upgrade_plugin_savepoint()`.

## Web services

Defined in `db/services.php`; both are `write`, AJAX-enabled and require
`local/jomot:createquiz` (granted to `editingteacher` and `manager` by default)
plus `moodle/course:manageactivities`.

| Function | Class | Returns |
|---|---|---|
| `local_jomot_create_quiz` | `local_jomot\external\create_quiz` | `{cmid, quizid, name}` |
| `local_jomot_add_question` | `local_jomot\external\add_question` | `{questionid, slotid}` |

`local_jomot_create_quiz` accepts the quiz settings listed in
`execute_parameters()` plus `useremail`, `submissiontext` and `numquestions`, so
external callers can run the full "generate from text" pipeline. It does not take
`templatequiz`; templates only apply to the cron path.

Do not call `execute()` from cron or tasks — `validate_context()` expects a web
request. Use `create()` / `add_to_quiz()` instead.

## Admin settings (`settings.php`)

| Setting | Purpose |
|---|---|
| `local_jomot/default_ai_prompt` | Base prompt; must ask for a JSON array of `{questiontext, aiprompt}` objects. `{numquestions}` is substituted. |
| `local_jomot/templatetag` | When set, only quizzes with this tag appear in the template selector. |

## Behaviour to be aware of

- **One quiz per submission event.** Each `assessable_submitted` event queues a
  new task. There is no check for a quiz already created for that student and
  assignment, so resubmissions (where the assignment allows them) produce
  additional quizzes.
- **Partial failure after `add_moduleinfo()`.** The AI call is done up front, but
  if template copying or question creation throws after the quiz exists, the task
  retry will create a second quiz.
- **Access is by email.** Changing a student's email after generation breaks the
  availability restriction on quizzes already created for them.
- **No privacy provider.** The plugin has no `classes/privacy/provider.php`. The
  config table holds no personal data, but submission text is sent to external
  AI services via `local_ai_content` and `tool_ai_bridge`.

## Testing

Tests live in `tests/`:

| File | Covers |
|---|---|
| `local_jomot_test.php` | Install/smoke checks |
| `submission_extractor_test.php` | Text combination and skipped-file handling, with `document_extractor` mocked through `\core\di` |
| `external/create_quiz_test.php` | Web service, availability restriction, adhoc task, submission → quiz end-to-end, `quizvisible`, prompt building and the additional prompt reaching the task |
| `external/create_quiz_template_test.php` | Copying template questions |
| `external/add_question_test.php` | aitext question creation and validation |
| `behat/admin_settings.feature` | Admin can view and save the default AI prompt |

Run each test file from the Moodle root, for example:

```bash
vendor/bin/phpunit public/local/jomot/tests/submission_extractor_test.php
vendor/bin/phpunit public/local/jomot/tests/external/create_quiz_test.php
```

`vendor/bin/phpunit --filter local_jomot` loads every test class in the site and
can fail on unrelated core tests, so per-file runs are more reliable.

No test makes a real AI call. Quiz-creation tests leave the submission text
empty, which skips `fetch_ai_questions()`. Extractor tests replace
`document_extractor` with a mock through `\core\di`. `build_ai_prompt()` is
tested directly, but the AI request and response parsing are not covered.

To try the whole flow manually:

1. Enable *Generate quiz from assignment* on an assignment and save.
2. As a student, submit online text and/or a file.
3. Run `php admin/cli/cron.php` (or `php admin/cli/adhoc_task.php --execute`).
4. Check the cron output for `local_jomot:` lines and the course for the new quiz.
