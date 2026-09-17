# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. Booster bundles and registers it as the first-party GitHub provider, while the implementation is maintained and released independently.

## Status

The package is being extracted under `RocketsAreNostalgic/ran-booster#131`. The GitHub provider implementation and its provider-owned tests are present in this repository and certified against the exact Booster source from which they were extracted.

Phase 4 release work is enabling the package's trusted prerelease publisher and preparing the first immutable `v0.1.0-beta.1` release. Booster dependency/runtime cutover remains a separate later phase and must consume a verified immutable package release rather than `dev-main`.

## Contract boundary

The package may consume Booster's public provider contracts supplied by the host at runtime, but it must not take a production Composer dependency on the whole `ran/booster` plugin. Shared non-host utilities are explicit Composer dependencies.

The current provider contract is pre-release. The package targets the current Booster contract rather than promising arbitrary compatibility with historical pre-release Booster builds.

## Quality profile

This repository follows the same PHP-library family as `ran/updater-support`, `ran/wp-branch-updater`, and `ran/wp-release-updater`: `composer check` is the ordinary host-independent deterministic package gate; PHPCS/PHPCBF own PHP style and formatting; PHPCompatibility covers the supported runtime surface; and CI consumes the immutable organisation PHP-v2 reusable workflow and adds package-specific evidence.

The migrated implementation's WordPress-aware PHPStan analysis and PHPUnit suite are host-backed because the provider contracts remain Booster-owned. CI checks out and verifies the exact certified Booster revision before running those gates instead of introducing a development or production dependency on the whole Booster plugin.

The provider implementation remains PHP-only and has no maintained frontend source. Node is nevertheless part of the repository quality profile for the maintained release-control surface: publisher, workflow-contract, and release-classification scripts/tests run on the repository-pinned Node version. This does not introduce a frontend toolchain; pnpm, ESLint, Prettier and Stylelint remain unnecessary unless maintained frontend source is added later.

If maintained JavaScript, TypeScript, CSS or SCSS frontend source is introduced later, the frontend quality surface must be adopted deliberately through the shared RAN quality configuration.

## Development

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` to apply the repository PHPCBF formatter. `composer check` validates Composer metadata, PHP syntax, PHPCS/WPCS/PHPCompatibility, the package-foundation contract, and the repository-owned release publisher/classification contracts.

To run the migrated implementation analysis and tests locally, point `RAN_BOOSTER_CORE_PATH` at the certified Booster checkout:

```bash
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer analyze
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer test:implementation
```

CI additionally verifies the exact current Booster host-contract signatures, runs those host-backed implementation gates on PHP 8.2 and 8.5, checks mutable PR release classification, and exposes one terminal `quality` fan-in.

Release operations and trust boundaries are documented in `RELEASING.md`. Organisation quality policy is tracked in `RocketsAreNostalgic/.github#7`, `#15` and `#12`; release trust/classification/admission are tracked under `.github#9`, `#20` and `#22`.
