# Contributing

This package follows the Rockets Are Nostalgic `php-library` engineering baseline.

The canonical local gate requires PHP 8.2+ with Composer and Node **24.11.0**. Node is used only for the maintained release-control scripts/tests; this repository still has no frontend toolchain.

Install only from the tracked lock and run the canonical local gate before opening a pull request:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use these focused commands:

| Command | Scope |
| --- | --- |
| `composer lint:syntax` | Parser checks for `src/` and `tests/` |
| `composer standards` | Shared PHPCS/WPCS/PHPCompatibility rules |
| `composer standards:fix` | PHPCBF with the same rules and source paths |
| `composer test` | Host-independent foundation and release-control tests |
| `composer check:host` | Host contract, blocking level-5 analysis and implementation PHPUnit |

After `composer check`, run the required host-backed aggregate using the exact
certified Booster checkout pinned in [CI](.github/workflows/ci.yml):

```bash
export RAN_BOOSTER_CORE_PATH=/path/to/ran-booster
# Match the certified host pinned in .github/workflows/ci.yml.
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = 8a3ed5a8acdb3875f498e2f44bf9eba89fddbbaf &&
  composer check:host
```

This exact host is the immutable Core `v1.0.0-beta.31` tag target, supplying
Provider API 14 / workflow V3. PHPStan blocks at level 5 over `src/` and
`tests/foundation-contract.php`, with unchanged WordPress extension, bootstrap
files and PHPDoc certainty setting. No baseline or ignore list is introduced.
Levels 6–8 remain separately scoped. Source-host qualification does not establish
installed-site or UI acceptance. Update the SHA example when the authoritative
CI tuple changes.

`composer analyze`, `composer test:host-contract` and
`composer test:implementation` remain available for focused host-backed checks.
The old `lint:php` command is now `standards`; `format` / `format:php` are now
`standards:fix`. Rerun `composer check` after formatting.

CI retains its separate host-contract and implementation lanes, verifies the
exact certified host revision, and requires both through terminal `quality`.
The implementation matrix invokes the same `composer check:host` as local
contributors; PR release classification remains separately required.

Do not add pnpm, ESLint, Prettier, Stylelint or other frontend tooling unless maintained JavaScript, TypeScript, CSS or SCSS frontend source actually exists. A future frontend surface must adopt the applicable shared RAN quality configuration through an explicit reviewed profile change.

Changes to the provider boundary must preserve the standing package contract:
no production dependency on the whole Booster plugin, no hidden first-party
authority, no imports of Booster private implementation namespaces, and no
unrelated GitHub feature rewrite merely because the package is bundled by
Booster.

Release-significant changes under `src/` or to production Composer requirements must use a visible release-driving Conventional Commit PR title (`feat`, `fix`, `perf`, `revert`) or an explicit breaking `!`. See `RELEASING.md` for the shared Profile A beta publication flow. Generated Release Please version PRs follow the repository's approved merge policy; publication no longer depends on a special normal-merge geometry.

## Naming migration

All first-party PHP under `src/` and `tests/` uses the shared `RANOwnedMethods`
check and WPCS variable naming, including inherited/interface classes and newly
added files. `ran/coding-standards` remains pinned to released 1.0.0; check and
fix use the same scope. Upstream method-name diagnostics are replaced by the
owned-method check so explicit public-contract deferrals work consistently.

Yoda conditions and reserved-parameter naming are now enforced without broad
migration suppressions. Only genuine foreign WordPress/updater/native signatures may retain
precise line-local deferrals. Owned Core API14 parameters and DTO fields now
use snake_case, including connected named arguments. Fixture-owned identifiers
and their connected callers now use snake_case, including named arguments.

The accepted Provider-owned helper tranche migrates 52 methods, 64 camelCase
parameter occurrences (including three private promotions) and four reserved
parameters, with connected callers and Core consumers. This intentionally
breaks the old beta PHP API; no mixed old/new tuple compatibility is claimed.
The earlier API13 tranche migrated all 50 Core-interface implementation methods.
The current API14 recovery completes public parameters, properties, promotions
and connected callers with the matching Core declarations. Persisted/template keys,
credentials and runtime protocols are unchanged. See [the migration ledger](docs/naming-migration.md) and
[the proposed Core contract manifest](docs/naming-core-contract-manifest.md).
