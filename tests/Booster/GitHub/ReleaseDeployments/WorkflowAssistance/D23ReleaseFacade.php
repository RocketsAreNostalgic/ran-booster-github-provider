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
	public function nonce_action( string $operation, string $type, string $identifier, int $source_revision, string $channel = '' ): string {
		return 'nonce';
	}
	public function preflight( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'preflight', $type, $identifier, $expected_source_revision, $channel, $nonce );
		return $this->preflight_contract_unavailable ? null : ( $this->preflight_response ?? new ReleaseTrackingPreflight( $this->preflight_code, 'example-plugin' ) );
	}
	public function assessment_preflight( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		$this->calls[] = array( 'assessment_preflight', $type, $identifier, $expected_source_revision, $channel, $nonce );
		return $this->preflight_contract_unavailable ? null : ( $this->preflight_response ?? new ReleaseTrackingPreflight( $this->preflight_code, 'example-plugin' ) );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- Core release-tracking interface requires these parameters; this fixture returns a fixed unused-operation result.
	public function enable( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- Core release-tracking interface requires these parameters; this fixture returns a fixed unused-operation result.
	public function change_channel( string $type, string $identifier, int $expected_source_revision, string $channel, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- Core release-tracking interface requires these parameters; this fixture returns a fixed unused-operation result.
	public function refresh( string $type, string $identifier, int $expected_source_revision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- Core release-tracking interface requires these parameters; this fixture returns a fixed unused-operation result.
	public function return_to_branch( string $type, string $identifier, int $expected_source_revision, string $nonce ): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed( 'unused', 'unused' );
	}
}
