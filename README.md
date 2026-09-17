# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. Booster bundles and registers it as the first-party GitHub provider, while the implementation is maintained and released independently.

## Status

The package is being extracted under `RocketsAreNostalgic/ran-booster#131`. The GitHub provider implementation and its provider-owned tests are now present in this repository and are being certified against the exact Booster source from which they were extracted.

The package has not yet reached the release/cutover phases: an immutable beta release and the subsequent Booster dependency/runtime cutover remain separate work after extraction parity and review are complete.

## Contract boundary

The package may consume Booster's public provider contracts supplied by the host at runtime, but it must not take a production Composer dependency on the whole `ran/booster` plugin. Shared non-host utilities are explicit Composer dependencies.

The current provider contract is pre-release. The package targets the current Booster contract rather than promising arbitrary compatibility with historical pre-release Booster builds.

## Quality profile

This repository follows the same PHP-library family as `ran/updater-support`, `ran/wp-branch-updater`, and `ran/wp-release-updater`: `composer check` is the ordinary host-independent deterministic package gate; PHPCS/PHPCBF own PHP style and formatting; PHPCompatibility covers the supported runtime surface; and CI consumes the immutable organisation PHP-v2 reusable workflow and adds package-specific evidence.

The migrated implementation's WordPress-aware PHPStan analysis and PHPUnit suite are host-backed because the provider contracts remain Booster-owned. CI checks out and verifies the exact certified Booster revision before running those gates instead of introducing a development or production dependency on the whole Booster plugin.

The implementation being extracted is PHP-only, so Node is deliberately disabled rather than adding a synthetic frontend toolchain. Starter remains a useful repository-ergonomics reference, but its ESLint/Prettier/Stylelint surface applies only when maintained frontend source exists.

If maintained JavaScript, TypeScript, CSS or SCSS source is introduced later, the frontend quality surface must be adopted deliberately through the shared RAN quality configuration.

## Development

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` to apply the repository PHPCBF formatter. `composer check` validates Composer metadata, PHP syntax, PHPCS/WPCS/PHPCompatibility and the package-foundation contract.

To run the migrated implementation analysis and tests locally, point `RAN_BOOSTER_CORE_PATH` at the certified Booster checkout:

```bash
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer analyze
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer test:implementation
```

CI additionally verifies the exact current Booster host-contract signatures, runs those host-backed implementation gates on PHP 8.2 and 8.5, and exposes one terminal `quality` fan-in.

Organization quality policy and reusable-workflow lifecycle are tracked in `RocketsAreNostalgic/.github#7`, `#15` and `#12`; release-publisher trust/classification is separate and will be adopted when package publishing is enabled.
