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
| `composer check:host` | Host contract, blocking level-1 analysis and implementation PHPUnit |

After `composer check`, run the required host-backed aggregate using the exact
candidate Booster checkout pinned in [CI](.github/workflows/ci.yml):

```bash
export RAN_BOOSTER_CORE_PATH=/path/to/ran-booster
# Match the candidate host pinned in .github/workflows/ci.yml.
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = 18b0ec619174000a9a9dbc27b9d68b44b0265449 &&
  composer check:host
```

This is candidate-only Provider API 12 / workflow V3 qualification, not
certification against a released Core host. The SHA above mirrors the existing
CI configuration; update the example when that authoritative tuple changes,
not the certification pin to match prose.

`composer analyze`, `composer test:host-contract` and
`composer test:implementation` remain available for focused host-backed checks.
The old `lint:php` command is now `standards`; `format` / `format:php` are now
`standards:fix`. Rerun `composer check` after formatting.

CI retains its separate host-contract and implementation lanes, verifies the
exact candidate host revision, and requires both through terminal `quality`.
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

The nine audited top-level Provider classes use the shared `RANOwnedMethods`
check, including classes implementing Core interfaces, and WPCS variable naming.
`ran/coding-standards` is pinned to released 1.0.0; check and fix use the same scope.
Declaration/use-local deferrals retain public named arguments, promoted properties
and Core DTO members until their coordinated caller cohort. They are migration
debt, not external-signature exemptions. Do not extend them to new private code.
WorkflowAssistance and test naming remain separate cohorts. See
[the migration ledger](docs/naming-migration.md) and
[the proposed Core contract manifest](docs/naming-core-contract-manifest.md).
