# Contributing

This package follows the Rockets Are Nostalgic PHP-library engineering baseline.

Before opening a pull request, run:

```bash
composer install
composer check
```

Changes to the provider boundary must remain compatible with the current extraction plan in `RocketsAreNostalgic/ran-booster#131`: no production dependency on the whole Booster plugin, no hidden first-party authority, and no unrelated GitHub feature rewrite during migration.
