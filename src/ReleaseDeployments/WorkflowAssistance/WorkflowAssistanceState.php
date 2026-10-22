<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

/** Owns the GitHub provider's local workflow-assistance storage namespace and cleanup. */
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
			function () use ( $key, $preview, $ttl ): bool {
				$index = $this->preview_index();
				if ( null === $index ) {
						return false;
				}
				foreach ( $index as $existing => $expiration ) {
					if ( $expiration <= time() || false === $this->read_preview( $existing ) ) {
						$this->consume_preview( $existing );
						if ( false !== $this->read_preview( $existing ) ) {
							return false;
						}
						unset( $index[ $existing ] );
					}
				}
				if ( count( $index ) >= self::MAX_PREVIEWS && ! array_key_exists( $key, $index ) ) {
					return false;
				}
				$index[ $key ] = time() + $ttl;
				update_option( $this->preview_index_option(), $index, false );
				// Persist cleanup ownership before creating an externally cached transient.
				return get_option( $this->preview_index_option() ) === $index
				&& set_transient( $this->preview_prefix() . $key, $preview, $ttl );
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
			function (): bool {
				$index = $this->preview_index();
				if ( null === $index ) {
						return false;
				}
				foreach ( $index as $key => $expiration ) {
					$this->consume_preview( $key );
					if ( false !== $this->read_preview( $key ) ) {
						return false;
					}
				}
				delete_option( $this->preview_index_option() );
				$missing = new \stdClass();
				return get_option( $this->preview_index_option(), $missing ) === $missing;
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

	/** @param callable():bool $operation */
	private function with_preview_lock( callable $operation ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$connection = $wpdb;
		$handle     = $wpdb->dbh ?? null;
		$name       = $this->custom_lock_name( 'previews' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialize only this namespace's bounded preview cleanup index on the current database connection.
		$acquired = $connection->get_var( $connection->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '' !== trim( (string) ( $connection->last_error ?? '' ) ) || '1' !== (string) $acquired ) {
			return false;
		}
		$result   = false;
		$released = false;
		try {
			if ( $wpdb === $connection && ( $wpdb->dbh ?? null ) === $handle ) {
				$result = $operation();
			}
		} finally {
			if ( $wpdb === $connection && ( $wpdb->dbh ?? null ) === $handle ) {
				$released = $this->release_preview_lock( $name );
			}
		}
		return $result && $released;
	}

	private function release_preview_lock( string $name ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The caller verifies the original database connection before releasing its namespace-local preview lock.
		$release = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
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
