# AGENTS.md

## Project contract

This repository is the first-party GitHub provider package for RAN Booster. It is a Composer **library**, not a WordPress plugin. Booster bundles an immutable released version as its first-party GitHub distribution; preserve the package boundary and established behavior rather than treating bundled status as permission for host-specific shortcuts or unrelated feature rewrites.

The supported baseline follows the current Booster host: PHP 8.2+ and WordPress 7.0+. Keep `composer.json`, `.phpcs.xml`, PHPStan, CI and documentation aligned when that support contract changes.

## Architecture boundary

Production package code must not depend on the whole `ran/booster` Composer package or import Booster private Admin/Internal/Logging/Secrets/Storage/WordPress implementation namespaces. Booster owns the provider contracts and host orchestration; this package implements the GitHub-specific side of those contracts. Test-only CI may check out an exact certified Booster revision to prove the host contract.

The package may depend on explicit shared libraries where the dependency is genuinely host-neutral. `ran/updater-support` currently supplies the reviewed repository-relative path primitive. Core remains responsible for host policy and final release-artifact custody.

## RAN quality profile

The repository profile is `php-library`. Each PHP matrix job provisions once,
runs the complete independent baseline before introducing exact certified Core,
then runs the host aggregate. `.github/workflows/ci.yml` and
`scripts/ci-quality-phase.sh` own this Provider-specific sequence and its drift
controls. Its baseline preserves `quality-php-library-v2.yml` at immutable
`788f783d2998994f7aab9691710911ed1bd762c9`; review later baseline changes deliberately.
The independent host-contract, classification and terminal `quality` gates remain.
See [`docs/ci-provisioning-pilot.md`](docs/ci-provisioning-pilot.md) for measurements,
shared-runner limitations and rollback. No shared-recipe pin is required.

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
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = 8a3ed5a8acdb3875f498e2f44bf9eba89fddbbaf &&
  composer check:host
```

`composer test` aggregates the host-independent foundation and release-control tests. `composer check:host` aggregates `test:host-contract`, `analyze` and `test:implementation`; the focused commands remain available.

CI pins and verifies the certified Booster revision before running those host-backed gates, separately verifies the host contract, validates mutable PR release classification, and exposes one terminal `quality` fan-in. Do not make the host-independent `composer check` gate depend implicitly on an unverified local Booster checkout.

The certified source host is the immutable Core `v1.0.0-beta.31` tag target
`8a3ed5a8acdb3875f498e2f44bf9eba89fddbbaf`, with Provider API 14 / workflow V3.
PHPStan blocks at level 5 over both default-inclusive production and development profiles.
The original production profile keeps test declarations isolated; the complementary
development profile discovers the root and excludes only src (analyzed by production),
dependencies and caches. The union covers every maintained PHP file, including tests
and maintenance scripts. Certified Core is scanned for development types without
executing the bounded runtime loader; bootstrap/symbol discovery alone is not analysis. Levels 6–8
remain separate work. This source-host proof does not claim installed-site or
UI acceptance. Preserve the full PHP 8.2/8.5 matrix and all terminal gates.

## Review and merge discipline

Review evidence is revision-specific. Every inline review finding must receive a written disposition and be explicitly resolved, including stale, superseded or not-applicable comments. Review-summary findings without inline threads must still receive an explicit PR-conversation disposition before merge. Do not merge without explicit owner authorization.

Use Conventional Commits. Changes under `src/` or to production Composer requirements are release-significant and must use a visible provider release-driving type (`feat`, `fix`, `perf`, `revert`) or an explicit breaking `!`; PR-title edits rerun the required classification gate. Classification must use merge-base-to-head changes, not the moving base-branch tip, so unrelated `main` changes cannot be attributed to an older PR.

The repository release path follows the owner-approved organisation Profile A contract in `RocketsAreNostalgic/.github#44/#47`. The local `release-please.yml` is a thin caller pinned to an approved organisation revision. Release Please owns generic version/changelog/release-PR/tag/release lifecycle; this repository retains only its package-specific release-significance classification and host-contract evidence.

The canonical `CI` supports input-free `workflow_dispatch` solely so shared Profile A can qualify an exact bot-owned Release Please PR head when `GITHUB_TOKEN` suppresses ordinary PR events. That dispatch must fail closed unless the ref resolves to exactly one canonical bot-owned release PR and the same PR-title classification is evaluated before terminal `quality` succeeds.

Do not manually create/move release tags, bypass failed release checks, or reintroduce repository-local publisher/replay/lifecycle state. See `RELEASING.md`.

## Agent/tooling boundary

Do not invoke Blacksmith [code]smith or Autofix/AI-agent features. Blacksmith may be used only as ordinary GitHub Actions runner infrastructure when a reviewed workflow selects it. Diagnose CI from GitHub Actions evidence directly.

The actual producer exchange regression also executes the generated build and
verification scripts in disposable local Git repositories for both package types,
then compares two generated ZIPs byte-for-byte. Host-backed tests therefore need
Bash, Git, jq, zip, unzip and shasum alongside PHP/ZipArchive. No remote repository
or installed site is modified by these tests. The runtime allowlist contains only
sorted explicit paths; human guidance lives in RELEASE-STARTER.md.

Production analysis starts at the repository root. Reviewed root fixture roles
(`tests/Booster`, `tests/Support`, `tests/fixtures`, host-contract and discovery
helper), scripts, dependencies and caches are excluded from analysis and scanning.
The foundation contract stays directly analyzed. Independent recursive discovery
must match locked FileFinder plus CLI stub-file removal. The host aggregate
proves new root/nested/split/moved sources, src/tests collisions, unsupported
extensions, production-stub rejection and excluded-fixture scan isolation.
No existing production omission or analysis-level change is claimed.

`analyze` runs both profiles; `check:host` retains the certified-host contract first.
The effective-selection guard compares each profile with independent discovery,
rejects level/scope/command reductions, and proves new root, test and script files
produce real diagnostics. Only three exact source-local locked-PHPStan API warning
annotations are retained in the coverage helper; moving, duplicating or broadening
them fails the existing guard, and an adjacent API call remains diagnosed. They do
not suppress semantic errors. One declaration-local `return.unusedType` exception
preserves the success-only `wp_http_validate_url` fixture's native `string|false`
signature, matching the locked WordPress stub return contract; an unexcepted
neighbouring union declaration must still be diagnosed. Changes to these
exceptions require explicit review.
Runtime negative fixtures and foreign contracts must remain tested. When type
analysis exposes stale helper docs or mutable external state, correct those types
or impurity metadata rather than removing the behavioral assertion.


WPCS suppression guards require exact diagnostic identifiers and non-empty reasons.
Every block disable is rejected. Existing host-fixture global identities and
process-local CLI gate variables retain only occurrence-local allowances; new
variables, functions, classes, methods and constants remain checked. The five filesystem/bootstrap, public-artifact and hostile-JSON fixture
cohorts use occurrence-local allowances, with actual-fixture outside controls.
Removing the prior spans exposed 12 structural/foreign-function/JSON diagnostics
and 142 global-variable diagnostics; these are exposure counts, not defect counts.
Fixture executable tokens remain unchanged. The coverage helper changes only its
three existing exact annotation fingerprints to include the new inline PHPCS
comments; it does not relax the accepted PHPStan annotation identities.
The redundant MethodNameInvalid severity-zero override is removed: both WPCS and
RANOwnedMethods now remain active. The guard rejects lower severity, narrowed
checker arguments, rule exclusions and changed prefix properties. These changes
are candidates for independent review; passing checks do not establish exception
acceptance or permission to merge.
