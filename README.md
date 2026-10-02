# RAN Booster GitHub Provider

`ran/booster-github-provider` is the first-party GitHub implementation for
[RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster). It is a
**Composer library**, not an installable WordPress plugin. Ordinary Booster
users install Booster, which pins and bundles an immutable Provider release in
its runtime archive; they do not install or activate this package separately.

## What it provides

The Provider resolves and browses GitHub repositories, prepares archives,
validates credentials, normalizes and manages webhooks, and supplies diagnostics.
It also lists, inspects and acquires GitHub releases, composes native update
targets, and offers initial release-workflow setup through the V3 capability.

Booster supplies the public provider contracts and owns registration, credential
custody, policy, deployment orchestration and administrator screens. This package
implements those contracts under `RAN\BoosterGitHubProvider\V1`; it has no
production dependency on the whole `ran/booster` Composer package. Bundling does
not grant it a private host API or extra mutation authority.

## Versions and compatibility

Published package versions are listed in
[GitHub Releases](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/releases).
This revision's Composer dependencies and host compatibility requirements are listed below.
[Composer metadata](composer.json) declares PHP and package dependencies; the
WordPress and Provider API requirements are host contracts, not Composer checks.

| Requirement | Contract |
| --- | --- |
| PHP | `^8.2` |
| WordPress host | WordPress 7.0+ through a compatible Booster host |
| Outer Provider API | Exactly `14`; not compatible with API 11, 12 or 13 |
| Release-workflow capability | Initial-only `RepositoryReleaseWorkflowManagementV3` |
| Shared repository paths | `ran/updater-support ^1.0.0-beta.4` |
| Release updater | `ran/wp-release-updater ~1.0.0-beta.9`, locked beta.9 / runtime protocol 5 |

The package version, `V1` PHP namespace, outer Provider API, workflow V3,
template-pack API 3 and updater runtime protocol are separate contracts.

The host and Provider must resolve the same released updater dependency. Composer
installs that shared dependency once; the host supplies its bootstrap registrar.
Other plugins or themes may bundle physical updater copies, whose runtime
selection belongs to the updater. Protocol-4 and protocol-5 copies cannot share
an active runtime and fail closed; upgrading this package alone does not qualify
the host's installed composition.
Do not infer host compatibility from matching version numbers.

**Published Provider compatibility is not released-Core certification.** As of
1 October 2026, Core main has adopted Provider beta.9, but Core beta.31 remains a
release proposal; the latest published Core beta.30 is not an API-13 host.
Provider CI qualifies an exact candidate host, identified in
[the contribution guide](CONTRIBUTING.md), rather than certifying whichever Core
revision is newest. Use the Provider version bundled with your chosen Booster
release. A package release alone does not qualify a different host/dependency
composition.

This is prerelease software. Pin an immutable release and retain the consuming
application's lockfile. Public contracts and prerelease persistence formats can
change between betas; earlier workflow records are not promised migration
contracts. Review [release notes](CHANGELOG.md) and the host's compatibility
contract before adopting a different version.

## Deliberate development use

For package development or a controlled host-integration experiment, clone this
repository and select the release or development revision you intend to test.
To inspect and validate a published package, choose its immutable tag from
GitHub Releases and set `provider_tag` to that exact tag before running:

```bash
git clone https://github.com/RocketsAreNostalgic/ran-booster-github-provider.git
cd ran-booster-github-provider
git checkout --detach "${provider_tag:?Set provider_tag to an immutable published tag}"
composer install --no-interaction --prefer-dist --no-progress
composer check
```

The local gate requires PHP 8.2+, Composer and Node 24.11.0. Node serves the
release-control tests, not a browser frontend. See [CONTRIBUTING.md](CONTRIBUTING.md)
for the additional required host-backed checks and exact candidate checkout.
Composer installation alone does not provide WordPress, Booster's interfaces,
registration or an operational standalone application.

A custom Composer root must declare the required VCS repositories and explicit
prerelease allowances itself: Composer does not inherit dependency repository
configuration. Use the package's metadata and the selected host's locked
composition as references, then qualify that complete composition. Do not replace
files inside an installed Booster bundle or register a second bundled Provider.

## Construction and registration

The public factory is
[`GitHubProvider::create()`](src/GitHubProvider.php); its constructor is private.
It returns the host's `RepositoryProvider` contract. The factory accepts a
provider-scoped credential store, authenticated webhook-delivery evidence,
a compatible release-updater registrar, and an optional artifact-limit callable.

The following is the factory portion of a host composition, with `$registrar`
already supplied by that composition. It is not a standalone plugin bootstrap:

```php
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryProvider;

$factory = static function (
    ProviderCredentialStore $credentials,
    AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
    ProviderRegistrationContext $registrationContext
) use ( $registrar ): RepositoryProvider {
    return GitHubProvider::create(
        $credentials,
        $deliveryEvidence,
        $registrar,
        static fn (): int => $registrationContext->maximumArtifactBytes()
    );
};
```

The host supplies these capabilities deliberately:

- `ProviderCredentialStore` and `AuthenticatedWebhookDeliveryEvidenceReader`
  are bound to the registered provider code; neither offers an arbitrary
  provider selector.
- `ProviderRegistrationContext` supplies the host-resolved artifact-size policy.
  Forward its callable lazily as above so operations use host policy; the
  context is not a service locator.
- `$registrar` is the compatible object obtained through the package-owned
  release updater's `bootstrap.php`, exposing `plugin()`, `theme()` and
  `releases()`. It is not the Provider registry or the whole Booster container.

Booster registers the returned aggregate as `gh` with
`ProviderRegistry::registerWithCredentialStore( 'gh', $factory )` before firing
`ran_booster_register_providers`, then seals the registry. An external
composition must check the exact API-13 marker and supported runtime mode before
loading the implementation. Ordinary Booster already owns `gh`: trying to
register it again is rejected before the second factory receives credentials.
The Core [external composition fixture](https://github.com/RocketsAreNostalgic/ran-booster/tree/main/tests/fixtures/ran-booster-github-provider-extension)
is a controlled integration example, not a supported second GitHub installation.

For the complete contract and lifecycle, use Core's
[Provider extension contract](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/provider-extension-contract.md),
[registration and coexistence guide](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/provider-registration-and-coexistence.md)
and [release-workflow API](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/provider-release-workflow-api.md).
Read the revision matching your host; these main-branch links describe current
development contracts.

## Passive construction and later operations

`GitHubProvider::create()` constructs collaborators and metadata. It does not
register WordPress hooks, activate a plugin, bootstrap the updater, fetch GitHub
data or write workflow state. The host supplies an already composed registrar;
construction merely stores it and the lazy policy callable.

Registration and later capability calls are separate lifecycle steps. Explicit
operations may read credentials, make network requests, persist workflow state
or register native update handling. Passive construction is not a promise that
all provider methods are side-effect free. Core retains orchestration and final
mutation authority; initial workflow assistance is not an automatic update or
repair engine.

## Support and contributing

Report provider-specific defects in [this repository's issues](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues).
Host API, registry, custody, deployment and UI issues belong in
[Booster's tracker](https://github.com/RocketsAreNostalgic/ran-booster/issues).
Follow [SECURITY.md](SECURITY.md) for private vulnerability reporting and its
fallback contact procedure; keep sensitive details out of public issues.

Maintainers should use [CONTRIBUTING.md](CONTRIBUTING.md) for quality commands,
[RELEASING.md](RELEASING.md) for publication and trust requirements, and
[AGENTS.md](AGENTS.md) for the repository engineering contract. The package is
licensed under [GPL-2.0-or-later](LICENSE).

## API14 naming candidate

This source includes the earlier 50 Core-owned interface method migrations and
completes owned parameter, property and connected consumer naming. It requires
the exact API14 Core candidate pinned in CI; prior API12/API13 tuples are not
compatible. WorkflowV3 and persisted/template identities remain unchanged.
Candidate host qualification is preparation, not released-Core certification.
See Core #167 for reviewed candidate identities and the publication/adoption
sequence. The earlier published beta.9 remains historical API12 helper evidence.
