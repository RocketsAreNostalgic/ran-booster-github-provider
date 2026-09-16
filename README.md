# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. Booster bundles and registers it as the first-party GitHub provider, while the implementation is maintained and released independently.

## Status

The package is being established as part of `RocketsAreNostalgic/ran-booster#131`. The foundation PR intentionally contains no migrated GitHub implementation yet; implementation and implementation-owned tests move in the next extraction phase.

## Contract boundary

The package may consume Booster's public provider contracts supplied by the host at runtime, but it must not take a production Composer dependency on the whole `ran/booster` plugin. Shared non-host utilities are explicit Composer dependencies.

The current provider contract is pre-release. The package targets the current Booster contract rather than promising arbitrary compatibility with historical pre-release Booster builds.

## Quality profile

This repository uses the RAN `php-library` profile and the current immutable `quality-php-library-v2.yml` provider. The implementation being extracted is PHP-only, so Node is deliberately disabled rather than adding a synthetic frontend toolchain.

PHP source quality derives from `ran/coding-standards` (`RANWordPressLibrary`), PHP CS Fixer, PHPCompatibility and WordPress-aware PHPStan. Repository-local configuration retains the actual PHP/WordPress support range, namespace and extraction-specific exceptions.

If maintained JavaScript, TypeScript, CSS or SCSS source is introduced later, the frontend quality surface must be adopted deliberately through the shared RAN quality configuration; this foundation does not add ESLint/Prettier/Stylelint when there is no frontend source to check.

## Development

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

`composer check` validates Composer metadata, formatter parity, PHPCS/WPCS/PHPCompatibility, static analysis, the package-foundation contract and PHP syntax. CI additionally checks the exact current Booster host-contract signatures and exposes one terminal `quality` fan-in.

Organization quality policy and reusable-workflow lifecycle are tracked in `RocketsAreNostalgic/.github#7`, `#15` and `#12`; release-publisher trust/classification is separate and will be adopted when package publishing is enabled.
