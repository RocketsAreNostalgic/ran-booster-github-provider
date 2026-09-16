# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. Booster bundles and registers it as the first-party GitHub provider, while the implementation is maintained and released independently.

## Status

The package is being established as part of `RocketsAreNostalgic/ran-booster#131`. The foundation PR intentionally contains no migrated GitHub implementation yet; implementation and implementation-owned tests move in the next extraction phase.

## Contract boundary

The package may consume Booster's public provider contracts supplied by the host at runtime, but it must not take a production Composer dependency on the whole `ran/booster` plugin. Shared non-host utilities are explicit Composer dependencies.

The current provider contract is pre-release. The package targets the current Booster contract rather than promising arbitrary compatibility with historical pre-release Booster builds.

## Quality profile

This repository follows the same PHP-library family as `ran/updater-support`, `ran/wp-branch-updater`, and `ran/wp-release-updater`: `composer check` is the ordinary deterministic package gate; PHPCS/PHPCBF own PHP style and formatting; PHPCompatibility and WordPress-aware PHPStan cover the supported runtime surface; CI consumes the immutable organisation PHP-v2 reusable workflow and adds package-specific evidence.

The implementation being extracted is PHP-only, so Node is deliberately disabled rather than adding a synthetic frontend toolchain. Starter remains a useful repository-ergonomics reference, but its ESLint/Prettier/Stylelint surface applies only when maintained frontend source exists.

If maintained JavaScript, TypeScript, CSS or SCSS source is introduced later, the frontend quality surface must be adopted deliberately through the shared RAN quality configuration.

## Development

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` to apply the repository PHPCBF formatter. `composer check` validates Composer metadata, PHP syntax, PHPCS/WPCS/PHPCompatibility, static analysis and the package-foundation contract. CI additionally checks the exact current Booster host-contract signatures and exposes one terminal `quality` fan-in.

Organization quality policy and reusable-workflow lifecycle are tracked in `RocketsAreNostalgic/.github#7`, `#15` and `#12`; release-publisher trust/classification is separate and will be adopted when package publishing is enabled.
