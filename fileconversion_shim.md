# Document converter shim (`cli/unoconv-shim`)

This document explains the fix that was applied so that `local_jomot` can extract
text from office-document submissions (DOCX, ODT, DOC, RTF). For the background
on *why* the original setup was broken, see
[`fileconversion_issues.md`](fileconversion_issues.md).

## What the problem was, in one line

The system `unoconv` 0.7 binary is broken against LibreOffice 26.x
(`AttributeError: module 'unohelper' has no attribute 'absolutize'`), so Moodle's
document converter could not convert anything, even though LibreOffice itself
works.

## The fix

A small shell shim, `cli/unoconv-shim`, replaces the broken `unoconv` binary. It
emulates the three command forms Moodle's `fileconverter_unoconv` plugin uses and
routes the real conversion straight to LibreOffice (`soffice`), skipping the dead
unoconv Python glue.

Nothing in Moodle core, the `fileconverter_unoconv` plugin, or `local_jomot` PHP
was edited. The only changes are:

| Change | Where | Type |
|--------|-------|------|
| New shim script | `local/jomot/cli/unoconv-shim` | new file in this repo (`0755`) |
| Converter path setting | `mdl_config.pathtounoconv` | DB value, set to the shim's absolute path |

The setting now points at:

```
/var/www/mdl52/public/local/jomot/cli/unoconv-shim
```

## How it works

The plugin only ever calls the binary three ways
(`files/converter/unoconv/classes/converter.php`):

| Plugin call | What the plugin expects | What the shim does |
|-------------|-------------------------|--------------------|
| `unoconv --version` | a line matching `/unoconv (\d+\.\d+)/`, version ≥ 0.7 (read from **stdout**) | prints `unoconv 0.9 (shim -> LibreOffice …)` to stdout |
| `unoconv --show` | format list parsed by `/\[\.(.*)\]/` (read from **stderr**) | prints `[.txt] [.docx] [.doc] [.odt] [.rtf] [.pdf] [.html]` to stderr |
| `unoconv -f FMT -o OUT IN` | converted file written to `OUT`, exit 0 | runs `soffice --headless --convert-to FMT --outdir <dir of OUT> IN`, renames the result to `OUT` |

Important detail: real `unoconv` writes its `--show` list to **stderr**, and the
plugin reads stderr (`proc_open` fd 2) for it but reads stdout for `--version`.
The shim matches that split — `--version` to stdout, `--show` to stderr. Getting
this wrong makes `supports()` return false and conversions silently unsupported.

### Conversion flow at runtime

```
assign submission (essay.docx)
  -> local_jomot  classes/task/create_quiz_adhoc.php   (adhoc task)
  -> local_jomot  classes/submission_extractor.php      ($converter->start_conversion($file,'txt'))
  -> core_files\converter                               (subsystem, routes to enabled plugin)
  -> fileconverter_unoconv  classes/converter.php:151   (exec "$CFG->pathtounoconv -f txt -o OUT IN")
  -> local/jomot/cli/unoconv-shim                       (THIS shim)
  -> soffice  (LibreOffice 26.2)                         (does the actual conversion)
  -> txt content -> cached in local_jomot_extract_cache -> quiz generation
```

The broken `/usr/bin/unoconv` is never invoked.

### Implementation notes

- **Per-call LibreOffice profile.** Each conversion uses a unique
  `-env:UserInstallation` temp profile, so concurrent conversions don't fight
  over a single locked profile (the job unoconv's listener used to do). The temp
  profile is removed on exit.
- **Cleaner text filter.** For `txt` the shim uses the `txt:Text` export filter
  (cleaner output than bare `txt`).
- **BOM stripped.** LibreOffice's text export prepends a UTF-8 BOM; the shim
  removes it from the first line of `txt` output.
- **Configurable LibreOffice path.** Set the `SOFFICE_BIN` environment variable
  to override the default `soffice`.

## Verification performed

All checks passed after applying the fix:

| Check | Result |
|-------|--------|
| `unoconv-shim --version` | `unoconv 0.9 (shim -> LibreOffice 26.2.2.2 …)` |
| `unoconv-shim --show` | lists `[.txt] [.docx] [.doc] [.odt] [.rtf] [.pdf] [.html]` |
| `unoconv-shim -f txt -o OUT IN` (standalone) | exit 0, text correct, BOM removed |
| `fileconverter_unoconv\converter::test_unoconv_path()` | `status: ok` |
| `converter::supports('docx','txt')` | `true` |
| Full `\core_files\converter::start_conversion()` round-trip | `status: 2` (COMPLETE), text extracted exactly |

## Operating notes

- **Activate / change:** Site admin → *Plugins → Document converters → Unoconv →
  Path to unoconv*, or update `mdl_config.pathtounoconv` and purge caches
  (`php admin/cli/purge_caches.php`).
- **Revert:** set the path back to `/usr/bin/unoconv`.
- **Survives `apt`:** an `apt` update of the `unoconv` package rewrites
  `/usr/bin/unoconv`, not this shim.
- **Travels with the plugin:** because the shim lives in the repo, deploying
  `local_jomot` carries the fix. Ensure the file stays executable (`0755`) after
  deploy/checkout.
- **Dependency:** requires a working `soffice` (LibreOffice) on the server,
  reachable on `PATH` or via `SOFFICE_BIN`.

## Limitations

- PDF and image submissions are still not text-extracted by `local_jomot` (the
  extractor skips them by design).
- This reuses the abandoned `fileconverter_unoconv` plugin as the integration
  point. A dedicated `fileconverter_soffice` plugin (Option B in
  `fileconversion_issues.md`) would remove that dependency entirely if a fully
  unoconv-free setup is ever wanted.
