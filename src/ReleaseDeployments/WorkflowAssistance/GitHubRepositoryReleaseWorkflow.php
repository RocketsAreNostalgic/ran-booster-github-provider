<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use RAN\Activity\ActivityProgress;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use Throwable;

/** @internal GitHub implementation and operational owner for initial-only workflow API 4. */
final class GitHubRepositoryReleaseWorkflow {
	public function __construct( private ProviderCredentialStore $credentials, private WorkflowApplicationCoordinator $coordinator, private SetupRecordStore $records, private ?\Closure $assert_operation = null ) {}

	public function status( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
		$record     = $this->records->find( $status->provider_repository_id() );
		$exact      = null !== $record && $status->type() === $record['package_type'] && $status->identifier() === $record['package_identifier'] && $status->source_revision() === $record['source_revision'];
		$type       = null === $record ? $status->type() : $record['package_type'];
		$identifier = null === $record ? $status->identifier() : $record['package_identifier'];
		$revision   = null === $record ? $status->source_revision() : $record['source_revision'];
		return new RepositoryReleaseWorkflowStatus(
			'gh',
			$status->provider_repository_id(),
			$exact,
			$this->records->occupied( $status->provider_repository_id() ),
			$exact ? 'https://github.com/' . $record['repository'] . '/pull/' . $record['pr_number'] : '',
			$type,
			$identifier,
			$revision,
			null !== $record ? $record['operation'] : '',
			'',
			'',
			array(),
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
		$this->assert_operation?->__invoke();
		$preview = $this->coordinator->preview( $key, $status );
		$this->assert_operation?->__invoke();
		if ( null === $preview ) {
			return null;
		}
		return new RepositoryReleaseWorkflowPreview(
			$key,
			'gh',
			$status->provider_repository_id(),
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

	public function inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult {
		$this->assert_operation?->__invoke();
		if ( 'stable' !== $channel ) {
			return $this->result( $this->invalid_request() );
		}
		if ( ! $this->bootstrap_preflight( $preflight ) ) {
			return $this->result( $this->preflight_result( $preflight ) );
		}
		$token = $this->credential( $credential_id, false );
		return $this->result( $this->selected_credential_unavailable( $credential_id, $token ) ? $this->unauthorised() : $this->coordinator->inspect( $status, $channel, $preflight, $token, $progress ) );
	}
	public function setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult {
		$this->assert_operation?->__invoke();
		if ( ! $this->bootstrap_preflight( $preflight ) ) {
			return $this->result( $this->preflight_result( $preflight, $key ) );
		}
		if ( null === $this->coordinator->preview( $key, $status ) ) {
			return $this->result( $this->unauthorised( $key ) );
		}
		$token = $this->credential( $credential_id, true );
		return $this->result( '' === $token ? $this->unauthorised( $key ) : $this->coordinator->setup( $status, $key, $confirmation, $preflight, $token, $progress ) );
	}
	public function outcome( RepositoryReleaseWorkflowTarget $status, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult {
		$this->assert_operation?->__invoke();
		if ( ! $this->coordinator->has_current_record( $status ) ) {
			return $this->result( $this->invalid_request() );
		}
		$token = $this->credential( $credential_id, true );
		return $this->result( $this->selected_credential_unavailable( $credential_id, $token ) ? $this->unauthorised() : $this->coordinator->outcome( $status, $token, $progress ) );
	}
	/**
	 * @param array{code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,correlation_reference?:string} $outcome
	 */
	private function result( array $outcome ): RepositoryReleaseWorkflowResult {
		return new RepositoryReleaseWorkflowResult( $outcome['code'], $outcome['successful'], $outcome['preview_key'], $outcome['failure_stage'], $outcome['diagnostic_code'], $outcome['correlation_reference'] ?? '' );
	}
	private function bootstrap_preflight( RepositoryReleaseWorkflowPreflight $preflight ): bool {
		return in_array( $preflight->code(), array( 'ready', 'release_unavailable' ), true );
	}
	/**
	 * @return array{code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function preflight_result( RepositoryReleaseWorkflowPreflight $preflight, string $key = '' ): array {
		return array(
			'code'            => 'workflow_' . ( 'preflight_unavailable' === $preflight->code() ? 'preflight_unavailable' : $preflight->code() ),
			'successful'      => false,
			'preview_key'     => $key,
			'failure_stage'   => 'release_preflight',
			'diagnostic_code' => '' !== $preflight->reason_code() ? $preflight->reason_code() : 'preflight_contract_unavailable',
		);
	}
	/**
	 * @return array{code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function unauthorised( string $key = '' ): array {
		return array(
			'code'            => 'workflow_unauthorised',
			'successful'      => false,
			'preview_key'     => $key,
			'failure_stage'   => 'credential_authorisation',
			'diagnostic_code' => 'credential_authorisation_unavailable',
		);
	}
	/**
	 * @return array{code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function invalid_request( string $key = '' ): array {
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
			$this->assert_operation?->__invoke();
			$profile = $this->credentials->credential_profiles()[ $id ] ?? null;
			$this->assert_operation?->__invoke();
			if ( ! is_array( $profile ) || 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] ) || empty( $profile['configured'] ) ) {
				return '';
			}
			$this->assert_operation?->__invoke();
			$material = $this->credentials->credential_material( $id );
			$this->assert_operation?->__invoke();
			$secret = is_array( $material ) && is_string( $material['secret'] ?? null ) ? trim( $material['secret'] ) : '';
			return '' === $secret && $required ? '' : $secret;
		} catch ( Throwable ) {
			return ''; } }
	private function selected_credential_unavailable( ?string $id, string $token ): bool {
		return null !== $id && '' !== $id && '' === $token;
	}
	/**
	 * @return list<array{id:string,label:string}>
	 */
	private function credential_choices(): array {
		try {
			$this->assert_operation?->__invoke();
			$profiles = $this->credentials->credential_profiles();
			$this->assert_operation?->__invoke();
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
	private function workflow_url( RepositoryReleaseWorkflowTarget $status ): string {
		$url = $status->expected_update_uri();
		return 1 === preg_match( '#\Ahttps://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#D', $url ) ? $url . '/actions' : '';
	}
}
