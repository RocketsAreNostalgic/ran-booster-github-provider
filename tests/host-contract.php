<?php

declare(strict_types=1);

use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot ? '' : rtrim( $coreRoot, '/\\' );
if ( '' === $coreRoot || ! is_file( $coreRoot . '/autoload.php' ) ) {
	throw new RuntimeException( 'RAN_BOOSTER_CORE_PATH must point at the exact certified Booster checkout.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require $coreRoot . '/autoload.php';

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
 * @param list<array{0:string,1:string}> $expectedParameters
 */
function ran_booster_github_provider_assert_method( string $interfaceName, string $method, array $expectedParameters, string $expectedReturn ): void {
	$reflection = new ReflectionMethod( $interfaceName, $method );
	$parameters = $reflection->getParameters();
	if ( count( $expectedParameters ) !== count( $parameters ) ) {
		throw new RuntimeException( "Unexpected parameter count for {$interfaceName}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	foreach ( $expectedParameters as $index => $expectedParameter ) {
		$parameter = $parameters[ $index ];
		if ( $expectedParameter[0] !== $parameter->getName() || $expectedParameter[1] !== ran_booster_github_provider_type_name( $parameter->getType() ) ) {
			throw new RuntimeException( "Unexpected parameter contract for {$interfaceName}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
		}
	}

	if ( $expectedReturn !== ran_booster_github_provider_type_name( $reflection->getReturnType() ) ) {
		throw new RuntimeException( "Unexpected return contract for {$interfaceName}::{$method}()." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}
}

$contracts = array(
	RepositoryProvider::class => array(
		'getMetadata'            => array( array(), ProviderMetadata::class ),
		'getProviderDiagnostics' => array( array(), ProviderDiagnostics::class ),
		'resolveRepository'      => array( array( array( 'request', RepositoryLookupRequest::class ) ), RepositoryDescriptor::class ),
		'prepareArchive'         => array( array( array( 'request', ArchiveRequest::class ) ), PreparedArchive::class ),
	),
	RepositoryReleaseArtifact::class => array(
		'discard'       => array( array(), 'bool' ),
		'handoffToCore' => array( array(), RepositoryReleaseArtifactCustody::class ),
		'version'       => array( array(), 'string' ),
		'packageRoot'   => array( array(), 'string' ),
		'mainFile'      => array( array(), 'string' ),
		'identifier'    => array( array( array( 'packageType', 'string' ) ), 'string' ),
	),
	RepositoryReleaseArtifactCustody::class => array(
		'inspect'     => array( array( array( 'inspection', 'callable' ) ), 'mixed' ),
		'discard'     => array( array(), 'bool' ),
		'resolvedRef' => array( array(), 'string' ),
		'version'     => array( array(), 'string' ),
		'size'        => array( array(), 'int' ),
		'sha256'      => array( array(), 'string' ),
	),
	RepositoryReleaseWorkflowManagementV2::class => array(
		'workflowStatus'        => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ) ), RepositoryReleaseWorkflowStatus::class ),
		'workflowPreview'       => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'key', 'string' ) ), '?' . RepositoryReleaseWorkflowPreview::class ),
		'workflowInspect'       => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'channel', 'string' ), array( 'preflight', RepositoryReleaseWorkflowPreflight::class ), array( 'credentialId', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflowSetup'         => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'key', 'string' ), array( 'confirmation', 'string' ), array( 'preflight', RepositoryReleaseWorkflowPreflight::class ), array( 'credentialId', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflowOutcome'       => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'credentialId', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflowInspectUpdate' => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'credentialId', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
		'workflowSetupUpdate'   => array( array( array( 'target', RepositoryReleaseWorkflowTarget::class ), array( 'key', 'string' ), array( 'confirmation', 'string' ), array( 'credentialId', '?string' ) ), RepositoryReleaseWorkflowResult::class ),
	),
);

foreach ( $contracts as $interfaceName => $methods ) {
	if ( ! interface_exists( $interfaceName ) ) {
		throw new RuntimeException( "Required Booster host contract is unavailable: {$interfaceName}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	$actualMethods   = get_class_methods( $interfaceName );
	$expectedMethods = array_keys( $methods );
	sort( $actualMethods );
	sort( $expectedMethods );
	if ( $actualMethods !== $expectedMethods ) {
		throw new RuntimeException( "Unexpected method set for {$interfaceName}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
	}

	foreach ( $methods as $method => $signature ) {
		ran_booster_github_provider_assert_method( $interfaceName, $method, $signature[0], $signature[1] );
	}
}

if ( 2 !== RepositoryReleaseWorkflowManagementV2::RELEASE_WORKFLOW_API_VERSION ) {
	throw new RuntimeException( 'Unexpected release-workflow API generation.' );
}
if ( ! is_subclass_of( RepositoryReleaseWorkflowManagementV2::class, ProviderCapability::class ) ) {
	throw new RuntimeException( 'Release workflow V2 must remain a provider capability.' );
}
