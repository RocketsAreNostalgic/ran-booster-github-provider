# Provider provisioning pilot — 1 October 2026

Provider #38 implements the smallest approved experiment from organisation #111.
Each PHP 8.2/8.5 implementation job provisions PHP and locked Composer dependencies
once, runs the independent baseline, then checks out the unchanged exact candidate
Core and runs `composer check:host`. Five PHP provisions become three and four
Composer installs become two. The standalone no-vendor host-contract job and
release classification retain their separate boundaries. Required lowercase
`quality` accepts only success from all three prerequisite job groups; both PHP
matrix legs must succeed. Failure, cancellation or a skipped group blocks it.

The Provider-owned inline sequence preserves shared `quality-php-library-v2.yml`
at `788f783d2998994f7aab9691710911ed1bd762c9`: exact credential-free source checkout,
locked manifests, PHP/zip/Composer v2, pinned Node 24.11.0 with explicit version
verification, pre-install Composer validation, locked install, `composer check`
and the broader PHP syntax sweep outside vendor/node_modules. Review future
shared-baseline changes against this local sequence deliberately.

The shared action introduced by organisation #115 and Provider #40 was retired
after the [nine-repository adoption audit](https://github.com/RocketsAreNostalgic/.github/issues/111#issuecomment-5929488158)
found no second compatible consumer. Ben approved reintegration on 1 October 2026.
Keep the useful provisioning consolidation local; require a demonstrated second
matching contract before extracting a shared abstraction again. Historical recipe
commit `6e81370238e33c5b77641355a772557912f7fee7` remains reachable for provenance;
current CI does not consume it. Implementation-job permissions remain contents:read.

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

The phase guards detect accidental drift, including writes to `GITHUB_ENV` or
`GITHUB_PATH`; they are not a sandbox or authenticated evidence against hostile
PR-controlled scripts. Baseline and host now share the runner UID, filesystem and
process environment. A malicious script can forge same-UID snapshots or change
other runner state. The prior implementation already ran PR-controlled Composer
hooks/plugins and host tests, and PRs control the workflow itself. Review of the
exact source/workflow remains essential. The separate no-vendor host-contract job
retains its independent runner; the privileged release workflow is unchanged.
Accepting this pilot means accepting the explicitly shared baseline/host runner
state, not claiming the previous process isolation survives consolidation.

Run the boundary fixtures through the existing `composer test:release-control`
entry point, or `node --test tests/ci-quality-phase.test.mjs`. They execute the
actual shell guard against disposable Git repositories with command doubles,
including changed locks/dependencies, wrong source/host, absent or failed baseline,
failed broad lint and failed host checks. Real package checks remain required in CI.
These new shell-boundary fixtures target the existing Linux runner (Bash/GNU
utilities); other local platforms report them skipped explicitly and retain the
pre-existing portable baseline tests. Both native PHP lanes execute every fixture.

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
Any caching or broader rollout needs a separately approved scope. The retired
shared abstraction adds no current consumer obligation.

## Measured prototype results — 1 October 2026

Ten successful paired CI runs used the same commit
`5c238ff28008793aa93dd0de39e14dc2f8c81bf3` (tree
`502b5c7bb311a33710ad40af173acf8de4cf9314`), with both PHP versions on both
topologies in each run. Samples began between 2026-10-01T09:04:27Z and 2026-10-01T09:30:40Z.
The control jobs reproduce the preceding split topology at
`556f19923f6564f1bbd5cecee089d6b136afc5cd` while checking the same candidate source.
The initial superseded/cancelled run `36840345564` is excluded from successful
pairs, not counted as a pass or silently deduplicated into another attempt.
All ten samples are attempt 1. No retry is included in these paired totals.

The following seconds cover only the two pilot jobs versus the four split
baseline/implementation jobs. Common no-vendor host-contract, classification and
terminal jobs are excluded from both sides. Sum is total observed job execution;
span is earliest job start to latest job end within that group, including scheduling
gaps but excluding initial queue. Neither is invoice/billed minutes.

| CI run | Pilot sum | Split sum | Saved sum | Pilot span | Split span |
| --- | ---: | ---: | ---: | ---: | ---: |
| [36840351540](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36840351540) | 152 | 253 | 101 | 87 | 83 |
| [36840782931](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36840782931) | 145 | 263 | 118 | 83 | 87 |
| [36840987599](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36840987599) | 168 | 279 | 111 | 107 | 97 |
| [36841229332](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36841229332) | 160 | 238 | 78 | 99 | 73 |
| [36841715956](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36841715956) | 160 | 260 | 100 | 98 | 85 |
| [36842000591](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36842000591) | 146 | 249 | 103 | 91 | 88 |
| [36842202724](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36842202724) | 236 | 396 | 160 | 178 | 170 |
| [36842643250](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36842643250) | 160 | 266 | 106 | 97 | 87 |
| [36842904047](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36842904047) | 181 | 299 | 118 | 107 | 128 |
| [36843204975](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/actions/runs/36843204975) | 161 | 251 | 90 | 105 | 87 |

Mean summed execution: **166.9s pilot / 275.4s split**;
mean saving **108.5s** (39.4% of these jobs),
median 104.5s, range 78–160s. Mean span delta
is **+6.7s**, median 9s,
range -21–26s: the pilot usually used fewer execution seconds
while taking longer to complete. Initial whole-workflow queue ranged
1–82s; the first sample waited behind the cancelled predecessor.

Mean summed step durations per paired run (seconds; API timestamps have one-second
resolution):

| Operation | Pilot | Split |
| --- | ---: | ---: |
| PHP provisioning | 93.3 | 184.9 |
| Node provisioning | 7.0 | 7.7 |
| Composer installation | 6.4 | 12.7 |
| Baseline/lint, plus pilot drift guards | 15.6 | 14.3 |
| Host aggregate, plus pilot drift guards | 25.3 | 23.6 |

Remaining job time includes runner preparation, checkouts, identity/manifest
checks, validation and teardown. Baseline/host step totals include the indicated
sanity guards; they are not isolated pure test CPU measurements. PHP provision
variability remained visible in sample 7. These paired runs did not reproduce the
historical 15-minute setup timeouts, so they do not prove improved outage behaviour.
Tool/download cache warmth, host reuse and mirror selection were not controlled or
verified; there is no defensible cold-versus-warm comparison, billing forecast or
long-term confidence interval. All samples are from one morning, not many days.

The final candidate removes the temporary comparison jobs and adds command-file
and failed-Git-enumeration controls plus documentation/current-main reconciliation.
Those guard additions are functionally requalified on the final revision, but are
not included in the ten prototype timings above. Extraction and reintegration
are functionally requalified, not additional paired timing experiments. Treat the figures as evidence
for this topology, not an exact final-revision performance guarantee.

Recommendation: the observed compute reduction supports a Provider-only opt-in
if maintainers accept the documented shared-runner tradeoff. Do not roll it out
across consumers or advertise it as a PHP provisioning fix. Keep the old shared
pin reachable for rollback; evaluate longer-window performance and failure/rerun
coupling before proposing any shared workflow or cache rollout.
