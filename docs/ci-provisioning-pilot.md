# Provider provisioning pilot — 1 October 2026

Provider #38 implements the smallest approved experiment from organisation #111.
Each PHP 8.2/8.5 implementation job provisions PHP and locked Composer dependencies
once, runs the independent baseline, then checks out the unchanged exact candidate
Core and runs `composer check:host`. Five PHP provisions become three and four
Composer installs become two. The standalone no-vendor host-contract job and
release classification retain their separate boundaries. Required lowercase
`quality` accepts only success from all three prerequisite job groups; both PHP
matrix legs must succeed. Failure, cancellation or a skipped group blocks it.

The local baseline operations mirror shared `quality-php-library-v2.yml` at
`788f783d2998994f7aab9691710911ed1bd762c9`: exact credential-free source checkout,
locked manifests, PHP/zip/Composer v2, pinned Node 24.11.0 with explicit version
verification, pre-install Composer validation, locked install, `composer check`
and the broader PHP syntax sweep outside vendor/node_modules. This is a bounded
local pilot, not a change to the shared provider. Future shared-provider changes
must be deliberately reviewed against this copy until the pilot is rolled back
or a separately approved immutable shared interface replaces it.

`scripts/ci-quality-phase.sh` requires a source SHA and phase-state directory
outside the checkout. Baseline rejects the Core environment and sibling checkout.
Tracked source bytes (including locks, even with misleading index flags) and
installed dependency file bytes/symlink targets are checked before and after the
phases. Only a successful baseline admits the host phase for the same source.
Host environment variables are scoped to that step; the Core revision remains
`18b0ec619174000a9a9dbc27b9d68b44b0265449`, preserving the existing candidate-only
API12/V3 qualification. PHPUnit/PHPStan caches are outside vendor and remain
ordinary disposable test state. No cached vendor or certified host is trusted.
These jobs do not establish installed or released Core composition.

Run the boundary fixtures through the existing `composer test:release-control`
entry point, or `node --test tests/ci-quality-phase.test.mjs`. They execute the
actual shell guard against disposable Git repositories with command doubles,
including changed locks/dependencies, wrong source/host, absent or failed baseline,
failed broad lint and failed host checks. Real package checks remain required in CI.

## Evaluation and rollback

Compare ten paired fixed-candidate samples over multiple time windows. Separate
PHP setup, Node setup, locked install, baseline/broad lint and host checks; record
summed job execution, elapsed span, queue and retries separately. Keep the original
split baseline/host topology as a bounded qualification control. It must never
substitute for the canonical terminal check. Do not infer billing from API job
durations or guaranteed cold/warm runner state without evidence. No Composer cache
is introduced; provider-managed download/tool caches remain an uncontrolled input.

The prior faster-sample model predicts 75–93 seconds less summed setup/install
execution per run, not a measured benefit or a latency guarantee. Sequential tests
and coupled reruns may worsen latency. Consolidation reduces repeated exposure to
provisioning; it does not fix the external PHP 8.5 package retrieval problem.

Rollback the single Provider opt-in commit to its previous baseline caller and
implementation topology at main `556f19923f6564f1bbd5cecee089d6b136afc5cd` (including
the shared pin above). Preserve required `quality`, the separate host contract,
release-classification/dispatch admission, action/host pins and both PHP versions.
No settings, shared defaults, other consumers or immutable releases need changes.
Any shared abstraction, caching or broader rollout needs a separately approved
scope after the pilot's timing and failure-isolation evidence has been reviewed.
