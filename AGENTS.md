# AGENTS.md

`mirafive/sdk-php`: the PHP SDK of MIRA FIVE. The public surface is fixed by the `mirafive/sdk-php` section of
`API.md` in the protocol repository; the wire by `PROTOCOL.md`, flag semantics by `FLAGS.md`. Change those first.

## Commands

```bash
composer install
composer check      # pint --test, phpstan (level max), pest
composer lint       # pint, fixes formatting
composer analyse    # phpstan
composer test       # pest
```

## Rules

- PHP 8.3 syntax and functions only: no property hooks, asymmetric visibility, pipe operator, `new` chained without
  parentheses, `array_any`/`array_all`/`array_find`, `mb_trim`. CI runs 8.3, 8.4 and 8.5.
- No required Composer dependencies. PSR interfaces are `suggest` only and must not be needed unless used.
- `track()` and `flush()` never throw for transport reasons; failures go through `Reporter`. Programming errors
  (bad input, identifiers in consentless mode) throw `InvalidArgumentException`.
- Retries resend the byte-identical body; never rebuild a batch between attempts.
- `tests/Fixtures/*.json` are byte-identical copies from the protocol repository (`fixtures/`). Never edit them:
  recopy and update the sha256 pins in `tests/Unit/FixturesTest.php`.
- The evaluator is pure and must pass every case in `flag-eval.cases.json`; behaviour changes start in the shared
  fixtures, not here.
- Tests never touch the network: use `tests/Support/FakeTransport`.
- Comments only for non-obvious constraints, one or two lines.

## Releasing

To release, bump `Mira::VERSION` in `src/Mira.php`, add a `## X.Y.Z — YYYY-MM-DD` section to `CHANGELOG.md`, commit, then `git tag vX.Y.Z && git push origin vX.Y.Z`. `.github/workflows/release.yml` checks the version and the changelog, runs `composer check` and creates the GitHub release; Packagist picks the tag up by itself.
