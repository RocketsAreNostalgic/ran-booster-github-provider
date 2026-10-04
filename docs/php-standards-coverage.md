# Provider standards adoption checkpoint

This implements the next-beta standards policy from organisation #128 on top of
Provider #57's required PHPStan level 5. Runtime contracts, persisted state and dependency requirements are
unchanged; only private unused helper inputs/callers are removed.

- Shared `ran/coding-standards` moves from 1.0.0 / `6af816a02b7d1108ad5c990e9d0fda0af0a13de7`
  to released 1.0.1 / `0248066be3f4f9476ef7095d888657001488a3de`.
- The existing WordPress library profile remains. Shared 1.0.1 centralizes the
  decision that exception payloads are diagnostic data, escaped at actual output
  boundaries; redundant local annotations become ordinary explanatory comments.
- PHPCS and PHPCBF share `.phpcs.xml`, covering the maintained PHP tree except
  vendor and generated analyzer/test caches. Prefix rules retain the test-fixture
  exception; owned method/variable, Yoda, unused-parameter and reserved-parameter
  checks cover the whole maintained tree. Upstream inherited-method naming is
  replaced by the existing RANOwnedMethods sniff, not omitted.
- Two file-wide unused-parameter exclusions are removed. The initial exposed
  reports were ten unused fixture arguments. Enabling inherited and before-last
  diagnostics exposed another 28 instances. Genuine Core/WordPress/test-double
  signatures retain declaration-local explanations; three private runtime helper
  inputs and one private test helper input are removed with their callers.
- A negative fixture runs the actual local checker and proves inherited owned
  method naming and unused private/helper parameters remain blocking.

The checker currently discovers all 75 maintained PHP files, matching an independent
filesystem sweep. The regression also accounts for future extensionless PHP
entrypoints and rejects unscoped PHPCS/legacy suppression comments while ignoring
fixture strings. Negative controls run the locked checker and require inherited
owned-method, unused-helper, variable, reserved-parameter and Yoda diagnostics.

Retained local exceptions are scoped to their actual boundaries: the test prefix
namespace, WordPress and Core interface signatures, native request callbacks,
structural test doubles, and exact fixture filesystem/JSON bytes. The producer
exchange fixture no longer disables the complete alternative-functions category;
its call-site exceptions name byte provenance, reproducible local Git archives,
JSON flags or subprocess stream ownership. Existing producer ZIP/rendered-digest
and two-build tests establish these invariants.

PHPCBF uses the same configuration and paths as PHPCS; repeated fixes are stable.
Required PHPStan level 5 and the exact immutable Core beta.31 source-host identity
remain unchanged. Local PHP 8.3 qualification complements the native PHP 8.2/8.5
matrix and Node 24.11.0 checks; PR evidence records their exact revisions/results.
No new release authority or installed/UI acceptance is claimed.
