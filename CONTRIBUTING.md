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
certified Booster checkout:

```bash
export RAN_BOOSTER_CORE_PATH=/path/to/ran-booster
# Match the certified host pinned in .github/workflows/ci.yml.
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = ffc11fc8e40618624a785b7fca5193029c6d492e &&
  composer check:host
```

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
