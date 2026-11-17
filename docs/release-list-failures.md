# Release listing failures

The provider validates the updater's five-key listing envelope before interpreting
a failed result. Each known failure code has fixed copy. Unknown codes, extra or
missing keys, invalid retry values and non-listing cleanup states remain invalid
candidate data. Failed listings never become empty candidate lists.

Credential, repository-access and rate-limit failures retain
`RepositoryReleaseReadUnavailable`, preserving Core's existing read fallback.
Other failures remain operational exceptions. The updater's coarse codes do not
prove a particular HTTP status, missing credential, expired credential, transport
cause, superseded operation or missing release asset; the provider does not guess.

For a valid `rate_limited` listing envelope, positive integer retry delays are
clamped to 86400 seconds. Null means no delay was supplied. Zero, negative,
non-integer and malformed values are rejected. Inspection and acquisition retain
their existing strict retry and cleanup validation.

When Core's optional `ProviderFailure` and read carrier member exist, the listing
exception carries `rate_limited` and the bounded delay. No HTTP status is invented.
Older hosts retain the plain exception and legacy code 502. Compatibility
construction bridges the old three-argument and new four-argument constructors.

Tests run the public provider through the locked updater with GitHub HTTP 429,
401/403/404, HTTP 500 and WordPress transport-error fixtures. Separate public
source-envelope tests cover all published failure codes and malformed shapes.
Null retries, oversized delays and the listing-inapplicable `invalid_release`,
`package_incompatible`, `release_changed` and `cleanup_failed` codes are defensive
provider-boundary cases, not native listing results from the locked updater.
Its bootstrap rejects null retries and those four listing codes; its runtime
normalises oversized delays to `operation_failed`. These fixtures do not prove
native outcomes the producer cannot publish. CI also runs both listing suites
against the released Core carrier. Skipped-release reporting remains separate.
