# Template-pack consumer compatibility transition

## What is being updated

The consumer is the PHP release-workflow setup assistant in this GitHub provider,
which Booster bundles. It downloads the canonical template-pack ZIP, validates it,
renders approved files, and proposes a draft PR in a managed plugin/theme's GitHub
repository. This is not the shared GitHub Actions publisher.

This change is the consumer-first bridge for organization issues #55 and #56.
It does **not** implement or advertise API-3 rendering. `TemplatePack::CONSUMER_API`
remains 2; the existing plugin/theme profiles and logical-file mapping are unchanged.

## Recognised next envelope

The deterministic next envelope has the existing ordered top-level keys:
`schema_version`, `consumer_api`, `pack_version`, `repository`, `release`, `profiles`.
Its schema is 1, its consumer API is integer 3, and the repository is the canonical
name and numeric-string ID. Its `release` object contains exactly `tag`, `commit`,
in that order, with string values. It does not contain a dummy or optional ID.
Version, tag, source commit and repository must match the separately verified
GitHub identity. The nonempty `profiles` envelope cannot contain forbidden
capability keys, but the new logical-file/render contract is not defined here.

Only this next envelope may omit the embedded release ID. The existing ID-bearing
shape remains unchanged, including its unsupported-version refusal behavior.
An API-2 manifest without the ID, or with a different ID, is still invalid.
An unknown API using the ID-less shape is invalid, not a generic skip instruction.

## Incompatible is not accepted

Before returning `template_pack_incompatible`, the reader still checks the fixed
repository, positive transport release/asset IDs, stable immutable release state,
exact tag/source/target equality, one canonical asset, size and SHA-256, ZIP
structure, member paths/permissions/encoding, and resource limits. Malformed
identity, tampered bytes, unsafe archives and forbidden capability metadata remain
errors. They do not authorize fallback to an older release.

The bridge recognises the next envelope with either `application/zip` or
`application/octet-stream` asset metadata. The latter accommodates shared Profile B
upload metadata **only for this unsupported envelope**. API-2 rendering still
requires `application/zip`. No other MIME types or additional public assets are
allowed. The promotion manifest/checksum evidence can stay in Actions artifacts;
it is not a second public pack asset.

An incompatible envelope returns no `TemplatePack` object and cannot be rendered,
previewed or applied. Discovery continues to a valid older API-2 pack and reports
`newer_incompatible`. If none is available within the existing bounded discovery
window, it returns incompatible. This does not guarantee indefinite historical
fallback after arbitrarily many future releases.

Numeric release and asset IDs remain in transport identity and exact re-fetch.
The bridge does not change the preview, setup-record, receipt, or draft-PR mutation
code. Selected API-2 packs still undergo the same identity checks on confirmation.

## Rollout gates

1. Review/merge the bridge provider change and release it through normal Profile A.
   Existing Release Please PR #24 is not bypassed or manually modified by this work;
   re-read its actual candidate version, head, CI and reviews before progression.
2. Adopt that exact released provider in Booster through a separately reviewed
   dependency change and prove the installed/runtime composition. A passing provider
   test against Core is not evidence that Core already bundles the new provider.
3. Upgrade supported installations, or record an explicit supported-installation
   cutover, **before publishing a stable ID-less API-3 pack**. Unmodified consumers
   classify that pack as invalid and stop discovery. Changing the producer alone is
   not backward compatible, and the bridge cannot retrofit already deployed code.
4. Under #56, implement the complete API-3 rendering/logical-file contract alongside
   the thin generated callers. Under #55, build the deterministic ZIP in read-only
   Quality and promote those exact bytes. Keep both acceptance records separate;
   do not publish an incomplete API-3 contract merely to finish one checklist.

The bridge is temporary transition support, not an API-1 adapter or a generic
multi-version compatibility framework. Remove it only through an explicit later
supported-client decision, not by silently weakening current ID checks.

## Evidence

Existing PHP 8.2/8.5 independent and certified beta.29 host lanes remain required.
A supplemental required job runs the same candidate provider against Core beta.30
`1c8283bc814ac593171d608d532226fcea83c6f4`, then executes the published-pack tests
with the exact immutable v0.2.1 and historical v0.2.0 ZIP sizes and SHA-256 pins.
The test bootstrap loads this candidate's `src/`, not Core's older bundled provider.
Those published tests preserve API-2 plugin/theme rendering digests and API-1 refusal.

Transition tests exercise the actual parser and discovery client with native ZIP
fixtures, including successful fallback, ID/asset drift, MIME restrictions, invalid
identity, forbidden capabilities and hostile archives. Their API-3 payloads are
inert envelope fixtures, not evidence of an implemented API-3 rendering contract.
