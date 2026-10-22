# Isolated workflow storage

`GitHubProvider::create()` accepts an optional final `workflow_namespace` string.
Existing callers retain `bundled` and its byte-for-byte option, transient and
setup advisory-lock names. An example composition must explicitly choose its
own stable value, for example `github_example`. This is a storage identity;
provider code remains `gh` and the supplying plugin and manifest implementation
identity are separate.

An identifier is one lowercase ASCII letter followed by up to 31 lowercase
letters, digits, underscores or hyphens. Invalid input throws before registrar
or storage I/O. Values are never sanitized or truncated. `bundled` selects the
reserved existing store; it is not an isolated wrapper value. Distinct accepted
values derive distinct keys. Sharing a value deliberately shares state.

One immutable `WorkflowAssistanceState` is passed to `SetupRecordStore`; the
coordinator derives the same state from that store, so preview and record
namespaces cannot disagree. Factory construction passes this state through the
real library. Custom keys use `ran_booster_gh_wf_<namespace>_` with purpose
suffixes. Locks also retain the options-table/site scope and database length
bound. Existing exact connection-local claim and release rules remain active.

## Preview lifetime and cleanup

Custom previews retain the existing 15-minute review lifetime and exact preview
validation. A non-autoloaded, namespace-owned index holds at most 100 exact
preview keys and expiries. A separate advisory lock serializes index changes;
index persistence/readback precedes transient creation. Index changes compare the exact previous stored bytes and require the captured
server connection to still own the advisory lock inside the SQL mutation itself.
A reconnect cannot authorize a stale overwrite or deletion. Reusing an occupied
live preview key refuses; ownership loss during transient creation removes only
the newly created key and reports failure. Expired known keys are
deleted before pruning. Malformed or oversized indexes refuse new writes and
cleanup; their contents never authorize arbitrary option deletion. This bounded
index lets `delete_transient()` reach both database and persistent object-cache
storage without a global cache flush or options-table scan. Bundled previews
retain their existing transient/TTL behavior and are not imported into an index.

Deactivation is not cleanup. After verifying that a deleted plugin owns the
exact custom namespace, uninstall can call `remove_ephemeral_state()` and, only
when successful, `remove_durable_state()` on its own state object. Check both
boolean results and report uncertainty. Failed cleanup retains enough known
ownership evidence for retry. Bundled ephemeral enumeration is unavailable;
`remove_ephemeral_state()` must not claim a global purge of unindexed historical
previews. Bundled durable cleanup still owns only its current and retired option
list. Custom durable cleanup never inherits that retired list. Neither cleanup
method removes Core credentials, managed packages, activity, remote hooks or
remote files. Advisory locks are connection-owned, not durable options to delete.

Changing the namespace starts an independent store. There is no automatic
migration, synchronization, other-namespace read-through or dual write. Preview
receipts and locks are never portable authority. Storage separation prevents
accidental collisions; it is not a PHP sandbox against another trusted plugin.

## Switching and remote work

An empty local store does not establish an empty remote repository. Inspection
still performs repository identity, automation/path conflict and template checks.
Setup revalidates exact remote branch/PR/file evidence and consumes its own
preview before mutation. Uncertain responses require authoritative readback;
replaying a consumed preview never authorizes another attempt. Deterministic
matching draft recovery remains bounded by the existing base/template identity.
Do not promise reconstruction of every old draft or zero additional PRs.

Core owns explicit provider adoption and return, credentials, package data and
history. Returning to bundled may reveal its previous records, which must still
pass fresh validation. Neither the library nor Core imports the custom store.
Transporter carries only its defined managed-package and explicitly selected
supported Core-held credential data through an available compatible destination
provider. Private workflow records, previews, indexes and locks are excluded.

## Dependency and host qualification

The optional namespace argument is backward compatible for existing bundled
callers. An older physical copy in the same PHP namespace does not implement
this seam and must not silently substitute for the qualified library. A wrapper
must verify the loaded implementation, exact supported dependency tuple and both
plugin load orders. Compatible-copy reuse requires evidence; no runtime broker,
class-scoping system or mixed-version guarantee is introduced here.

A real replacement also needs lazy artifact limits, fresh operation approval
and both host native mutation callbacks. The currently examined Core API15
registration context exposes only the first two. A guard-only updater declaration
is rejected by the real runtime. Namespace support therefore does not establish
that a full-capability replacement is ready on an existing Core release.
