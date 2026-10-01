<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

final class D23ReleaseFacade implements ReleaseTrackingFacade {
	public string $preflight_code                        = ReleaseTrackingPreflight::RELEASE_UNAVAILABLE;
	public ?ReleaseTrackingPreflight $preflight_response = null;
	public bool $preflight_contract_unavailable          = false;
	/** @var list<list<mixed>> */
	public array $calls = array();
	public function __construct( private readonly string $source = 'branch' ) {
	}
	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		$root = 'theme' === $type ? 'example-theme' : 'example-plugin';
		return new ReleaseTrackingStatus( $type, $identifier, $this->source, 3, '101', 'manual', new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/owner/example-plugin', $root ), null, $root, '1.2.3' );
	}
	public function statuses( string $type, array $identifiers ): array {
		return array( $identifiers[0] => $this->status( $type, $identifiers[0] ) );
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function nonceAction( string $operation, string $type, string $identifier, int $sourceRevision, string $channel = '' ): string {
		return 'nonce';
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function preflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
		$this->calls[] = array( 'preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );
		return $this->preflight_contract_unavailable ? null : ( $this->preflight_response ?? new ReleaseTrackingPreflight( $this->preflight_code, 'example-plugin' ) );
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function assessmentPreflight( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
		$this->calls[] = array( 'assessment_preflight', $type, $identifier, $expectedSourceRevision, $channel, $nonce );
		return $this->preflight_contract_unavailable ? null : ( $this->preflight_response ?? new ReleaseTrackingPreflight( $this->preflight_code, 'example-plugin' ) );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function enable( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function changeChannel( string $type, string $identifier, int $expectedSourceRevision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function refresh( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function returnToBranch( string $type, string $identifier, int $expectedSourceRevision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
}
