# Replacement compatibility

`GitHubProvider::compatibility_contract()` exposes passive package semantics for
Core's retained approval. It constructs no provider, reads no credentials and
performs no I/O. Core adds the actual supplying plugin basename and derives the
implementation fingerprint. This library publishes no WordPress replacement
marker and this method does not activate replacement support.

The implementation identity is `ran/booster-github-provider`, compatibility
revision `1`, Provider API 15. The facet list describes the actual aggregate,
including inherited repository browsing; workflow management is V4, not V3.
Ordinary package version changes do not change this revision. Changes to
credential interpretation, retained signing authority, source identity or
capability semantics require a new compatibility revision. Core must compare
against the approved retained contract, never reconstruct old approval from
updated package code. Expanding the format or facet sets also changes approval.

## Credential meanings

- `github-classic-pat-v1`: normalized kind `classic`, configuration exactly
  `owner: ""`.
- `github-fine-grained-pat-v1`: normalized kind `fine-grained`, configuration
  exactly `owner` with the existing GitHub owner grammar. Stored metadata is
  already normalized; compatibility checks do not trim or reinterpret it.
- The active `RAN_BOOSTER_GITHUB_TOKEN` string overlay remains classic with empty
  owner, regardless of token prefix. Whitespace-only and non-string values are
  absent. Core never copies the overlay into a stored profile.

The IDs concern metadata semantics, not proof that a token is currently valid
or permission to disclose it. Submitted-token validation keeps its existing
prefix/length checks. Profile identity, explicit selection/default semantics,
self-destruction and provider expiry remain Core-owned and unchanged. A missing,
destroyed, incompatible or unapproved selected credential cannot be replaced
with another profile, constant or anonymous access.

`github-hmac-sha256-v1` retains Core-managed scoped signing keys and stable
repository identity. API credential reuse is separate from signing authority;
Core's adoption confirmation must cover the retained signing relationship.

## Private workflow state

`WorkflowAssistanceState` supplies an immutable, validated storage namespace.
The default `bundled` owner retains its existing option, preview transient prefix
and advisory-lock names. A wrapper must explicitly pass a distinct stable
`workflow_namespace` to `GitHubProvider::create()`; merely bundling another copy
of this library does not isolate state. The namespace is independent of `gh`,
plugin basename and replacement-manifest identity.

Records, preview keys, cache invalidation and site-scoped advisory locks follow
that namespace. Custom cleanup cannot remove bundled or retired bundled state.
Deactivation retains state; uninstall may remove only the deleted composition's
known namespace. No state migration, read-through, synchronization or dual write
occurs when switching. See [workflow storage](workflow-storage.md) for construction,
cleanup, bounds and remote-automation caveats.

No official runtime wrapper is supplied by this library. The example requires
separate exact Core/provider/updater qualification, including public native
mutation coordination, explicit adoption and dependency-copy attribution.

## Operation lifetime

`GitHubProvider::create()` accepts optional `operation_guard`,
`native_mutation_begin` and `native_mutation_complete` callbacks after the archive
limit supplier. Core replacement composition supplies the guard; absence exists
only for standalone composition outside replacement support. Only literal `true`
allows work. False, non-boolean results and exceptions refuse with fixed copy.

The provider checks before and after credential reads and its outbound HTTP,
including anonymous browser and workflow requests. Stale responses are discarded.
Private archive authentication rechecks selection and reacquires the selected
credential at consumption. Core's prepared-archive guard supplies the independent
anonymous HTTP lifetime fence; merely checking when preparing the URL is
insufficient. Cleanup remains available after authority loss.

Release operations use the registrar's `with_operation_guard()`; native updates
also receive `with_native_mutation_callbacks(begin, complete)`. The exact callable
identities are retained. A guarded factory rejects an older registrar lacking
these seams. The negotiated protocol-6 runtime owns acquisition, native mutation
and cleanup. Core owns begin's durable marker and complete's exact-owner verified
success/uncertainty decision. Source qualification against the new host/runtime
and immutable release adoption are separate from declaring this contract.
