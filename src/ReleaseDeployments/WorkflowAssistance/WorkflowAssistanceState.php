<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

/**
 * Owns the GitHub provider's local workflow-assistance storage namespace and cleanup.
 *
 * @phpstan-type PreviewClaim array{connection:object,handle:mixed,options:mixed,name:string,owner:int}
 */
final readonly class WorkflowAssistanceState {
	public const SETUP_OPTION   = 'ran_booster_github_provider_release_workflow_setup_records';
	public const PREVIEW_PREFIX = 'ran_booster_github_provider_release_workflow_preview_';
	private const MAX_PREVIEWS  = 100;

	public function __construct( private string $storage_namespace = 'bundled' ) {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/D', $storage_namespace ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub workflow storage namespace.' );
		}
	}

	public function namespace(): string {
		return $this->storage_namespace;
	}

	public function setup_option(): string {
		return 'bundled' === $this->storage_namespace ? self::SETUP_OPTION : $this->custom_prefix() . 'setup_records';
	}

	public function preview_prefix(): string {
		return 'bundled' === $this->storage_namespace ? self::PREVIEW_PREFIX : $this->custom_prefix() . 'preview_';
	}

	public function setup_lock_name(): string {
		return 'bundled' === $this->storage_namespace ? self::claim_lock_name() : $this->custom_lock_name( 'setup' );
	}

	public static function claim_lock_name(): string {
		global $wpdb;

		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'unavailable';

		return 'ran_booster_github_workflow_' . substr( hash( 'sha256', $options ), 0, 32 );
	}

	/** @param array<array-key,mixed> $preview */
	public function store_preview( string $key, array $preview, int $ttl ): bool {
		if ( ! $this->valid_preview_key( $key ) || $ttl < 1 || $ttl > 15 * MINUTE_IN_SECONDS ) {
			return false;
		}
		if ( 'bundled' === $this->storage_namespace ) {
			return set_transient( $this->preview_prefix() . $key, $preview, $ttl );
		}
		return $this->with_preview_lock(
			function ( array $claim ) use ( $key, $preview, $ttl ): bool {
				$index = $this->preview_index();
				if ( null === $index || ! $this->preview_lock_owned( $claim ) ) {
					return false;
				}
				$previous = $index;
				foreach ( $index as $existing => $expiration ) {
					$stored = $this->read_preview( $existing );
					if ( ! $this->preview_lock_owned( $claim ) ) {
						return false;
					}
					if ( $expiration <= time() || false === $stored ) {
						$this->consume_preview( $existing );
						if ( ! $this->preview_lock_owned( $claim ) || false !== $this->read_preview( $existing ) || ! $this->preview_lock_owned( $claim ) ) {
							return false;
						}
						unset( $index[ $existing ] );
					}
				}
				if ( isset( $index[ $key ] ) || count( $index ) >= self::MAX_PREVIEWS ) {
					return false;
				}
				$index[ $key ] = time() + $ttl;
				if ( ! $this->persist_preview_index( $previous, $index, $claim['name'], $claim['owner'] )
					|| ! $this->preview_lock_owned( $claim ) || get_option( $this->preview_index_option() ) !== $index || ! $this->preview_lock_owned( $claim ) ) {
					return false;
				}
				$stored = set_transient( $this->preview_prefix() . $key, $preview, $ttl );
				if ( ! $stored || ! $this->preview_lock_owned( $claim ) ) {
					// The index preceded creation; compensate only this new key after ownership loss.
					$this->consume_preview( $key );
					return false;
				}
				return true;
			}
		);
	}

	public function read_preview( string $key ): mixed {
		return $this->valid_preview_key( $key ) ? get_transient( $this->preview_prefix() . $key ) : false;
	}

	public function consume_preview( string $key ): bool {
		return $this->valid_preview_key( $key ) && delete_transient( $this->preview_prefix() . $key );
	}

	/** Custom previews are indexed so cleanup also reaches persistent object caches. */
	public function remove_ephemeral_state(): bool {
		if ( 'bundled' === $this->storage_namespace ) {
			// Legacy bundled previews have no index and expire under their existing TTL.
			return false;
		}
		return $this->with_preview_lock(
			function ( array $claim ): bool {
				$index = $this->preview_index();
				if ( null === $index || ! $this->preview_lock_owned( $claim ) ) {
					return false;
				}
				foreach ( $index as $key => $expiration ) {
					$this->consume_preview( $key );
					if ( ! $this->preview_lock_owned( $claim ) || false !== $this->read_preview( $key ) || ! $this->preview_lock_owned( $claim ) ) {
						return false;
					}
				}
				if ( ! $this->persist_preview_index( $index, null, $claim['name'], $claim['owner'] ) || ! $this->preview_lock_owned( $claim ) ) {
					return false;
				}
				$missing = new \stdClass();
				return get_option( $this->preview_index_option(), $missing ) === $missing && $this->preview_lock_owned( $claim );
			}
		);
	}

	private function custom_prefix(): string {
		return 'ran_booster_gh_wf_' . $this->storage_namespace . '_';
	}

	private function preview_index_option(): string {
		return $this->custom_prefix() . 'preview_keys';
	}

	private function valid_preview_key( string $key ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $key );
	}

	/** @return array<string,int>|null */
	private function preview_index(): ?array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $this->preview_index_option(), 'options' );
		}
		$index = get_option( $this->preview_index_option(), array() );
		if ( ! is_array( $index ) || count( $index ) > self::MAX_PREVIEWS ) {
			return null;
		}
		foreach ( $index as $key => $expiration ) {
			if ( ! is_string( $key ) || ! $this->valid_preview_key( $key ) || ! is_int( $expiration ) || $expiration < 1 ) {
				return null;
			}
		}
		return $index;
	}

	private function custom_lock_name( string $purpose ): string {
		global $wpdb;
		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'unavailable';
		return 'ran_booster_gh_wf_' . substr( hash( 'sha256', $options . "\0" . $this->storage_namespace . "\0" . $purpose ), 0, 40 );
	}

	/** @param callable(PreviewClaim):bool $operation */
	private function with_preview_lock( callable $operation ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		$connection = $wpdb;
		$handle     = $wpdb->dbh ?? null;
		$options    = $wpdb->options ?? null;
		$name       = $this->custom_lock_name( 'previews' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture the exact server connection that acquired this namespace's advisory lock.
		$acquired = $connection->get_var( $connection->prepare( 'SELECT IF(GET_LOCK(%s, 0) = 1, CONNECTION_ID(), 0)', $name ) );
		if ( '' !== trim( (string) ( $connection->last_error ?? '' ) ) || ! is_numeric( $acquired ) || (int) $acquired < 1 ) {
			return false;
		}
		$claim    = array(
			'connection' => $connection,
			'handle'     => $handle,
			'options'    => $options,
			'name'       => $name,
			'owner'      => (int) $acquired,
		);
		$result   = false;
		$released = false;
		try {
			if ( $this->preview_lock_owned( $claim ) ) {
				$result = $operation( $claim );
			}
		} finally {
			if ( $this->preview_lock_owned( $claim ) ) {
				$released = $this->release_preview_lock( $name, $claim['owner'] );
			}
		}
		return $result && $released;
	}


	/**
	 * @param PreviewClaim $claim
	 * @phpstan-impure Reads mutable connection-local server ownership after each external operation.
	 */
	private function preview_lock_owned( array $claim ): bool {
		global $wpdb;
		$connection = $claim['connection'];
		if ( $wpdb !== $connection || ( $wpdb->dbh ?? null ) !== $claim['handle'] || ( $wpdb->options ?? null ) !== $claim['options']
			|| ! method_exists( $connection, 'prepare' ) || ! method_exists( $connection, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A reconnect must not reauthorize work merely because the PHP database wrapper survived.
		$current = $connection->get_var( $connection->prepare( 'SELECT CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d', $claim['owner'], $claim['name'], $claim['owner'] ) );
		return '' === trim( (string) ( $connection->last_error ?? '' ) ) && '1' === (string) $current;
	}

	/**
	 * @param array<string,int> $previous
	 * @param array<string,int>|null $index Null removes only the exact previously read index.
	 */
	private function persist_preview_index( array $previous, ?array $index, string $name, int $owner ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$connection = $wpdb;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Match the exact scalar-array bytes written by WordPress options; noncanonical bytes refuse the conditional write.
		$before = serialize( $previous );
		if ( null === $index ) {
			$sql = $connection->prepare( 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d', $connection->options, $this->preview_index_option(), $before, $owner, $name, $owner );
		} else {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Keep the bounded preview ownership index in the existing WordPress option encoding.
			$after = serialize( $index );
			$sql   = $connection->prepare( "INSERT INTO %i (option_name, option_value, autoload) SELECT %s, %s, 'off' WHERE CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d ON DUPLICATE KEY UPDATE option_value = IF(BINARY option_value = BINARY %s AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d, %s, option_value)", $connection->options, $this->preview_index_option(), $after, $owner, $name, $owner, $before, $owner, $name, $owner, $after );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One namespace-owned option uses connection/lock-fenced compare-and-swap to prevent reconnect retries overwriting another owner's index.
		$result = $connection->query( $sql );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $this->preview_index_option(), 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return false !== $result && ( null === $index || $result > 0 || $previous === $index )
			&& '' === trim( (string) ( $connection->last_error ?? '' ) );
	}

	private function release_preview_lock( string $name, int $owner ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The caller verifies the original database connection before releasing its namespace-local preview lock.
		$release = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d, RELEASE_LOCK(%s), 0)', $owner, $name, $owner, $name ) );
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $release;
	}

	/** Remove current and retired provider-owned durable workflow-assistance state. */
	public function remove_durable_state(): bool {
		$missing = new \stdClass();
		foreach ( $this->cleanup_options() as $option ) {
			delete_option( $option );
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option, 'options' );
			}
			if ( get_option( $option, $missing ) !== $missing ) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	private function cleanup_options(): array {
		if ( 'bundled' !== $this->storage_namespace ) {
			return array( $this->setup_option() );
		}
		return array(
			self::SETUP_OPTION,
			// Retired history is cleanup-only; Core owns all new activity records.
			'ran_booster_github_provider_release_workflow_assessment_observations',
			'ran_booster_github_provider_release_workflow_failure_history',
		);
	}
}
