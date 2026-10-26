# Releasing

This package uses the organisation-owned **Profile A** release lifecycle.

Release Please owns version selection, changelog generation, the managed release PR, tag creation, and the GitHub Release. The repository-local release workflow is a thin caller of the pinned shared Profile A contract in `RocketsAreNostalgic/.github`.

## Normal development

Ordinary pull requests follow the protected repository merge policy. Changes under `src/` or to production Composer requirements remain release-significant for this package and must use a visible release-driving Conventional Commit type from `release-please-config.json` (`feat`, `fix`, `perf`, or `revert`) or an explicit breaking `!` classification.

The terminal `quality` check enforces that package-specific rule. Classification uses merge-base-to-head changes so unrelated later changes on `main` are not attributed to an older pull request.

## Release flow

1. Exact `main` CI succeeds.
2. The shared Profile A workflow admits only that canonical successful same-repository `main` CI revision and runs Release Please against current `main`.
3. If Release Please creates or updates its bot-owned release PR, Profile A binds the configured candidate branch to the exact PR head and dispatches this repository's existing read-only `CI` only when no successful or in-flight qualification already covers that exact head.
4. The `workflow_dispatch` CI path fails closed unless the dispatched ref is exactly the canonical bot-owned Release Please PR. It resolves that PR's exact base/head/title and runs the same release-significance classification before terminal `quality` can succeed.
5. Review the generated version/changelog proposal and merge only after required checks and review complete.
6. Exact `main` CI for the merged release revision admits Release Please again; Release Please creates the tag and GitHub Release. Organisation immutable-release policy is the publication baseline.

There is no repository-local publisher, replay authority, immutable-release acknowledgement variable, manual lifecycle-label reconciler, special two-parent release-merge geometry, or standing historical recovery path.

A failed release is repaired through reviewed source/configuration, fresh qualification, and a new valid Release Please lifecycle. Do not create or move release tags manually or bypass failed `quality`.

## Example consumer proof

The required `consumer` CI matrix installs the exact package candidate into the
[Example plugin](https://github.com/RocketsAreNostalgic/ran-booster-github-example).
It runs on ordinary PRs, including draft PRs, main and the canonical release-PR
dispatch. No package release or Example dependency-bump PR is needed first.

Only the Example's source is pinned in CI. Its candidate preparation tool replaces
the released Provider dependency in temporary Example and host assemblies with
the exact package checkout, using Composer. Other dependencies remain locked.
Both assemblies need matching Provider bytes because the Example rejects
conflicting already-loaded copies. Core's source and repository remain unchanged.
These temporary test assemblies are not published Core or Example releases.

The disposable WordPress test exercises GitHub release discovery and installation
of that Example archive, then activates and adopts the Example and checks both
plugin load orders. It verifies the executing Provider against the candidate,
using controlled HTTP responses and no production credentials. Every consumer
matrix result feeds terminal `quality`, which gates the existing Profile A path.
The installed test uses the pinned Core source's container-inspection fixture
only as test instrumentation. The distributed Example still uses public provider
contracts. Initial installation uses the candidate bundled in the temporary host;
after adoption, both load orders repeat listing and dependency-copy checks.

The Example owns this consumer fixture; there is no second plugin wrapper here.
Focused package fixtures remain for malformed responses, credential boundaries,
archive custody, updater contracts and workflow protocols. Consumer success does
not replace those assertions or certify a retained site or browser acceptance.

## Booster consumption

Booster consumes a reviewed immutable provider release, never `dev-main` or an arbitrary source SHA. Provider publication does not update Booster automatically. Any Booster dependency update remains a separate reviewed integration change with its own exact-head Quality and runtime dependency/archive verification.
