# AGENTS.md

## Project contract

This repository is the first-party GitHub provider package for RAN Booster. It is a Composer **library**, not a WordPress plugin. Booster bundles an immutable released version as its first-party GitHub distribution; preserve the package boundary and established behavior rather than treating bundled status as permission for host-specific shortcuts or unrelated feature rewrites.

The supported baseline follows the current Booster host: PHP 8.2+ and WordPress 7.0+. Keep `composer.json`, `.phpcs.xml`, PHPStan, CI and documentation aligned when that support contract changes.

## Architecture boundary

Production package code must not depend on the whole `ran/booster` Composer package or import Booster private Admin/Internal/Logging/Secrets/Storage/WordPress implementation namespaces. Booster owns the provider contracts and host orchestration; this package implements the GitHub-specific side of those contracts. Test-only CI may check out an exact certified Booster revision to prove the host contract.

The package may depend on explicit shared libraries where the dependency is genuinely host-neutral. `ran/updater-support` currently supplies the reviewed repository-relative path primitive. Core remains responsible for host policy and final release-artifact custody.

## RAN quality profile

The repository profile is `php-library`, using the current organisation `quality-php-library-v2.yml` provider at an immutable reviewed SHA.

For package conventions, prefer the closest maintained Booster support libraries as references: `ran/updater-support`, `ran/wp-branch-updater`, and `ran/wp-release-updater`. Use Booster and `ran-starter-plugin` for stronger transferable guarantees and repository ergonomics, but do not copy plugin-only runtime, archive or frontend machinery into this library without an applicable source/product requirement.

The provider implementation has no maintained JavaScript, TypeScript, CSS or SCSS frontend source. Node **24.11.0** is intentionally present and required for the maintained release-control surface: workflow-contract and release-classification scripts/tests. Do not add pnpm, ESLint, Prettier or Stylelint merely for symmetry. If maintained frontend source is introduced later, reclassify the quality surface deliberately and adopt the applicable shared `@rocketsarenostalgic/quality-config` entry points at that time.

PHP quality derives from `ran/coding-standards` through `RANWordPressLibrary`, with support range, namespace/prefix and extraction-specific exceptions kept local. PHPCS is the authoritative style check, PHPCBF is the formatter, and PHPStan is WordPress-aware because provider implementation code uses WordPress APIs. Do not introduce a second PHP formatter merely to mirror a plugin repository.

The ordinary host-independent deterministic local gate requires PHP 8.2+ with Composer and Node 24.11.0:

```sh
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer standards:fix` to apply PHPCBF. `composer check` covers strict Composer validation, PHP syntax, PHPCS/WPCS/PHPCompatibility, the package-owned deterministic foundation contract, and the release workflow/classification contracts.

The implementation consumes Booster-owned provider contracts, so implementation static analysis and the provider PHPUnit suite are deliberately certified against an exact Booster checkout rather than by adding the whole Booster plugin as a package dependency. For an equivalent local host-backed pass, set `RAN_BOOSTER_CORE_PATH` to the certified Booster checkout and run:

```sh
export RAN_BOOSTER_CORE_PATH=/path/to/ran-booster
# Match the certified host pinned in .github/workflows/ci.yml.
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = ffc11fc8e40618624a785b7fca5193029c6d492e &&
  composer check:host
```

`composer test` aggregates the host-independent foundation and release-control tests. `composer check:host` aggregates `test:host-contract`, `analyze` and `test:implementation`; the focused commands remain available.

CI pins and verifies the certified Booster revision before running those host-backed gates, separately verifies the host contract, validates mutable PR release classification, and exposes one terminal `quality` fan-in. Do not make the host-independent `composer check` gate depend implicitly on an unverified local Booster checkout.

## Review and merge discipline

Review evidence is revision-specific. Every inline review finding must receive a written disposition and be explicitly resolved, including stale, superseded or not-applicable comments. Review-summary findings without inline threads must still receive an explicit PR-conversation disposition before merge. Do not merge without explicit owner authorization.

Use Conventional Commits. Changes under `src/` or to production Composer requirements are release-significant and must use a visible provider release-driving type (`feat`, `fix`, `perf`, `revert`) or an explicit breaking `!`; PR-title edits rerun the required classification gate. Classification must use merge-base-to-head changes, not the moving base-branch tip, so unrelated `main` changes cannot be attributed to an older PR.

The repository release path follows the owner-approved organisation Profile A contract in `RocketsAreNostalgic/.github#44/#47`. The local `release-please.yml` is a thin caller pinned to an approved organisation revision. Release Please owns generic version/changelog/release-PR/tag/release lifecycle; this repository retains only its package-specific release-significance classification and host-contract evidence.

The canonical `CI` supports input-free `workflow_dispatch` solely so shared Profile A can qualify an exact bot-owned Release Please PR head when `GITHUB_TOKEN` suppresses ordinary PR events. That dispatch must fail closed unless the ref resolves to exactly one canonical bot-owned release PR and the same PR-title classification is evaluated before terminal `quality` succeeds.

Do not manually create/move release tags, bypass failed release checks, or reintroduce repository-local publisher/replay/lifecycle state. See `RELEASING.md`.

## Agent/tooling boundary

Do not invoke Blacksmith [code]smith or Autofix/AI-agent features. Blacksmith may be used only as ordinary GitHub Actions runner infrastructure when a reviewed workflow selects it. Diagnose CI from GitHub Actions evidence directly.
