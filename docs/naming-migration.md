# Provider naming migration — 1 October 2026

## Operative API14 recovery scope — 2 October 2026

The current recovery completes owned public parameters, properties, promotions and
connected consumers on top of the earlier 50 Core-interface method migrations.
Core DTO fields, method calls, named arguments, test doubles and reflection checks
use the corresponding snake_case API14 declarations; no old-name aliases or mixed
API13/API14 tuple compatibility are provided. Workflow V3, persisted/template keys,
credentials, webhook behavior and updater runtime protocol 5 are unchanged.

The exact candidate Core is
`ae4de158e3ae02d99162b9b8d0babdc9269a36da`, matching the immutable host pin in
[CI](../.github/workflows/ci.yml). Host-contract and implementation qualification
cover 432 tests / 3,354 assertions, including interface parameter-name conformance.
See [the current recovery qualification](beta31-recovery-qualification.md) for
exact source identities and remaining release, composition and installed gates.
Source qualification does not authorize publication or Core dependency adoption.

All sections below preserve historical audits and checkpoints. Their earlier
public-parameter deferrals, proposed mappings, source line references and host
identities describe those checkpoints, not the operative API14 contract above.

## Historical API13 methods-only checkpoint — 1 October 2026

The accepted tranche at this checkpoint implemented exactly 50 methods across GitHubProvider
(30), WebhookPolicy (8), CredentialPolicy (5), GitHubReleaseArtifact (4), and
WebhookNormalizer (3), paired with 47 Core declarations across 20 interfaces.
Only method declarations and their receiver-resolved callers/reflection names
changed. Public parameter names and promotions in the broader proposal
below remained deferred at that checkpoint; that proposal is not blanket implementation authority.
Core owned API13 admission and combined qualification. Bitbucket had a
separate owner responsible for its matching migration. Source/host overlays are
preparation only; publication and real Core lock adoption remain separate gates.

The remaining sections preserve the earlier audit evidence and proposal.


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
signatures in the scoped production files are token-identical to the base.

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

## Follow-on: local test/support and standards closeout

Base `29917abc5fc25eda2c1d3746fb2314f03bd93f19` contains owner-authorized
squash merge #45. Its post-merge CI 36877516643 passed. Ben authorized this next
bounded cut under [claim5934699448](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/25#issuecomment-5934699448),
linked from organisation #65; refreshed #28 ownership showed no competing claim.
Release Please #43 remains outside scope.

| Cohort | Worker source commit | Local integration commit |
| --- | --- | --- |
| Archive/API/producer tests and fixture | dc061994ffe16635ed0bafcac683bc284091ba1d | 9f926a691ed285f26b52498611c74b2f1bfd13f1 |
| General test support and contract scripts | d239b8106d5e323b15370d1f2ef7a132ba97ebc5 | a083b1c2d9ed36154e1293273ec0651a43c62250 |
| Production comparisons/private parameter | 44a9ceacae34741e5e297a53aa0973000894e579 | 00317628e30a75a2058b3f989674915d4be86346 |
| Workflow transport fixtures/bootstrap | 7f1561f6cc246a066e9ad395d430cfd261284d17 | c4c92ee6148f80955a701cd3e89fc7778ecbd764 |

All four handoffs feed `fix/25-local-standards-closeout`; the destination PR
records its immutable published head/tree and source reconciliation. Root owns
connected callers, shared configuration/documentation and final integration.
Root also corrected one missed internal `listed_release` fixture call and combined
two stacked filesystem/naming ignores so both apply to the actual call. These
were resolved before final qualification; no source draft is orphaned.

Forty remaining owned methods migrate, including 20 test methods, with fixture
properties, parameters, locals and connected callers/named arguments. All 180
production public signatures remain unchanged. Genuine Core/WordPress/updater
signatures and external members retain precise deferrals. Production public
naming and dependency/protocol adoption are not included.

Both owned-method and variable rules now cover `src/` and `tests/`, including
all 23 previously unscoped files and future files: 73 PHP files in total. Upstream
method-name diagnostics remain disabled in favor of `RANOwnedMethods`, which
checks inherited/interface classes and honors the same explicit local contract
deferrals. This is rule replacement, not permission for new noncompliant methods.
Completed fixture-property deferrals are removed from connected tests.

Broad Yoda and reserved-parameter suppressions are removed. Sixteen original
Yoda diagnostics resolve through 17 strict-comparison operand reversals across
nine files; grouping, short-circuit behavior, operators and single evaluation
are preserved. Five local reserved parameters change: private repository request
headers, the test autoloader, a release-result fixture constructor/property, the
workflow regex callback and a repository-response test helper. The four public
RepositoryBrowser `$private` parameters retain exact line-local deferrals pending
the coordinated caller migration. Existing unrelated API/layout exceptions remain.

Independent parsed-PHP review finds equivalent executable structure after only
these identifier mappings and comparison reversals. Literal values, persisted
keys, template/generated bytes, credentials, webhook handling and runtime
error/status/protocol behavior remain unchanged. Complete ordered discovery,
dataset labels and groups match the baseline after method mapping: 427 instances.

Local PHP 8.3.6 / Node 24.11.0 qualification:

- Full `composer check` passes, including all 33 Node release-control tests.
- Full `composer check:host` passes against certified Core
  `18b0ec619174000a9a9dbc27b9d68b44b0265449` and separately current Core
  `437e04db9e76d39a6b52a72126330eaf0a54a249`: clear level-1 PHPStan and
  427 tests / 3,170 assertions on each exact host.
- Sixty-four real-PHPCS negative controls pass: method and variable probes in
  each of 23 newly scoped files, nine Yoda probes, five reserved-parameter
  probes, plus method/variable probes in new source and test files. Each probe
  is syntactically valid, produces the intended diagnostic and nonzero status,
  and is removed with exact source-byte restoration.
- Repeated formatter stability and final published-head/native qualification
  are recorded in the PR, alongside clean no-dev distribution evidence.

The full implementation suites retain generated plugin/theme build/verification
and reproducibility proofs. Two opt-in published-template-pack tests retain their
existing exclusion and require separately supplied immutable assets; they are
not represented as executed by the ordinary 427-test suite. No fixture/template
content or runtime package composition changes in this cut.

Residual obligations remain the 102 noncompliant public production declaration
occurrences, public parameters/promotions and coordinated Core/Provider consumer
contracts. Exact mapping, ownership, API/named-argument compatibility and landing
order still require Core #167 agreement. Release Updater beta.9/protocol-5 adoption,
real released Core lock/bundle/archive/installed proof and deferred UI/interactive
acceptance remain separate. Prepared/integrated/published for review does not
mean merged, package-published or adopted; new owner merge authorization is required.

## Accepted Provider helper integration preparation

The [public-helper manifest](naming-provider-public-helper-manifest.md) is now implemented: 52 methods, 64 camelCase parameters (including three private constructor promotions), plus four reserved parameters. Core accepted the mapping in #167 comment5935782179. The two connected Core consumers travel in the coordinated receiving change. This is an intentionally breaking beta PHP API migration with no old-name aliases.

Only obsolete line-local naming deferrals are removed. Directory-wide owned-method/variable checks and foreign Core DTO/interface deferrals remain. The 50 Core interface implementation methods and 16 other public parameter occurrences/11 promotions remain follow-on obligations. Provider source integration is not publication or adoption; immutable releases and real Core locks require the separate approved release sequence.
