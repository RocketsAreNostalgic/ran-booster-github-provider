<?php

declare(strict_types=1);

use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

$core_root = getenv( 'RAN_BOOSTER_CORE_PATH' );
$core_root = false === $core_root ? '' : rtrim( $core_root, '/\\' );
if ( '' === $core_root || ! is_file( $core_root . '/autoload.php' ) ) {
	throw new RuntimeException( 'RAN_BOOSTER_CORE_PATH must point at the exact certified Booster checkout.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require $core_root . '/autoload.php';

$core_plugin = file_get_contents( $core_root . '/ran-booster.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact certified source contract.
if ( ! is_string( $core_plugin )
	|| 1 !== preg_match( "/define\( 'RAN_BOOSTER_PROVIDER_API_VERSION', 13 \);/", $core_plugin )
) {
	throw new RuntimeException( 'Unexpected Provider API generation.' );
}
if ( ! class_exists( ProviderRegistrationContext::class ) ) {
	throw new RuntimeException( 'Provider API 13 registration context is unavailable.' );
}

/** @return string */
function ran_booster_github_provider_type_name( ?ReflectionType $type ): string {
	if ( ! $type instanceof ReflectionNamedType ) {
		throw new RuntimeException( 'The certified provider contract contains an unsupported composite or missing type.' );
	}

	$name = $type->getName();
	if ( $type->allowsNull() && 'mixed' !== $name && 'null' !== $name ) {
		return '?' . $name;
	}

	return $name;
}

/**
 * @param list<array{0:string,1:string}> $expected_parameters
 */
function ran_booster_github_provider_assert_method( string $interface_name, string $method, array $expected_parameters, string $expected_return ): void {
	$reflection = new ReflectionMethod( $interface_name, $method );
	$parameters = $reflection->getParameters();
	if ( count( $expected_parameters ) !== count( $parameters ) ) {
		throw new RuntimeException( "Unexpected parameter count for {$interface_name}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	foreach ( $expected_parameters as $index => $expected_parameter ) {
		$parameter = $parameters[ $index ];
		if ( $parameter->getName() !== $expected_parameter[0] || ran_booster_github_provider_type_name( $parameter->getType() ) !== $expected_parameter[1] ) {
			throw new RuntimeException( "Unexpected parameter contract for {$interface_name}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
		}
	}

	if ( ran_booster_github_provider_type_name( $reflection->getReturnType() ) !== $expected_return ) {
		throw new RuntimeException( "Unexpected return contract for {$interface_name}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}
}

$contracts = array(
	RepositoryProvider::class                    => array(
		'get_metadata'             => array( array(), ProviderMetadata::class ),
		'get_provider_diagnostics' => array( array(), ProviderDiagnostics::class ),
		'resolve_repository'       => array( array( array( 'request', RepositoryLookupRequest::class ) ), RepositoryDescriptor::class ),
		'prepare_archive'          => array( array( array( 'request', ArchiveRequest::class ) ), PreparedArchive::class ),
	),
	RepositoryReleaseArtifact::class             => array(
		'discard'         => array( array(), 'bool' ),
		'handoff_to_core' => array( array(), RepositoryReleaseArtifactCustody::class ),
		'version'         => array( array(), 'string' ),
		'package_root'    => array( array(), 'string' ),
		'main_file'       => array( array(), 'string' ),
		'identifier'      => array( array( array( 'package_type', 'string' ) ), 'string' ),
	),
	RepositoryReleaseArtifactCustody::class      => array(
		'inspect'      => array( array( array( 'inspection', 'callable' ) ), 'mixed' ),
		'discard'      => array( array(), 'bool' ),
		'resolved_ref' => array( array(), 'string' ),
		'version'      => array( array(), 'string' ),
		'size'         => array( array(), 'int' ),
		'sha256'       => array( array(), 'string' ),
	),
	RepositoryReleaseWorkflowManagementV3::class => array(
		'workflow_status'  => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ) ), RepositoryReleaseWorkflowStatus::class ),
		'workflow_preview' => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'key', 'string' ) ), '?' . RepositoryReleaseWorkflowPreview::class ),
		'workflow_inspect' => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'channel', 'string' ), array( 'preflight', RepositoryReleaseWorkflowPreflight::class ), array( 'credential_id', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflow_setup'   => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'key', 'string' ), array( 'confirmation', 'string' ), array( 'preflight', RepositoryReleaseWorkflowPreflight::class ), array( 'credential_id', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflow_outcome' => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'credential_id', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
	),
);

foreach ( $contracts as $interface_name => $methods ) {
	if ( ! interface_exists( $interface_name ) ) {
		throw new RuntimeException( "Required Booster host contract is unavailable: {$interface_name}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	$actual_methods   = get_class_methods( $interface_name );
	$expected_methods = array_keys( $methods );
	sort( $actual_methods );
	sort( $expected_methods );
	if ( $actual_methods !== $expected_methods ) {
		throw new RuntimeException( "Unexpected method set for {$interface_name}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	foreach ( $methods as $method => $signature ) {
		ran_booster_github_provider_assert_method( $interface_name, $method, $signature[0], $signature[1] );
	}
}

if ( 3 !== RepositoryReleaseWorkflowManagementV3::RELEASE_WORKFLOW_API_VERSION ) {
	throw new RuntimeException( 'Unexpected release-workflow API generation.' );
}
if ( ! is_subclass_of( RepositoryReleaseWorkflowManagementV3::class, ProviderCapability::class ) ) {
	throw new RuntimeException( 'Release workflow V3 must remain a provider capability.' );
}
