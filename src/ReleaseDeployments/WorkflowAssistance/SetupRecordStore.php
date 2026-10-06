<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

/** Stores exact, bounded, non-secret setup pull-request evidence. */
final class SetupRecordStore {
	private const OPTION                   = WorkflowAssistanceState::SETUP_OPTION;
	private const ASSESSMENT_OPTION        = WorkflowAssistanceState::ASSESSMENT_OPTION;
	private const FAILURE_OPTION           = WorkflowAssistanceState::FAILURE_OPTION;
	private const MAX_RECORDS              = 100;
	private const MAX_OBSERVATIONS         = 100;
	private const MAX_FAILURES             = 100;
	private const MAX_HISTORY              = 12;
	private const FIELDS                   = array(
		'schema_version',
		'operation',
		'repo_id',
		'repository',
		'package_type',
		'package_identifier',
		'source_revision',
		'default_branch',
		'base_sha',
		'setup_branch',
		'head_sha',
		'pr_number',
		'profile_id',
		'template_repo_name',
		'template_repo_id',
		'template_release_id',
		'template_tag',
		'template_commit',
		'template_asset_id',
		'template_asset_name',
		'template_asset_size',
		'template_asset_digest',
		'manifest_digest',
		'changed_files',
		'consumer_api',
		'pack_version',
		'bundle_hash',
		'changed_path_hash',
	);
	private const IDENTITY_FIELDS          = array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' );
	private const OBSERVATION_FIELDS       = array( 'kind', 'repository_id', 'package_type', 'package_identifier', 'source_revision', 'observed_at' );
	private const OBSERVATION_STATUSES     = array( 'existing_automation_detected', 'booster_setup_verified', 'no_recognisable_automation' );
	private const FAILURE_FIELDS           = array( 'operation', 'outcome_code', 'failure_stage', 'package_type', 'package_identifier', 'source_revision', 'repository_id', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'recorded_at' );
	private const FAILURE_STAGES           = array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' );
	private const FAILURE_DIAGNOSTIC_CODES = array( 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous', 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );

	private ?string $claim_token      = null;
	private ?string $claim_connection = null;
	/** @return array<string,mixed>|null */
	public function find( string $repository_id ): ?array {
		$raw = $this->raw( $repository_id );
		if ( null === $raw || 3 !== ( $raw['schema_version'] ?? null ) ) {
			return null;
		}
		$record = $this->normalize( $raw );
		return null !== $record && hash_equals( $repository_id, $record['repo_id'] ) ? $record : null;
	}
	/** Any existing value owns its repository key, including unknown or malformed evidence. */
	public function occupied( string $repository_id ): bool {
		if ( ! $this->text( $repository_id, 191 ) ) {
			return false;
		}
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && array_key_exists( $repository_id, $all );
	}
	/**
	 * Serialize setup and the shared record write before any provider mutation.
	 *
	 * @return string|null Opaque exact-owner claim.
	 * @phpstan-impure Reads and mutates shared connection-backed lock state.
	 */
	public function claim( string $repository_id, string $type, string $identifier, int $revision ): ?string {
		$this->has_active_claim();
		if ( ! $this->number( $repository_id ) || ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! $this->text( $identifier, 255 ) || $revision < 1 || null !== $this->claim_token ) {
			return null;
		}
		$claim = bin2hex( random_bytes( 16 ) );
		if ( ! $this->acquire_claim_lock() ) {
			return null;
		}
		$this->claim_token      = $claim;
		$this->claim_connection = $this->connection_fingerprint();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
		if ( $this->occupied( $repository_id ) ) {
			$this->release_claim( $repository_id, $claim );
			return null;
		}
		return $claim;
	}

	/** Release only the exact connection-local lock held by this store instance. */
	public function release_claim( string $repository_id, string $claim ): bool {
		if ( ! $this->number( $repository_id ) || ! $this->has_active_claim() || ! hash_equals( $this->claim_token, $claim ) ) {
			return false;
		}
		$released = $this->release_claim_lock();
		if ( $released ) {
			$this->claim_token      = null;
			$this->claim_connection = null;
		}
		return $released;
	}

	/** Keep a failed release claim only while its original database connection remains current. */
	private function has_active_claim(): bool {
		if ( null === $this->claim_token ) {
			return false;
		}
		if ( null !== $this->claim_connection && hash_equals( $this->claim_connection, $this->connection_fingerprint() ) ) {
			return true;
		}
		$this->claim_token      = null;
		$this->claim_connection = null;
		return false;
	}

	private function connection_fingerprint(): string {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return '';
		}
		$fingerprint = (string) spl_object_id( $wpdb );
		if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) ) {
			$fingerprint .= ':' . spl_object_id( $wpdb->dbh );
		} elseif ( isset( $wpdb->dbh ) && is_resource( $wpdb->dbh ) ) {
			$fingerprint .= ':' . get_resource_id( $wpdb->dbh );
		}
		return $fingerprint;
	}

	private function acquire_claim_lock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock serializes remote setup and the shared evidence option.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::claim_lock_name() ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private function release_claim_lock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory locks are released automatically if the database connection closes.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::claim_lock_name() ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private static function claim_lock_name(): string {
		return WorkflowAssistanceState::claim_lock_name();
	}
	/**
	 * Refresh only the monotonic Core source revision for the same exact package record.
	 *
	 * @return array<string,mixed>|null
	 * @phpstan-impure Reads and mutates shared connection-backed record state.
	 */
	public function refresh_source_revision( string $repository_id, string $type, string $identifier, int $revision ): ?array {
		$acquired = ! $this->has_active_claim();
		if ( $acquired && ! $this->acquire_claim_lock() ) {
			return null;
		}
		$readback = null;
		$released = true;
		try {
			$this->refresh_record_cache();
			$record = $this->find( $repository_id );
			if ( null !== $record && $revision > $record['source_revision']
				&& hash_equals( $type, $record['package_type'] ) && hash_equals( $identifier, $record['package_identifier'] ) ) {
				$record['source_revision'] = $revision;
				$readback                  = $this->persist_record( $record ) ? $this->find( $repository_id ) : null;
			}
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock();
			}
		}
		return $released ? $readback : null;
	}
	/** @param array<string,mixed> $record */
	public function save( array $record ): bool {
		$record = $this->normalize( $record );
		if ( null === $record ) {
			return false;
		}
		$acquired = ! $this->has_active_claim();
		if ( $acquired && ! $this->acquire_claim_lock() ) {
			return false;
		}
		$saved    = false;
		$released = true;
		try {
			$this->refresh_record_cache();
			$saved = $this->persist_record( $record );
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock();
			}
		}
		return $saved && $released;
	}

	/** @param array<string,mixed> $record */
	private function persist_record( array $record ): bool {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || count( $all ) > self::MAX_RECORDS
			|| ( ! array_key_exists( $record['repo_id'], $all ) && count( $all ) >= self::MAX_RECORDS ) ) {
			return false;
		}
		if ( array_key_exists( $record['repo_id'], $all ) ) {
			$existing = is_array( $all[ $record['repo_id'] ] ) ? $this->normalize( $all[ $record['repo_id'] ] ) : null;
			if ( null === $existing || ! hash_equals( $record['repo_id'], $existing['repo_id'] )
				|| ! hash_equals( $record['package_type'], $existing['package_type'] )
				|| ! hash_equals( $record['package_identifier'], $existing['package_identifier'] ) ) {
				return false;
			}
		}
		$all[ $record['repo_id'] ] = $record;
		update_option( self::OPTION, $all, false );
		return $this->find( $record['repo_id'] ) === $record;
	}

	private function refresh_record_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
	}
	private function refresh_failure_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::FAILURE_OPTION, 'options' );
		}
	}
	private function refresh_assessment_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::ASSESSMENT_OPTION, 'options' );
		}
	}
	/** @param array<string,mixed> $observation */
	public function save_assessment_observation( array $observation ): bool {
		$observation = $this->normalize_observation( $observation );
		if ( null === $observation ) {
			return false;
		}
		$acquired = ! $this->has_active_claim();
		if ( $acquired && ! $this->acquire_claim_lock() ) {
			return false;
		}
		$saved    = false;
		$released = true;
		try {
			$this->refresh_assessment_cache();
			$saved = $this->persist_assessment_observation( $observation );
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock();
			}
		}
		return $saved && $released;
	}
	/** @param array<string,mixed> $observation */
	private function persist_assessment_observation( array $observation ): bool {
		$all = $this->assessment_observations();
		if ( null === $all ) {
			return false;
		}
		foreach ( $all as $index => $existing ) {
			if ( $this->same_assessment_package( $observation, $existing ) ) {
				unset( $all[ $index ] );
			}
		}
		$all = array_values( $all );
		if ( count( $all ) >= self::MAX_OBSERVATIONS ) {
			$oldest = 0;
			foreach ( $all as $index => $existing ) {
				if ( $existing['observed_at'] < $all[ $oldest ]['observed_at'] ) {
					$oldest = $index;
				}
			}
			array_splice( $all, $oldest, 1 );
		}
		$all[] = $observation;
		return update_option( self::ASSESSMENT_OPTION, $all, false )
			&& $this->assessment_observation( $observation['repository_id'], $observation['package_type'], $observation['package_identifier'], $observation['source_revision'] ) === $observation;
	}
	/** @return array<string,mixed>|null */
	public function assessment_observation( string $repository_id, string $type, string $identifier, int $source_revision ): ?array {
		if ( ! $this->number( $repository_id ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->text( $identifier, 255 ) || $source_revision < 1 ) {
			return null;
		}
		$all = $this->assessment_observations();
		if ( null === $all ) {
			return null;
		}
		foreach ( $all as $observation ) {
			if ( $repository_id === $observation['repository_id'] && $type === $observation['package_type']
				&& $identifier === $observation['package_identifier'] && $source_revision === $observation['source_revision'] ) {
				return $observation;
			}
		}
		return null;
	}
	/** @param array<string,mixed> $failure */
	public function record_failure( array $failure ): bool {
		$failure = $this->normalize_failure( $failure );
		if ( null === $failure ) {
			return false;
		}
		$acquired = ! $this->has_active_claim();
		if ( $acquired && ! $this->acquire_claim_lock() ) {
			return false;
		}
		$recorded = false;
		$released = true;
		try {
			$this->refresh_failure_cache();
			$recorded = $this->persist_failure( $failure );
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock();
			}
		}
		return $recorded && $released;
	}
	/** @param array<string,mixed> $failure */
	private function persist_failure( array $failure ): bool {
		$history = get_option( self::FAILURE_OPTION, array() );
		if ( ! is_array( $history ) || ! array_is_list( $history ) || count( $history ) > self::MAX_FAILURES ) {
			return false;
		}
		foreach ( $history as $index => $entry ) {
			$entry = is_array( $entry ) ? $this->normalize_failure( $entry ) : null;
			if ( null === $entry ) {
				return false;
			}
			$history[ $index ] = $entry;
		}
		$history[] = $failure;
		$history   = array_slice( $history, -self::MAX_FAILURES );
		if ( ! update_option( self::FAILURE_OPTION, $history, false ) ) {
			return false;
		}
		$readback = $this->failure_history( $failure['repository_id'], $failure['package_type'], $failure['package_identifier'], $failure['source_revision'] );
		return in_array( $failure, $readback, true );
	}
	/** @return list<array<string,mixed>> */
	public function failure_history( string $repository_id, string $type, string $identifier, int $source_revision ): array {
		if ( ! $this->number( $repository_id ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->text( $identifier, 255 ) || $source_revision < 1 ) {
			return array();
		}
		$history = get_option( self::FAILURE_OPTION, array() );
		if ( ! is_array( $history ) || ! array_is_list( $history ) || count( $history ) > self::MAX_FAILURES ) {
			return array();
		}
		$matched = array();
		foreach ( $history as $entry ) {
			$entry = is_array( $entry ) ? $this->normalize_failure( $entry ) : null;
			if ( null === $entry ) {
				return array();
			}
			if ( hash_equals( $repository_id, $entry['repository_id'] ) && hash_equals( $type, $entry['package_type'] )
				&& hash_equals( $identifier, $entry['package_identifier'] ) && $source_revision === $entry['source_revision'] ) {
				$matched[] = $entry;
			}
		}
		return array_slice( $matched, -self::MAX_HISTORY );
	}
	/** @return array<string,mixed>|null */
	private function raw( string $repository_id ): ?array {
		if ( ! $this->text( $repository_id, 191 ) ) {
			return null;
		}
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && count( $all ) <= self::MAX_RECORDS && is_array( $all[ $repository_id ] ?? null ) ? $all[ $repository_id ] : null;
	}
	/** @param array<string,mixed> $raw @return array<string,mixed>|null */
	private function normalize( array $raw ): ?array {
		if ( array_keys( $raw ) !== self::FIELDS || 3 !== ( $raw['schema_version'] ?? null )
			|| 'bootstrap' !== ( $raw['operation'] ?? null )
			|| ! $this->current_identity_valid( array_intersect_key( $raw, array_flip( self::IDENTITY_FIELDS ) ) )
			|| ! str_starts_with( $raw['setup_branch'], 'ran-booster/release-setup-v3-' )
			|| ! $this->hash( $raw['base_sha'] ?? null, 40 )
			|| ! in_array( $raw['profile_id'] ?? null, array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ), true )
			|| 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' !== ( $raw['template_repo_name'] ?? null )
			|| '1322743261' !== ( $raw['template_repo_id'] ?? null )
			|| ! $this->positive_int( $raw['template_release_id'] ?? null ) || ! $this->text_value( $raw['template_tag'] ?? null, 191 )
			|| ! $this->hash( $raw['template_commit'] ?? null, 40 ) || ! $this->positive_int( $raw['template_asset_id'] ?? null )
			|| 'ran-booster-release-bootstrap-templates.zip' !== ( $raw['template_asset_name'] ?? null )
			|| ! $this->positive_int( $raw['template_asset_size'] ?? null ) || $raw['template_asset_size'] > 2097152
			|| ! $this->hash( $raw['template_asset_digest'] ?? null, 64 ) || ! $this->hash( $raw['manifest_digest'] ?? null, 64 )
			|| ! $this->valid_changed_files( $raw['changed_files'] ?? null ) || TemplatePack::CONSUMER_API !== ( $raw['consumer_api'] ?? null )
			|| ! is_string( $raw['pack_version'] ?? null ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $raw['pack_version'] )
			|| ! $this->hash( $raw['bundle_hash'] ?? null, 64 ) || ! $this->hash( $raw['changed_path_hash'] ?? null, 64 ) ) {
			return null;
		}
		/** @var array<string,mixed> $raw */
		return $raw;
	}
	/** @return list<array<string,mixed>>|null */
	private function assessment_observations(): ?array {
		$all = get_option( self::ASSESSMENT_OPTION, array() );
		if ( ! is_array( $all ) || ! array_is_list( $all ) || count( $all ) > self::MAX_OBSERVATIONS ) {
			return null;
		}
		$seen = array();
		foreach ( $all as $index => $observation ) {
			$observation = is_array( $observation ) ? $this->normalize_observation( $observation ) : null;
			if ( null === $observation ) {
				return null;
			}
			$key = $observation['repository_id'] . "\0" . $observation['package_type'] . "\0" . $observation['package_identifier'] . "\0" . $observation['source_revision'];
			if ( isset( $seen[ $key ] ) ) {
				return null;
			}
			$seen[ $key ]  = true;
			$all[ $index ] = $observation;
		}
		return $all;
	}
	/** @param array<string,mixed> $observation @return array<string,mixed>|null */
	private function normalize_observation( array $observation ): ?array {
		if ( array_keys( $observation ) !== self::OBSERVATION_FIELDS || ! in_array( $observation['kind'] ?? null, self::OBSERVATION_STATUSES, true )
			|| ! $this->number( $observation['repository_id'] ?? null )
			|| ! in_array( $observation['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			|| ! $this->text_value( $observation['package_identifier'] ?? null, 255 ) || ! $this->positive_int( $observation['source_revision'] ?? null )
			|| ! is_string( $observation['observed_at'] ?? null ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $observation['observed_at'] ) ) {
			return null;
		}
		/** @var array<string,mixed> $observation */
		return $observation;
	}
	/** @param array<string,mixed> $first @param array<string,mixed> $second */
	private function same_assessment_package( array $first, array $second ): bool {
		return $first['repository_id'] === $second['repository_id'] && $first['package_type'] === $second['package_type']
			&& $first['package_identifier'] === $second['package_identifier'];
	}
	/** @param array<string,mixed> $failure @return array<string,mixed>|null */
	private function normalize_failure( array $failure ): ?array {
		if ( array_keys( $failure ) !== self::FAILURE_FIELDS
			|| ! in_array( $failure['operation'] ?? null, array( 'inspect', 'setup', 'outcome' ), true )
			|| ! is_string( $failure['outcome_code'] ?? null ) || 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $failure['outcome_code'] )
			|| ! in_array( $failure['failure_stage'] ?? null, self::FAILURE_STAGES, true )
			|| ! in_array( $failure['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			|| ! $this->text_value( $failure['package_identifier'] ?? null, 255 ) || ! $this->positive_int( $failure['source_revision'] ?? null )
			|| ! $this->number( $failure['repository_id'] ?? null )
			|| ! in_array( $failure['diagnostic_code'] ?? null, self::FAILURE_DIAGNOSTIC_CODES, true )
			|| ! is_bool( $failure['diagnostic_available'] ?? null )
			|| ! is_string( $failure['correlation_reference'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $failure['correlation_reference'] )
			|| ! is_string( $failure['recorded_at'] ?? null ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $failure['recorded_at'] ) ) {
			return null;
		}
		/** @var array<string,mixed> $failure */
		return $failure;
	}
	/** @param array<string,mixed> $raw */
	private function current_identity_valid( array $raw ): bool {
		return count( $raw ) === count( self::IDENTITY_FIELDS ) && $this->number( $raw['repo_id'] ?? null )
			&& $this->repository( $raw['repository'] ?? null ) && in_array( $raw['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			&& $this->text_value( $raw['package_identifier'] ?? null, 255 ) && $this->positive_int( $raw['source_revision'] ?? null )
			&& $this->branch( $raw['default_branch'] ?? null ) && $this->branch( $raw['setup_branch'] ?? null )
			&& str_starts_with( $raw['setup_branch'], 'ran-booster/release-setup-v3-' )
			&& $this->hash( $raw['head_sha'] ?? null, 40 ) && $this->positive_int( $raw['pr_number'] ?? null );
	}
	private function valid_changed_files( mixed $files ): bool {
		if ( ! is_array( $files ) || ! array_is_list( $files ) || array() === $files || count( $files ) > 32 ) {
			return false;
		}
		$previous = '';
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || array_keys( $file ) !== array( 'path', 'status', 'sha' )
				|| ! is_string( $file['path'] ) || '' === $file['path'] || strlen( $file['path'] ) > 512
				|| str_starts_with( $file['path'], '/' ) || str_contains( $file['path'], '\\' )
				|| 1 === preg_match( '#(?:\A|/)\.\.?(/|\z)|[\x00-\x1F\x7F]#', $file['path'] )
				|| strcmp( $previous, $file['path'] ) >= 0 || ! in_array( $file['status'], array( 'added', 'modified' ), true ) || ! $this->hash( $file['sha'], 40 ) ) {
				return false;
			}
			$previous = $file['path'];
		}
		return true;
	}

	private function repository( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $value );
	}
	private function branch( mixed $value ): bool {
		return $this->text_value( $value, 191 ) && ! str_contains( $value, '..' ) && ! str_contains( $value, '@{' )
			&& 0 === preg_match( '/[ ~^:?*\[\\\\]|]|(?:\A|\/)\.|\.(?:lock)?\z|\/\//', $value );
	}
	private function number( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value );
	}
	private function positive_int( mixed $value ): bool {
		return is_int( $value ) && $value > 0;
	}
	private function hash( mixed $value, int $length ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . $length . '}\z/D', $value );
	}
	private function text_value( mixed $value, int $limit ): bool {
		return is_string( $value ) && $this->text( $value, $limit );
	}
	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
