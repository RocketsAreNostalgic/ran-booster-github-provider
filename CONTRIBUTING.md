# Contributing

This package follows the Rockets Are Nostalgic `php-library` engineering baseline.

The canonical local gate requires PHP 8.2+ with Composer and Node **24.11.0**. Node is used only for the maintained release-control scripts/tests; this repository still has no frontend toolchain.

Install only from the tracked lock and run the canonical local gate before opening a pull request:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` for the repository-owned PHP formatting pass and rerun `composer check` afterwards. CI adds the exact certified Booster host-contract proof, mutable PR release-classification evidence, and the terminal `quality` fan-in.

Do not add pnpm, ESLint, Prettier, Stylelint or other frontend tooling unless maintained JavaScript, TypeScript, CSS or SCSS frontend source actually exists. A future frontend surface must adopt the applicable shared RAN quality configuration through an explicit reviewed profile change.

Changes to the provider boundary must remain compatible with the extraction plan in `RocketsAreNostalgic/ran-booster#131`: no production dependency on the whole Booster plugin, no hidden first-party authority, and no unrelated GitHub feature rewrite during migration.

Release-significant changes under `src/` or to production Composer requirements must use a visible release-driving Conventional Commit PR title (`feat`, `fix`, `perf`, `revert`) or an explicit breaking `!`. See `RELEASING.md` for the trusted beta publication flow and the normal-merge exception for generated Release Please version PRs.
