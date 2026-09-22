# Bulletin parser regression suite

This suite protects the human-reviewed bulletin parser from regressions as new PDF layouts and edge cases are added.

Each directory under `tests/fixtures/` contains:

- `bulletin.pdf` - the original bulletin PDF used as parser input (preferred).
- `bulletin.txt` - optional human-reviewed transcription used only when a binary PDF cannot be committed by automation; the runner wraps it in a deterministic PDF so the real PDF library is still exercised.
- `expected.json` - the manually reviewed semantic result. Do **not** generate this file from current parser output; the bulletin itself is the source of truth.

Run locally with:

```bash
composer install --no-dev
php tests/regression/run.php
```

The GitHub test-build workflow runs the suite after PHP lint and before creating the installable plugin ZIP. A regression therefore stops the build before an artifact is published.

## What is compared

The fixture format can assert:

- recurring schedule candidates;
- the complete set of dated Mass rows and intentions;
- required prayer/sacrament rows;
- required parish-event rows;
- weekly row counts;
- livestream text;
- review-warning text.

Debug/source-line diagnostics are intentionally excluded from equality checks because they are useful implementation details rather than website data.

## Adding a fixture

1. Create `tests/fixtures/YYYY-MM-DD-short-name/`.
2. Copy the original bulletin to `bulletin.pdf` whenever possible. Use `bulletin.txt` only as a connector-friendly fallback.
3. Read the bulletin independently and create `expected.json` from the human-reviewed answer.
4. Run `php tests/regression/run.php`.
5. If the parser disagrees, decide whether the parser is wrong or the hand-reviewed expectation is wrong before changing either one.

Prefer a small, diverse corpus over many near-duplicate bulletins: summer/school-year changes, funerals, Holy Days, `NO MASS`, off-site Masses, multiple Masses on one date, unusual intentions, and date/day discrepancies are especially valuable.

The `tests/` tree is excluded from the installable WordPress ZIP, so fixtures are build-time assets only.
