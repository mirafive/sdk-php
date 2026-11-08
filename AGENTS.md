# Working on mirafive/sdk-php

Part of the MIRA FIVE SDKs (github.com/mirafive). Read README.md for what the package does.

## Commands

```sh
composer install
composer check    # Pint, PHPStan (max), Pest: exactly what CI runs on PHP 8.3 and 8.5
```

`php tests/contract/emit.php | npx --yes @mirafive/protocol` checks what the SDK sends against the
ingest's rules (CI does it too).
## Rules

- No required Composer dependencies besides PHP; PSR packages are suggestions.
- Event methods never throw into the host and never hold a request: report problems instead.
- A secret key (`mira_sk_…`) never reaches a template; a public key (`mira_pk_…`) is refused
  on the server.
- The wire format is defined by `@mirafive/protocol` (github.com/mirafive/protocol). The Node SDK
  is the reference for behaviour; `eventIdFor` must match its vectors.
- Tests for every change. Comments explain why, briefly. Conventional Commits.

## Releasing

Add a `## x.y.z — YYYY-MM-DD` section to CHANGELOG.md and bump `Mira::VERSION` and merge to main: the
release workflow tags `vx.y.z` and creates the GitHub release; Packagist reads the tag.
