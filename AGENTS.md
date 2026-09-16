# AGENTS.md

## Project contract

This repository is the first-party GitHub provider package for RAN Booster. It is a Composer **library**, not a WordPress plugin. The package is extracted from Booster under `RocketsAreNostalgic/ran-booster#131`; preserve behaviour during that migration rather than using extraction as a feature rewrite.

The supported baseline follows the current Booster host: PHP 8.2+ and WordPress 7.0+. Keep `composer.json`, `.phpcs.xml`, PHPStan, CI and documentation aligned when that support contract changes.

## Architecture boundary

Production package code must not depend on the whole `ran/booster` Composer package or import Booster private Admin/Internal/Logging/Secrets/Storage/WordPress implementation namespaces. Booster owns the provider contracts and host orchestration; this package implements the GitHub-specific side of those contracts. Test-only CI may check out an exact certified Booster revision to prove the host contract.

The package may depend on explicit shared libraries where the dependency is genuinely host-neutral. `ran/updater-support` currently supplies the reviewed repository-relative path primitive. Core remains responsible for host policy and final release-artifact custody.

## RAN quality profile

The repository profile is `php-library`, using the current organisation `quality-php-library-v2.yml` provider at an immutable reviewed SHA.

For package conventions, prefer the closest maintained Booster support libraries as references: `ran/updater-support`, `ran/wp-branch-updater`, and `ran/wp-release-updater`. Use Booster and `ran-starter-plugin` for stronger transferable guarantees and repository ergonomics, but do not copy plugin-only runtime, archive, frontend or publication machinery into this library without an applicable source/product requirement.

The extracted implementation currently has no maintained JavaScript, TypeScript, CSS or SCSS source. Do not add Node, pnpm, ESLint, Prettier or Stylelint merely for symmetry. If maintained frontend source is introduced later, reclassify the quality surface deliberately and adopt the applicable shared `@rocketsarenostalgic/quality-config` entry points at that time.

PHP quality derives from `ran/coding-standards` through `RANWordPressLibrary`, with support range, namespace/prefix and extraction-specific exceptions kept local. PHPCS is the authoritative style check, PHPCBF is the formatter, and PHPStan is WordPress-aware because provider implementation code uses WordPress APIs. Do not introduce a second PHP formatter merely to mirror a plugin repository.

The ordinary deterministic local gate is:

```sh
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` to apply PHPCBF. `composer check` must cover strict Composer validation, PHP syntax, PHPCS/WPCS/PHPCompatibility, static analysis and package-owned deterministic contract tests. CI adds the exact Booster host-contract proof and a terminal `quality` fan-in.

## Review and merge discipline

Review evidence is revision-specific. Every inline review finding must receive a written disposition and be explicitly resolved, including stale, superseded or not-applicable comments. Review-summary findings without inline threads must still receive an explicit PR-conversation disposition before merge. Do not merge without explicit owner authorization.

Use Conventional Commits. Release Please metadata is present for the future beta series, but no privileged publisher should be enabled until release work is ready. When publication is enabled, reconcile the repository with the current organisation release-trust/classification/workflow-run workstreams in `RocketsAreNostalgic/.github` rather than copying an older publisher blindly.

## Agent/tooling boundary

Do not invoke Blacksmith [code]smith or Autofix/AI-agent features. Blacksmith may be used only as ordinary GitHub Actions runner infrastructure when a reviewed workflow selects it. Diagnose CI from GitHub Actions evidence directly.
