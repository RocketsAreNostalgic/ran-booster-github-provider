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

The current host contract is Provider API 10. The additive
`ProviderRegistrationContext` is feature-detected by external wrappers so the
original two-argument API-10 factory remains the compatibility floor.

## Requirements

The supported host baseline is:

- PHP 8.2 or newer;
- WordPress 7.0 or newer when used through Booster; and
- a compatible RAN Booster installation providing the public provider
  contracts.

The package is pre-release software and should be consumed through an immutable
tagged release, not a moving development branch.

## Development

The canonical local gate requires PHP 8.2+ with Composer and Node **24.11.0**.
Node is used for the maintained release-control scripts/tests; the package has
no frontend toolchain.

Install the locked development dependencies and run:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

Use `composer format` to apply PHPCBF.

The implementation tests and static analysis also verify compatibility with an
exact certified Booster checkout because the public provider contracts remain
Booster-owned. For an equivalent local pass:

```bash
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer analyze
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer test:implementation
```

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
