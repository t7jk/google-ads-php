# 7. Own test runner, linters as phars, still no Composer

Status: accepted (2026-11)

## Context
The audit (findings 6 and 7) found too few tests, one test that wrote to the dev database, and no linter or static analysis.
ADR 0004 rules out Composer for the application: there is no shell on the hosting and `vendor/` over FTP is slow and hard to audit.
PHPUnit and Pest would bring exactly that dependency tree, for a suite that today is a few hundred plain checks.

## Decision
- Tests stay plain PHP scripts that print `OK`/`FAIL` lines. `tests/run.php` (about 70 lines) is the whole framework: it finds
  `tests/*.php`, runs each file in its own process with a throwaway data directory (`GADS_DATA_DIR`) and sums the results.
  One process per file isolates global state (static classes, superglobals, constants such as `DATA_PATH`) which in-process
  test cases could not reset. Tests that need the network or Google (`compare.php`, `plans-d2.php`) are excluded by name.
- Pint (style, `pint.json`) and PHPStan (`phpstan.neon`, level 3 with a baseline) run from phars downloaded by
  `tools/get-dev-tools.sh` into the git-ignored `tools/bin/`. They are used only in CI and on developer machines; nothing is added to the app.
- The Pint rules that contradict the existing style are switched off instead of rewriting the repository; the baseline holds the
  older PHPStan findings (mostly array-shape docblocks) so that new code is held to the standard from day one.

## Consequences
- No dependency to update or audit in the application; the tools themselves are unpinned ("latest" phar), so a new release can add findings.
- Moving to Laravel later: the checks translate to Pest/PHPUnit tests easily, and `pint.json` is already Pint's own format.
- Views are excluded from PHPStan (variables arrive through `extract()`), and levels above 3 are not clean yet; raise the level
  gradually and shrink the baseline when touching old code.

## Alternatives considered
PHPUnit via a phar: heavier, and its process-per-test isolation needs configuration we get for free. Composer only for `require-dev`:
possible later, but would put a `vendor/` next to the deployed `app/` and invite its use at runtime.
