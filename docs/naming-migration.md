# Provider naming migration — 1 October 2026

Coordination: Provider #25, organisation #65, Core #167; template ownership #28.
This first local cut starts at `de9807f415524b3e05ce20758d06f3ff2d0f1a6f`.
It does not complete the public-contract migration or authorize a release.

## Prepared and integrated scope

| Cohort | Files under src/ | Private methods | Worker source commit | Integration commit |
| --- | --- | ---: | --- | --- |
| Browser/webhook transport | RepositoryBrowser.php, RepositoryWebhookClient.php | 28 | e2a448883768a37394780b392921a5352dec2e5a | cca3438 |
| Credential/webhook policy | CredentialPolicy.php, Diagnostics.php, WebhookPolicy.php, WebhookNormalizer.php | 18 | 271921bde1f0bfbd22af3b9920ce382406d5ed3c | 6bfac02 |
| Provider/artifacts | GitHubProvider.php, GitHubReleaseArtifact.php, GitHubReleaseNativeTarget.php | 19 | b09e5856d6af1ce5dccb9dc735fdac4bcbbd37df | 5eeb0fb |

All 65 mappings are direct camelCase to snake_case. The same cut migrates private
parameters, locals and nine nonpromoted private properties. NativeTargetsTest's
reflection follows `accessToken` to `access_token`. All public declaration
signatures, external member accesses and production string literals are unchanged.
Independent executable-token review of the three source commits found no other
changes. Isolated worker branches are integrated here; none is an orphaned PR.

## Enforcement and quality dependency

The nine named files now enable WPCS variable naming and the released shared
`RANOwnedMethods` check. The latter checks inherited/interface-implementing
classes that upstream WPCS skips. Public methods, public named parameters and
promoted/Core DTO property accesses carry local, specific deferrals. No class-wide
inheritance exemption is used. Global method suppression remains only because
unmigrated code is outside the owned-method opt-in; workflow/test variable naming
remains outside this completed scope. No Yoda or analysis-level migration occurs.

Development-only `ran/coding-standards` changes from dev-main at
`0b03e61a4bb558deeb6bc6b6399f44c0ec95e5be` to published `v1.0.0` at
`6af816a02b7d1108ad5c990e9d0fda0af0a13de7`, the version already adopted by Core.
Its relevant additions are the opt-in owned-method rule and alignment errors
remaining blocking under warning suppression. The package adds the explicit
PHPCSUtils requirement; the already locked compatible version is retained.
Every other dependency record, runtime requirement, platform and host pin is unchanged.

Online Composer metadata refresh failed (proxy timeout/authentication). A
network-disabled Composer resolver qualified the full locked dependency graph
using existing package metadata and the independently tag-verified v1.0.0 record
from Core's lock. Only its resolved standards record was transferred; Composer's
own content-hash calculation refreshed the lock. Strict validation and an actual
locked install then passed. This is an exact released package, not a synthetic
runtime alias or source pin. Native locked-install CI remains mandatory.

## Validation and limits

Local PHP 8.3.6 and declared Node 24.11.0:

- `composer check`: strict manifest/lock, syntax, PHPCS and foundation contracts;
  all 33 release-control/phase tests pass.
- `composer check:host` against certified candidate Core
  `18b0ec619174000a9a9dbc27b9d68b44b0265449`: contract, blocking level-1 PHPStan,
  427 implementation tests / 3,170 assertions pass.
- The same complete host aggregate against independently verified current Core
  `a8b635a8c9a40482ec5f125023f54e43f88bce58`: 427 / 3,170 and clear PHPStan.
  This separate result does not change CI certification or Core's bundled lock.
- Eighteen negative controls insert a bad private method and a bad local into
  each selected file, including interface implementors. Actual repository PHPCS
  reports the expected owned-method/variable error in every case.
- Two subsequent complete `composer standards:fix` passes preserve tracked hashes.
- Existing implementation coverage executes producer-built template parsing and
  plugin/theme generated archive build/verification in disposable local Git repos.
  Template bytes/contracts were not edited.

PR comments record the final immutable candidate, native PHP 8.2/8.5 matrix,
independent published-head review, clean no-dev distribution proof and log hashes.
Local results do not substitute for those final gates or installed Core adoption.
The package is a Composer library; connected WordPress archive/installed acceptance
remains with the separately owned Core integration and template programme.

## Residual obligations

The source baseline has 253 noncompliant methods: 151 private and 102 public.
This cut completes 65 private methods, leaving 86 private workflow helpers and
102 public declarations (50 Core-interface implementation occurrences and 52
Provider-owned public methods). Distinct interface symbols and implementation
occurrences differ: the Core manifest lists 44 distinct noncompliant methods,
67 interface/implementation rows across seven classes including already compliant
methods and repeated implementations. Tests, promoted properties and public
named parameters also remain inventory, not completed migration.

The proposed [declaration/consumer manifest](naming-core-contract-manifest.md)
requires Core #167 agreement on exact mappings, file ownership, named-argument
compatibility, API generation and integration order before any shared rename.
Five workflow signatures already differ (`$target` in Core, `$status` in Provider);
do not silently choose a new public parameter contract. WorkflowAssistance/#28
ownership must be refreshed before its private/public cohorts are reserved.

Release Updater remains pinned to 0.1.0-beta.7. Its published beta.9/protocol-5
adoption is a separate, explicitly coordinated dependency/caller cohort with
exact combined-host and released Core bundle/archive/installed qualification.
No protocol adoption, version bump, template redesign, UI acceptance, merge,
publication or installed adoption is performed by this cut.


## Follow-on: isolated top-level test naming

Base `c6b924090243a0c0111ffd14c7ebe808ae003dc3` contains merged #42. This
test-only follow-on migrates the 17 direct `tests/Booster/GitHub/*Test.php`
files: 186 owned methods (147 test methods plus helpers/data providers), owned
fixture properties, parameters and locals. Exactly 26 DataProvider attribute
strings follow the renamed declarations. Dataset keys and values are unchanged;
all argument rows are positional. There are no cross-file owned-method callers,
Depends annotations or command/filter references requiring additional edits.

Three isolated source cohorts are integrated into this PR:

| Worker cohort | Source commit | Destination integration commit | Focused baseline-host result |
| --- | --- | --- | --- |
| Six transport/webhook tests | d506673117eb03c150d7b21a0c3ff8fe7e1d3b29 | bd044ab | 155 tests / 1,232 assertions |
| Five credential/diagnostic/native tests | c4bde9b940020ff1ccb5d00bd7fa79dc7c39e720 | cd5041f | 51 tests / 183 assertions |
| Six release/custody tests | d99fff8c260cbbebebcfe34f3bd365c462c3d997 | e72357e | 46 tests / 216 assertions |

The shared scope explicitly lists the 17 files for both owned-method and WPCS
variable checks. Narrow exceptions preserve PHPUnit lifecycle methods, Core
interface/production overrides, external DTO properties, the updater inspect
parameter and the deliberate throwing `maximumArtifactBytes()` forbidden-policy
probe. That probe must retain its exact name to detect accidental host discovery.
There is no blanket test/inheritance exemption within these files.

Baseline and candidate discovery preserve the complete ordered 427 instances
across 30 classes, including dataset labels and group metadata, after applying
only the owned-method mapping. Complete suites retain 427 tests / 3,170 assertions.
Exact qualification uses certified Core `18b0ec619174000a9a9dbc27b9d68b44b0265449`
and a separate current-source candidate `7450d2dd22ec256427d284ee2058c203331a79e6`.
Production source, all dependency records, bootstrap/support/workflow files, host
pins, CI and release files are byte-identical to the base. This does not change
runtime archive contents or certify a new released/installed Core composition.

Thirty-four negative controls inject an invalid method and local into every
newly selected file; each must produce the expected naming diagnostic. Two
complete formatter passes must preserve tracked bytes. Final PR comments record
the exact published tuple, aggregate/native check results and independent review.
The earlier production residual inventory is unchanged. Workflow tests, shared
Support/bootstrap naming and public contracts remain separate; Release Please
#43, updater adoption and UI acceptance are outside this test cohort. No merge
or publication authority follows from implementation approval.

## Follow-on: private workflow internals

Base `b4d1cedaf3e2ba6802d12839d4ae15378e5b77eb` contains merged #42 and
#44. After the fresh #28 ownership check, Ben authorized the bounded claim
[5931229045](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/25#issuecomment-5931229045).
No competing implementation PR or newer file reservation was found. This is
private naming work, not template-owner interactive acceptance.

| Cohort | Private methods | Worker source commit | Local integration commit |
| --- | ---: | --- | --- |
| Persistence/workflow facade | 29 | f5399073153a4a0dd9e474ab4d7f795898ffedbf | 9e86da828d60b836da342044922cb588e2a7cbd6 |
| Application/repository transport | 25 | 915611275e4911a06fe9219c243d23b999207764 | 14843195d5cd26d00c5944c6dc17c1ad1c7a9783 |
| Template verification/acquisition | 22 | d54d21ed075848085ff5f82ffc65b5c3918595ab | 0e34f4e7d182a146219f3f225aa2a7de3a29a267 |
| Assessment/starter assembly | 10 | f1bb3042616d25b0b6f106d462796665483326f1 | 44cac26441926dd69cf036a6d27bed277856744b |

All four source cohorts are integrated into `fix/25-workflow-private`; the PR
records its exact published head/tree and reconciles these source commits. No
worker draft is abandoned or closed without a receiving destination.

This cut completes all 86 remaining private production-method renames, plus 15
private properties and safe private parameters/locals. The promoted properties
changed here belong to private constructors. RepositorySnapshot's public
constructor parameters/properties remain unchanged. All 91 public declaration
signatures in the changed production files are token-identical to the base.

The 10 same-basename tests migrate 127 owned methods: 116 test methods, eight
private helpers and three data providers. Their three DataProvider attribute
strings follow the declarations. Complete ordered discovery, dataset labels and
groups remain identical for all 427 instances after applying the method map.
Nine inherited PHPUnit lifecycle declarations retain exact local exceptions.

Both naming checks now explicitly cover all 14 production WorkflowAssistance
classes and those 10 tests; StarterGuidance already conformed and requires no
source change. Inherited/interface implementations receive owned-method checks.
Line-local deferrals preserve public method/named-parameter contracts, public
constructor promotions and external/shared fixture members. No class-wide
inheritance exclusion is introduced. Remaining unscoped test support/bootstrap
and the three template archive/API/producer test classes remain separate naming
cohorts; the global upstream method suppression is not evidence of their
convergence. Shared fixtures are unchanged.

Independent token review confirms every changed identifier is its exact
snake_case mapping; all 877 changed identifier tokens preserve executable
structure. Changed member accesses target only `$this`/`self`. Production literals,
persisted/JSON keys, generated template bytes, credentials, redirect callback
references/captures, webhook handling and error/status/protocol semantics are
unchanged. The only three changed string tokens are test DataProvider references.
Receiver-sensitive public names such as SetupRecordStore::releaseClaim and
WorkflowAssistanceState::claimLockName retain their existing spelling.

Local PHP 8.3.6 / Node 24.11.0 qualification passes:

- Full `composer check`, including all 33 Node release-control tests.
- Full `composer check:host` on certified Core
  `18b0ec619174000a9a9dbc27b9d68b44b0265449`: host contract, clear level-1
  PHPStan and 427 tests / 3,170 assertions.
- Separate full host aggregate on Core #215 candidate
  `2b0c556bf62321cb40f7537024b8bfab0356f716`: the same 427 / 3,170 and clear
  PHPStan. This proves source compatibility with that exact combined candidate;
  it does not modify Core's bundled Provider or certify release/installed adoption.

The existing host suites execute plugin/theme generated archive build/verification
and byte-reproducibility proofs. PR comments record final-head native PHP 8.2/8.5,
negative controls, repeat formatter stability, clean no-dev distribution and
independent published-head review separately; local passes do not replace them.

After this cut, no camelCase private production methods remain. The 102 public
method declaration occurrences (50 Core-interface implementations and 52
Provider-owned public APIs), public parameters/promotions and residual shared test
naming remain coordinated work. The proposed Core manifest still requires exact
mapping/ownership/API/integration-order agreement in Core #167. Runtime dependencies,
host pins and release identity are unchanged; Release Updater beta.9/protocol-5
adoption remains the explicitly separate integration cohort. UI and owner-verified
interactive acceptance remain deferred. Prepared/integrated is not merged,
package-published or adopted; a new owner merge authorization remains required.
