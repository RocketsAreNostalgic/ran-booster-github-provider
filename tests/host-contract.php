<?php

declare(strict_types=1);

use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot ? '' : rtrim( $coreRoot, '/\\' );
if ( '' === $coreRoot || ! is_file( $coreRoot . '/autoload.php' ) ) {
	throw new RuntimeException( 'RAN_BOOSTER_CORE_PATH must point at the exact certified Booster checkout.' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require $coreRoot . '/autoload.php';

foreach ( array( RepositoryProvider::class, RepositoryReleaseArtifact::class, RepositoryReleaseArtifactCustody::class, RepositoryReleaseWorkflowManagementV2::class ) as $interface ) {
	if ( ! interface_exists( $interface ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CI contract failure only.
		throw new RuntimeException( "Required Booster host contract is unavailable: {$interface}" );
	}
}

$handoff = new ReflectionMethod( RepositoryReleaseArtifact::class, 'handoffToCore' );
if ( RepositoryReleaseArtifactCustody::class !== (string) $handoff->getReturnType() ) {
	throw new RuntimeException( 'Release artifact handoff is not provider-neutral.' );
}

$workflowMethods = get_class_methods( RepositoryReleaseWorkflowManagementV2::class );
if ( ! in_array( 'previewReleaseWorkflow', $workflowMethods, true ) || ! in_array( 'applyReleaseWorkflow', $workflowMethods, true ) ) {
	throw new RuntimeException( 'Release workflow V2 host contract is incomplete.' );
}
