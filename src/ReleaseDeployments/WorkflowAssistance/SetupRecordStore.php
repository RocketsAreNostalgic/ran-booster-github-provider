<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use RAN\BoosterGitHubProvider\V1\GitHubRepositoryIdentity;

/** Stores exact, bounded, non-secret setup pull-request evidence. */
final class SetupRecordStore {
	private const MAX_RECORDS     = 100;
	private const FIELDS          = array(
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
	private const IDENTITY_FIELDS = array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' );

	private readonly WorkflowAssistanceState $state;

	public function __construct( ?WorkflowAssistanceState $state = null ) {
		$this->state = $state ?? new WorkflowAssistanceState();
	}

	public function workflow_state(): WorkflowAssistanceState {
		return $this->state;
	}

	private ?string $claim_token = null;
	/** @var array{connection:object,handle:mixed,options:mixed,name:string,owner:int}|null */
	private ?array $claim_lock = null;
	/**
	 * @return array<string,mixed>|null
	 * @phpstan-impure Reads mutable shared WordPress option state.
	 */
	public function find( string $repository_id ): ?array {
		$raw = $this->raw( $repository_id );
		if ( null === $raw || 3 !== ( $raw['schema_version'] ?? null ) ) {
			return null;
		}
		$record = $this->normalize( $raw );
		return null !== $record && hash_equals( $repository_id, $record['repo_id'] ) ? $record : null;
	}
	/**
	 * Any existing value owns its repository key, including unknown or malformed evidence.
	 *
	 * @phpstan-impure Reads mutable shared WordPress option state.
	 */
	public function occupied( string $repository_id ): bool {
		if ( ! $this->text( $repository_id, 191 ) ) {
			return false;
		}
		$all = get_option( $this->state->setup_option(), array() );
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
		$lock  = $this->acquire_claim_lock();
		if ( null === $lock ) {
			return null;
		}
		$this->claim_token = $claim;
		$this->claim_lock  = $lock;
		$this->refresh_record_cache();
		if ( $this->occupied( $repository_id ) ) {
			$this->release_claim( $repository_id, $claim );
			return null;
		}
		return $this->has_active_claim() ? $claim : null;
	}

	/** Release only the exact connection-local lock held by this store instance. */
	public function release_claim( string $repository_id, string $claim ): bool {
		if ( ! $this->number( $repository_id ) || ! $this->has_active_claim() || null === $this->claim_token || null === $this->claim_lock || ! hash_equals( $this->claim_token, $claim ) ) {
			return false;
		}
		$released = $this->release_claim_lock( $this->claim_lock );
		if ( $released ) {
			$this->claim_token = null;
			$this->claim_lock  = null;
		}
		return $released;
	}

	/** Keep a failed release claim only while its original database connection remains current. */
	private function has_active_claim(): bool {
		if ( null === $this->claim_token || null === $this->claim_lock ) {
			return false;
		}
		if ( $this->claim_lock_owned( $this->claim_lock ) ) {
			return true;
		}
		$this->claim_token = null;
		$this->claim_lock  = null;
		return false;
	}

	/**
	 * @param array{connection:object,handle:mixed,options:mixed,name:string,owner:int} $lock
	 * @phpstan-impure Reads mutable connection-local server lock ownership.
	 */
	private function claim_lock_owned( array $lock ): bool {
		global $wpdb;
		$connection = $lock['connection'];
		if ( $wpdb !== $connection || ( $wpdb->dbh ?? null ) !== $lock['handle'] || ( $wpdb->options ?? null ) !== $lock['options']
			|| ! method_exists( $connection, 'prepare' ) || ! method_exists( $connection, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify server connection identity and lock ownership via CONNECTION_ID() and IS_USED_LOCK().
		$current = $connection->get_var( $connection->prepare( 'SELECT CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d', $lock['owner'], $lock['name'], $lock['owner'] ) );
		return '' === trim( (string) ( $connection->last_error ?? '' ) ) && '1' === (string) $current;
	}

	/** @return array{connection:object,handle:mixed,options:mixed,name:string,owner:int}|null */
	private function acquire_claim_lock(): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		$connection = $wpdb;
		$handle     = $wpdb->dbh ?? null;
		$options    = $wpdb->options ?? null;
		$name       = $this->state->setup_lock_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture the exact server connection that acquired the setup advisory lock.
		$acquired = $connection->get_var( $connection->prepare( 'SELECT IF(GET_LOCK(%s, 0) = 1, CONNECTION_ID(), 0)', $name ) );
		if ( '' !== trim( (string) ( $connection->last_error ?? '' ) ) || ! is_numeric( $acquired ) || (int) $acquired < 1 ) {
			return null;
		}
		$lock = array(
			'connection' => $connection,
			'handle'     => $handle,
			'options'    => $options,
			'name'       => $name,
			'owner'      => (int) $acquired,
		);
		return $this->claim_lock_owned( $lock ) ? $lock : null;
	}

	/** @param array{connection:object,handle:mixed,options:mixed,name:string,owner:int} $lock */
	private function release_claim_lock( array $lock ): bool {
		if ( ! $this->claim_lock_owned( $lock ) ) {
			return false;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the advisory lock only on the exact server connection that acquired it.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d, RELEASE_LOCK(%s), 0)', $lock['owner'], $lock['name'], $lock['owner'], $lock['name'] ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	/**
	 * Refresh only the monotonic Core source revision for the same exact package record.
	 *
	 * @return array<string,mixed>|null
	 * @phpstan-impure Reads and mutates shared connection-backed record state.
	 */
	public function refresh_source_revision( string $repository_id, string $type, string $identifier, int $revision ): ?array {
		$active_lock = $this->has_active_claim() ? $this->claim_lock : null;
		$acquired    = null === $active_lock;
		if ( $acquired ) {
			$active_lock = $this->acquire_claim_lock();
			if ( null === $active_lock ) {
				return null;
			}
		}
		$readback = null;
		$released = true;
		try {
			$this->refresh_record_cache();
			$record = $this->find( $repository_id );
			if ( null !== $record && $revision > $record['source_revision']
				&& hash_equals( $type, $record['package_type'] ) && hash_equals( $identifier, $record['package_identifier'] )
				&& $this->claim_lock_owned( $active_lock ) ) {
				$record['source_revision'] = $revision;
				$readback                  = $this->persist_record( $record ) && $this->claim_lock_owned( $active_lock ) ? $this->find( $repository_id ) : null;
			}
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock( $active_lock );
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
		$active_lock = $this->has_active_claim() ? $this->claim_lock : null;
		$acquired    = null === $active_lock;
		if ( $acquired ) {
			$active_lock = $this->acquire_claim_lock();
			if ( null === $active_lock ) {
				return false;
			}
		}
		$saved    = false;
		$released = true;
		try {
			$this->refresh_record_cache();
			if ( $this->claim_lock_owned( $active_lock ) ) {
				$saved = $this->persist_record( $record ) && $this->claim_lock_owned( $active_lock );
			}
		} finally {
			if ( $acquired ) {
				$released = $this->release_claim_lock( $active_lock );
			}
		}
		return $saved && $released;
	}

	/** @param array<string,mixed> $record */
	private function persist_record( array $record ): bool {
		$all = get_option( $this->state->setup_option(), array() );
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
		update_option( $this->state->setup_option(), $all, false );
		return $this->find( $record['repo_id'] ) === $record;
	}

	private function refresh_record_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $this->state->setup_option(), 'options' );
		}
	}
	/** @return array<string,mixed>|null */
	private function raw( string $repository_id ): ?array {
		if ( ! $this->text( $repository_id, 191 ) ) {
			return null;
		}
		$all = get_option( $this->state->setup_option(), array() );
		return is_array( $all ) && count( $all ) <= self::MAX_RECORDS && is_array( $all[ $repository_id ] ?? null ) ? $all[ $repository_id ] : null;
	}
	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>|null
	 */
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
		return GitHubRepositoryIdentity::is_valid_repository( $value );
	}
	private function branch( mixed $value ): bool {
		return $this->text_value( $value, 191 ) && ! str_contains( $value, '..' ) && ! str_contains( $value, '@{' )
			&& 0 === preg_match( '/[ ~^:?*\[\\\\]|]|(?:\A|\/)\.|\.(?:lock)?\z|\/\//', $value );
	}
	/** @phpstan-assert-if-true =string $value */
	private function number( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value );
	}
	/** @phpstan-assert-if-true =int $value */
	private function positive_int( mixed $value ): bool {
		return is_int( $value ) && $value > 0;
	}
	private function hash( mixed $value, int $length ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . $length . '}\z/D', $value );
	}
	/** @phpstan-assert-if-true =string $value */
	private function text_value( mixed $value, int $limit ): bool {
		return is_string( $value ) && $this->text( $value, $limit );
	}
	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
