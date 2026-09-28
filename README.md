# RAN Booster GitHub Provider

First-party GitHub provider implementation for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This repository is a **Composer library**, not a WordPress plugin. RAN Booster
pins and bundles an immutable released version, so ordinary Booster users do
not install this package separately.

## What this package provides

The package owns GitHub-specific provider behavior, including:

- repository resolution, public browsing and archive preparation;
- GitHub credential interpretation and validation;
- provider diagnostics and webhook normalization/management;
- GitHub release metadata, candidate listing, inspection and acquisition;
- native release-target composition; and
- release-workflow assistance and its provider-owned workflow state.

Booster remains the host. It owns the provider registry and sealing lifecycle,
credential custody, provider-neutral policy, deployment coordination,
administrator surfaces and final Core mutation authority.

## Provider boundary

Bundled versus external is a distribution choice, not a privileged provider
architecture. The `gh` aggregate implements Booster's public provider
contracts and is registered through the same bounded Provider API semantics
available to external providers.

The package has no production Composer dependency on the whole
`ran/booster` plugin and must not import Booster private
Admin/Internal/Logging/Secrets/Storage/WordPress implementation namespaces.
Where host policy is needed, it arrives through bounded public registration
inputs. The package owns its legitimate shared dependencies, including the
provider-neutral release updater.

The current host contract is Provider API 11. Credential-bearing registration
requires `ProviderCredentialStore`, `AuthenticatedWebhookDeliveryEvidenceReader`
and the bounded `ProviderRegistrationContext`. The API-11 registration wrapper
adapts the context's host-resolved artifact-size policy to the package's
host-neutral callable composition boundary used by released Booster beta.29;
there is no API-10 two-argument registration compatibility path.

Workflow-assistance persistence has a single current pre-1.0 baseline: setup
records use schema 2 under the provider-owned option namespace, and failure
history uses the current diagnostic-bearing record shape. Earlier prerelease
option names and record shapes are not migration contracts.

## Requirements

The supported host baseline is:

- PHP 8.2 or newer;
- WordPress 7.0 or newer when used through Booster; and
- a compatible RAN Booster installation providing the public provider
  contracts.

The package is pre-release software and should be consumed through an immutable
tagged release, not a moving development branch.

The shared repository-path dependency requires `ran/updater-support ^1.0.0-beta.4`.
This repository locks an immutable release for qualification; consuming hosts own
their dependency locks and must qualify their complete package composition.

## Development

The canonical local gate requires PHP 8.2+ with Composer and Node **24.11.0**.
Node is used for the maintained release-control scripts/tests; the package has
no frontend toolchain.

Install the locked development dependencies and run:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer standards:fix` to apply PHPCBF.

The implementation tests and static analysis also verify compatibility with an
exact candidate Booster checkout because the public provider contracts remain
Booster-owned. For an equivalent local pass:

```bash
export RAN_BOOSTER_CORE_PATH=/path/to/ran-booster
# Match the candidate host pinned in .github/workflows/ci.yml.
test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = 3dfccf389fae6ee9e54e141f5b97b0d9b7aca2ff &&
  composer check:host
```

`composer check:host` runs the host contract, blocking level-1 production
analysis and the implementation PHPUnit suite. It supplements `composer check`.
CI pins and verifies that host revision before running the host-backed gates.

## Issues and ownership

Report GitHub-provider implementation defects in this repository. Issues about
Booster's provider-neutral API, registry, credential custody, deployment
orchestration, administrator UI, or host integration belong in
[RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster/issues).

Security reports should use this repository's private GitHub security-advisory
flow rather than a public issue.

## Releases

This package is versioned and released independently from Booster. Booster
consumes a specific immutable provider release and verifies that dependency in
its runtime archive. Release and trust details for maintainers are documented
in [RELEASING.md](RELEASING.md).

## API 3 initial starter candidate

Provider #30 implements the five-method initial-only V3 contract against Core
#177 `3dfccf389fae6ee9e54e141f5b97b0d9b7aca2ff`, as approved in programme #81.
This test tuple is candidate qualification, not certification against a released
host. Core source, production dependency locks and the runtime updater protocol
are separate ownership boundaries. UI and owner-run interactive acceptance remain
deferred.

The fixed plugin/theme starter renders five logical templates into ten generated
files, plus bounded header/readme version annotations. Every file is mode 100644.
The passive origin record and operator guide grant no destination paths,
permissions, ownership or future writes. Existing automation or generated-file
conflicts require manual integration. No update engine, managed receipt, API 2
fallback, formatter modification or repair is provided.

`StarterSecurityCheck::check()` is an on-demand read-only package service. Its
caller must obtain origin bytes from an identity-verified exact repository
revision. Core adoption wiring remains outstanding: this service introduces no
new host-interface method or UI. Results are `matching_advisory`,
`no_matching_known_advisory` or `unknown`; none grants execution readiness or
blocks ordinary adoption. Canonical GitHub advisory endpoint/response URLs bind
repository identity; every indexed advisory must be published and not withdrawn.
No prose matching, background scanning, cache or write occurs.

`tests/fixtures/api3-producer` contains exact bytes downloaded from producer B
Actions run 36435273347 attempt 1, artifact 10975406751, producer commit
`37fcee9c6707747d2b2cba5eff90a22b8f9e5968`. `ProducerExchangeTest` verifies the
9996-byte ZIP, SHA-256
`f97165fc884770319f4a53d5ae3377adc94821b7bd5ed439a4023c426d92ba23`, manifest and
all ten plugin/theme render digests against the producer envelope. Numeric
release/asset identities are explicitly simulated transport fixtures: this is
actual producer-byte/consumer convergence, not published transport or installed
end-to-end acceptance. Shared Profile B is pinned at
`63c4a4b192bbb4cf203dab281b75a0907e85c3a9`.
