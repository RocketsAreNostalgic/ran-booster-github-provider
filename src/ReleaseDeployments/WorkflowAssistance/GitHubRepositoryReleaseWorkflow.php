<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use Throwable;

/** @internal GitHub implementation and persistence owner for initial-only workflow API 3. */
final class GitHubRepositoryReleaseWorkflow {
	public function __construct( private ProviderCredentialStore $credentials, private WorkflowApplicationCoordinator $coordinator, private SetupRecordStore $records ) {}

	public function status( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
		$record      = $this->records->find( $status->providerRepositoryId() );
		$exact       = null !== $record && $status->type() === $record['package_type'] && $status->identifier() === $record['package_identifier'] && $status->sourceRevision() === $record['source_revision'];
		$type        = null === $record ? $status->type() : $record['package_type'];
		$identifier  = null === $record ? $status->identifier() : $record['package_identifier'];
		$revision    = null === $record ? $status->sourceRevision() : $record['source_revision'];
		$observation = $this->records->assessmentObservation( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() );
		$history     = array_map( static fn ( array $entry ): array => array_intersect_key( $entry, array_flip( array( 'operation', 'outcome_code', 'failure_stage', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'recorded_at' ) ) ), $this->records->failureHistory( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() ) );
		return new RepositoryReleaseWorkflowStatus(
			'gh',
			$status->providerRepositoryId(),
			$exact,
			$this->records->occupied( $status->providerRepositoryId() ),
			$exact ? 'https://github.com/' . $record['repository'] . '/pull/' . $record['pr_number'] : '',
			$type,
			$identifier,
			$revision,
			null !== $record ? $record['operation'] : '',
			is_array( $observation ) ? $observation['kind'] : '',
			is_array( $observation ) ? $observation['observed_at'] : '',
			$history,
			$this->credential_choices(),
			array(
				array(
					'label' => 'GitHub releases',
					'url'   => 'https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases',
				),
				array(
					'label' => 'GitHub immutable releases',
					'url'   => 'https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases',
				),
			),
			$this->workflow_url( $status ),
			'Setup PR created does not mean execution readiness. Review RELEASE-STARTER.md before activating workflows; read-only Quality can run on the draft PR.'
		);
	}

	public function preview( RepositoryReleaseWorkflowTarget $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		$preview = $this->coordinator->preview( $key, $status );
		if ( null === $preview ) {
			return null;
		}
		return new RepositoryReleaseWorkflowPreview(
			$key,
			'gh',
			$status->providerRepositoryId(),
			$preview['kind'],
			$preview['preflight_channel'],
			$preview['repository'],
			array(
				'repository'      => $preview['repository'],
				'default_branch'  => $preview['default_branch'],
				'base_sha'        => $preview['base_sha'],
				'pack_version'    => $preview['pack_version'],
				'template_digest' => $preview['template_identity']['asset_sha256'],
			),
			array_map(
				static fn ( array $change ): array => array(
					'path'      => $change['path'],
					'operation' => $change['operation'],
					'digest'    => $change['sha256'],
				),
				$preview['changes']
			)
		);
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
	public function inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		if ( 'stable' !== $channel ) {
			return $this->persist( 'inspect', $status, $this->invalid_request( $status ) );
		}
		if ( ! $this->bootstrap_preflight( $preflight ) ) {
			return $this->persist( 'inspect', $status, $this->preflight_result( $status, $preflight ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
		$token = $this->credential( $credentialId, false );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
		return $this->persist( 'inspect', $status, $this->selected_credential_unavailable( $credentialId, $token ) ? $this->unauthorised( $status ) : $this->coordinator->inspect( $status, $channel, $preflight, $token ) );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
	public function setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		if ( ! $this->bootstrap_preflight( $preflight ) ) {
			return $this->persist( 'setup', $status, $this->preflight_result( $status, $preflight, $key ) );
		}
		if ( null === $this->coordinator->preview( $key, $status ) ) {
			return $this->persist( 'setup', $status, $this->unauthorised( $status, $key ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
		$token = $this->credential( $credentialId, true );
		return $this->persist( 'setup', $status, '' === $token ? $this->unauthorised( $status, $key ) : $this->coordinator->setup( $status, $key, $confirmation, $preflight, $token ) );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
	public function outcome( RepositoryReleaseWorkflowTarget $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		if ( ! $this->coordinator->hasCurrentRecord( $status ) ) {
			return $this->persist( 'outcome', $status, $this->invalid_request( $status ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
		$token = $this->credential( $credentialId, true );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public method and named-parameter compatibility pending the coordinated caller cohort.
		return $this->persist( 'outcome', $status, $this->selected_credential_unavailable( $credentialId, $token ) ? $this->unauthorised( $status ) : $this->coordinator->outcome( $status, $token ) );
	}
	private function persist( string $operation, RepositoryReleaseWorkflowTarget $status, array $outcome ): RepositoryReleaseWorkflowResult {
		$observation = match ( $outcome['code'] ) {
			'workflow_release_automation_conflict' => 'existing_automation_detected', 'workflow_release_automation_present' => 'booster_setup_verified', 'workflow_inspected' => 'no_recognisable_automation', default => '' };
		if ( '' !== $observation ) {
			$this->records->saveAssessmentObservation(
				array(
					'kind'               => $observation,
					'repository_id'      => $status->providerRepositoryId(),
					'package_type'       => $status->type(),
					'package_identifier' => $status->identifier(),
					'source_revision'    => $status->sourceRevision(),
					'observed_at'        => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
				)
			);
		}
		if ( ! $outcome['successful'] && '' !== $outcome['failure_stage'] ) {
			$reference = bin2hex( random_bytes( 16 ) );
			$available = false;
			$this->records->recordFailure(
				array(
					'operation'             => $operation,
					'outcome_code'          => $outcome['code'],
					'failure_stage'         => $outcome['failure_stage'],
					'package_type'          => $status->type(),
					'package_identifier'    => $status->identifier(),
					'source_revision'       => $status->sourceRevision(),
					'repository_id'         => $status->providerRepositoryId(),
					'diagnostic_code'       => $outcome['diagnostic_code'],
					'diagnostic_available'  => $available,
					'correlation_reference' => $reference,
					'recorded_at'           => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
				)
			);
			$outcome['correlation_reference'] = $available ? $reference : '';
		}
		return new RepositoryReleaseWorkflowResult( $outcome['code'], $outcome['successful'], $outcome['preview_key'], $outcome['failure_stage'], $outcome['diagnostic_code'], $outcome['correlation_reference'] ?? '' );
	}
	private function bootstrap_preflight( RepositoryReleaseWorkflowPreflight $preflight ): bool {
		return in_array( $preflight->code(), array( 'ready', 'release_unavailable' ), true );
	}
	private function preflight_result( RepositoryReleaseWorkflowTarget $status, RepositoryReleaseWorkflowPreflight $preflight, string $key = '' ): array {
		return array(
			'code'            => 'workflow_' . ( 'preflight_unavailable' === $preflight->code() ? 'preflight_unavailable' : $preflight->code() ),
			'successful'      => false,
			'preview_key'     => $key,
			'failure_stage'   => 'release_preflight',
			'diagnostic_code' => '' !== $preflight->reasonCode() ? $preflight->reasonCode() : 'preflight_contract_unavailable',
		);
	}
	private function unauthorised( RepositoryReleaseWorkflowTarget $status, string $key = '' ): array {
		return array(
			'code'            => 'workflow_unauthorised',
			'successful'      => false,
			'preview_key'     => $key,
			'failure_stage'   => 'credential_authorisation',
			'diagnostic_code' => 'credential_authorisation_unavailable',
		);
	}
	private function invalid_request( RepositoryReleaseWorkflowTarget $status, string $key = '' ): array {
		return array(
			'code'            => 'workflow_invalid_request',
			'successful'      => false,
			'preview_key'     => $key,
			'failure_stage'   => '',
			'diagnostic_code' => '',
		);
	}
	private function credential( ?string $id, bool $required ): string {
		if ( null === $id || '' === $id ) {
			return '';
		} try {
			$profile = $this->credentials->credentialProfiles()[ $id ] ?? null;
			if ( ! is_array( $profile ) || 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] ) || empty( $profile['configured'] ) ) {
				return '';
			} $material = $this->credentials->credentialMaterial( $id );
			$secret     = is_array( $material ) && is_string( $material['secret'] ?? null ) ? trim( $material['secret'] ) : '';
			return '' === $secret && $required ? '' : $secret;
		} catch ( Throwable ) {
			return ''; } }
	private function selected_credential_unavailable( ?string $id, string $token ): bool {
		return null !== $id && '' !== $id && '' === $token;
	}
	private function credential_choices(): array {
		try {
			$profiles = $this->credentials->credentialProfiles();
		} catch ( Throwable ) {
			return array();
		} $choices = array();
		foreach ( $profiles as $profile ) {
			if ( is_array( $profile ) && 'file' === ( $profile['source'] ?? null ) && empty( $profile['immutable'] ) && ! empty( $profile['configured'] ) && is_string( $profile['id'] ?? null ) && is_string( $profile['label'] ?? null ) && is_string( $profile['kind'] ?? null ) ) {
				$choices[] = array(
					'id'    => $profile['id'],
					'label' => $this->credential_label( $profile['label'], $profile['kind'] ),
				);
			}
		} return array_slice( $choices, 0, 16 );
	}
	private function credential_label( string $label, string $kind ): string {
		$suffix = $this->utf8_prefix( ' (' . $kind . ')', 255 );
		return $this->utf8_prefix( $label, 255 - strlen( $suffix ) ) . $suffix;
	}
	private function utf8_prefix( string $value, int $maximum_bytes ): string {
		$value = substr( $value, 0, max( 0, $maximum_bytes ) );
		while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}
		return $value;
	}
	private function repository_locator( ?array $record ): string {
		return is_array( $record ) && is_string( $record['repository'] ?? null ) ? $record['repository'] : '';
	}
	private function workflow_url( RepositoryReleaseWorkflowTarget $status ): string {
		$url = $status->expectedUpdateUri();
		return 1 === preg_match( '#\Ahttps://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#D', $url ) ? $url . '/actions' : '';
	}
}
