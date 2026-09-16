# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. Booster bundles and registers it as the first-party GitHub provider, while the implementation is maintained and released independently.

## Status

The package is being established as part of `RocketsAreNostalgic/ran-booster#131`. The initial foundation intentionally contains no migrated GitHub implementation yet; implementation and implementation-owned tests move in the next extraction phase.

## Contract boundary

The package may consume Booster's public provider contracts supplied by the host at runtime, but it must not take a production Composer dependency on the whole `ran/booster` plugin. Shared non-host utilities are explicit Composer dependencies.

The current provider contract is pre-release. The package targets the current Booster contract rather than promising arbitrary compatibility with historical pre-release Booster builds.

## Development

```bash
composer install
composer check
```

`composer check` validates the package metadata, coding standards, static analysis, and the passive-library foundation contract. CI also checks the package against the exact Booster host commit recorded in the workflow before implementation migration begins.
