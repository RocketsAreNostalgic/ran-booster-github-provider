# Contributing

This package follows the Rockets Are Nostalgic `php-library` engineering baseline.

Install only from the tracked lock and run the canonical local gate before opening a pull request:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` for the repository-owned PHP formatting pass and rerun `composer check` afterwards. CI adds the exact certified Booster host-contract proof and the terminal `quality` fan-in.

Do not add Node/frontend tooling unless maintained JavaScript, TypeScript, CSS or SCSS source actually exists. A future frontend surface must adopt the applicable shared RAN quality configuration through an explicit reviewed profile change.

Changes to the provider boundary must remain compatible with the extraction plan in `RocketsAreNostalgic/ran-booster#131`: no production dependency on the whole Booster plugin, no hidden first-party authority, and no unrelated GitHub feature rewrite during migration.
