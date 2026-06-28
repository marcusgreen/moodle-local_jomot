# File Conversion Issues (DOCX → text for submission extraction)

## Summary

`local_jomot` extracts text from assignment submissions so it can feed
submitted content into quiz generation. For office documents (DOCX, ODT, DOC,
etc.) it relies on Moodle's document converter subsystem to convert the file to
plain text:

```php
// classes/submission_extractor.php
$converter = new \core_files\converter();
if (!$converter->can_convert_storedfile_to($file, 'txt')) {
    return '';
}
$conversion = $converter->start_conversion($file, 'txt');
```

On this installation the conversion **does not work at runtime**, so office
documents are silently skipped and no text is extracted from them. Online text
submissions are unaffected.

## Root cause

The site is configured to use the `fileconverter_unoconv` plugin, which shells
out to the `unoconv` binary. The configuration *looks* correct, but the binary
is broken against the installed LibreOffice.

| Component | State |
|-----------|-------|
| `fileconverter_unoconv` plugin | installed, enabled (not in disabled list) |
| `$CFG->pathtounoconv` | `/usr/bin/unoconv` |
| `unoconv` binary | present, version 0.7 |
| LibreOffice backend | LibreOffice **26.2.2.2**, `/usr/bin/soffice` present |
| `unoconv --show` (format probe) | works, advertises `[.txt]`, `[.docx]`, etc. |
| Actual conversion (`unoconv -f txt -o out in`) | **fails** |

The failure:

```
AttributeError: module 'unohelper' has no attribute 'absolutize'
```

`unoconv` 0.7 calls `unohelper.absolutize(...)`, which was removed from
LibreOffice's `unohelper` module in newer releases. LibreOffice 26.2 only exposes
`systemPathToFileUrl` and `fileUrlToSystemPath`. Three call sites are affected:

```
/usr/bin/unoconv:903   input file
/usr/bin/unoconv:917   template
/usr/bin/unoconv:1032  output file
```

Why config-level checks still pass: `unoconv --version` and `unoconv --show` do
not touch the broken LibreOffice API, so Moodle's path/version checks succeed.
Only the real conversion call hits the dead `absolutize` and throws.

### Why upgrading unoconv is a dead end

- `apt` has no newer package: installed `0.7-2ubuntu1`, candidate
  `0.7-2ubuntu1`.
- The unoconv project is effectively abandoned (last upstream release 0.9,
  2019) and does not track LibreOffice 26.2.

## Key finding: LibreOffice itself works

Calling LibreOffice directly, bypassing the broken unoconv Python glue, converts
DOCX → text cleanly on this box:

```bash
soffice --headless --convert-to txt:Text --outdir <dir> <file>.docx
```

A clean round-trip (txt → docx → txt) extracted the text correctly (output
carries a leading UTF-8 BOM that should be stripped). The engine is fine; only
unoconv's wrapper is broken.

Also present on the system: `catdoc` (legacy `.doc`), `python-docx 1.1.0`,
PHP 8.3. Not present: `pandoc`, `docx2txt`, `antiword`.

## How Moodle invokes the converter

`fileconverter_unoconv` calls the binary with only three command shapes
(`files/converter/unoconv/classes/converter.php`):

```
<pathtounoconv> -f <format> -o <outfile> <infile>   # the conversion
<pathtounoconv> --version                            # probe: needs /unoconv (\d+\.\d+)/ >= 0.7
<pathtounoconv> --show                               # format list, parsed by /\[\.(.*)\]/
```

The path must point at an existing, executable, non-directory file. This makes a
drop-in replacement (shim) feasible.

## Solutions

### Option A — soffice shim at `$CFG->pathtounoconv`  (recommended quick fix)

A small wrapper script that emulates the three unoconv calls Moodle makes but
routes the actual conversion through LibreOffice directly. The existing
`fileconverter_unoconv` plugin then works unchanged.

- **No edit to the system `/usr/bin/unoconv` binary.**
- **No Moodle code change** — only the `pathtounoconv` admin setting changes.
- Reuses the working LibreOffice 26.2; keeps the converter subsystem's caching.

```bash
#!/bin/bash
# /usr/local/bin/unoconv-shim — emulates the unoconv calls Moodle makes
case "$1" in
  --version)
    echo "unoconv 0.9"; exit 0 ;;                  # >= 0.7 passes version_check
  --show)
    printf '[.txt]\n[.docx]\n[.doc]\n[.odt]\n[.pdf]\n[.rtf]\n'; exit 0 ;;
esac
# conversion form:  -f FMT -o OUTFILE INFILE
fmt=""; out=""; in=""
while [ $# -gt 0 ]; do
  case "$1" in
    -f) fmt="$2"; shift 2 ;;
    -o) out="$2"; shift 2 ;;
    *)  in="$1";  shift ;;
  esac
done
outdir=$(dirname "$out")
prof=$(mktemp -d)                                   # unique profile = no lock clash
soffice --headless -env:UserInstallation="file://$prof" \
        --convert-to "$fmt" --outdir "$outdir" "$in" >/dev/null 2>&1
rc=$?
produced="$outdir/$(basename "${in%.*}").$fmt"      # soffice names output by basename
[ "$produced" != "$out" ] && mv -f "$produced" "$out" 2>/dev/null
rm -rf "$prof"
exit $rc
```

Then set admin → *Plugins → Document converters → unoconv path* (or
`$CFG->pathtounoconv`) to `/usr/local/bin/unoconv-shim`.

Trade-offs: still a shell script (not version-controlled with the plugin unless
placed in the repo); for `txt`, the `txt:Text` filter gives cleaner output than
bare `txt`, so special-casing the txt format is advisable. Test before trusting.

### Option B — custom `fileconverter_soffice` plugin  (recommended durable fix)

A small Moodle file-converter plugin that calls `soffice` directly, replacing
unoconv entirely. Moodle-native, version-controlled, shareable across sites.
More effort than the shim, but the proper long-term answer.

### Option C — `local_jomot` self-extraction

Since this plugin owns the use case and only needs `txt` (not the PDF output the
converter subsystem is mainly designed for), bypass `\core_files\converter` and
shell `soffice` directly inside `submission_extractor`. Narrowest blast radius,
but reimplements format handling and caching (note: the
`local_jomot_extract_cache` table already exists).

### Option D — pure-PHP extraction, no external binary

Use `phpoffice/phpword`, or unzip the DOCX and read `word/document.xml`. Most
portable (no LibreOffice dependency), but DOCX/ODT only — legacy `.doc` needs
`catdoc` (present) and PDF is unsupported. A Composer dependency is awkward
inside a Moodle plugin.

### Option E — patch the unoconv binary by hand  (not recommended)

Replace the three `unohelper.absolutize(self.cwd, unohelper.systemPathToFileUrl(X))`
calls with `unohelper.systemPathToFileUrl(os.path.abspath(X))`. Cheapest test of
"is unoconv otherwise usable", but: edits a root-owned system file (overwritten
by any apt reinstall), unversioned, and given the 7-year API gap between unoconv
0.7 and LibreOffice 26.2, it may just surface the next incompatibility.

## Recommendation

- **Now:** Option A (shim) to restore DOCX/ODT/DOC extraction immediately with no
  code change.
- **Durable:** Option B (custom `fileconverter_soffice`) or Option C
  (self-extraction in `local_jomot`) so the fix lives in version control and does
  not depend on the abandoned unoconv project.
