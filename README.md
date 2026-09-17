# RAN Booster GitHub Provider

GitHub integration for [RAN Booster](https://github.com/RocketsAreNostalgic/ran-booster).

This package gives Booster its built-in GitHub support. It is a Composer library, **not a WordPress plugin**.

## What it does

The package contains the GitHub-specific code Booster uses for:

- finding and resolving GitHub repositories;
- using GitHub credentials configured in Booster;
- preparing repository archives for installation and deployment;
- receiving and managing GitHub webhooks;
- GitHub Releases support for Booster's release and update features;
- GitHub-specific diagnostics and tools for setting up release workflows.

Booster itself remains responsible for the WordPress screens, storing credentials, coordinating installs and updates, and deciding how providers fit into the rest of the application.

## Do I need to install this?

Normally, no.

RAN Booster includes a tested version of this package as one of its Composer dependencies. If you are using Booster as a WordPress plugin, install and configure Booster; there is no separate GitHub Provider plugin to activate.

Direct use of this repository is mainly for development and maintenance of Booster's GitHub integration.

## Requirements

- PHP 8.2 or later
- WordPress 7.0 or later when used with Booster
- a compatible RAN Booster version

Provider versions are tied to the Booster versions they are tested with. Use the version installed by Booster rather than swapping the package version manually.

## Development

Install the locked dependencies and run the main package checks:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer check
```

The repository currently uses PHP 8.2+ and Node 24.11.0 for its development and release checks.

Some analysis and implementation tests also need a Booster checkout because the shared provider interfaces live in Booster:

```bash
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer analyze
RAN_BOOSTER_CORE_PATH=/path/to/ran-booster composer test:implementation
```

Use `composer format` to apply the repository's PHP formatter.

## Reporting issues

Report GitHub-specific problems here, including repository discovery, GitHub authentication, webhooks, GitHub Releases, or GitHub-specific diagnostics.

Report Booster application issues in the [RAN Booster repository](https://github.com/RocketsAreNostalgic/ran-booster/issues), including WordPress administration, credential storage, installs and updates, or behavior shared by multiple providers.

## Releases

See [CHANGELOG.md](CHANGELOG.md) for released changes and [RELEASING.md](RELEASING.md) for the release process.

This package is released independently from Booster, while Booster pins the provider version it ships and tests.
