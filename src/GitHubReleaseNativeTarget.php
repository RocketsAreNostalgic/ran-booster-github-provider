<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use Closure;
use LogicException;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;

/** Maps a managed GitHub package onto the selected provider-neutral runtime. */
final class GitHubReleaseNativeTarget implements RepositoryReleaseNativeTarget {

	private ?object $updater = null;

	/** @var string|callable|null */
	private string|Closure|null $access_token;

	/** @var (Closure(): int)|null */
	private ?Closure $maximum_artifact_bytes;

	/** @param string|callable|null $accessToken */
	public function __construct(
		private object $registrar,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $packageType,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $metadataFile,
		private string $repository,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $providerRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string|callable|null $accessToken,
		private string $channel,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $deploymentPolicy,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?callable $maximumArtifactBytes = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| ! in_array( $deploymentPolicy, array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) ) {
			throw new LogicException( 'The GitHub release native target is incompatible.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$this->access_token = is_string( $accessToken )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			? static fn (): string => $accessToken
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			: ( null === $accessToken ? null : Closure::fromCallable( $accessToken ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$this->maximum_artifact_bytes = null === $maximumArtifactBytes ? null : Closure::fromCallable( $maximumArtifactBytes );
	}

	public function register(): bool {
		try {
			if ( null === $this->updater ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
				$method    = 'plugin' === $this->packageType ? 'plugin' : 'theme';
				$arguments = array(
					'github',
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
					$this->metadataFile,
					$this->repository,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
					$this->providerRepositoryId,
					$this->channel,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
					$this->deploymentPolicy,
					$this->access_token,
				);
				if ( null !== $this->maximum_artifact_bytes ) {
					$arguments[] = ( $this->maximum_artifact_bytes )();
				}
				$this->updater = $this->registrar->{$method}( ...$arguments );
			}
			return true === $this->updater->register();
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function status(): RepositoryReleaseNativeTargetStatus {
		if ( null === $this->updater ) {
			return new RepositoryReleaseNativeTargetStatus( false );
		}
		if ( ! is_callable( array( $this->updater, 'status' ) ) ) {
			return new RepositoryReleaseNativeTargetStatus( false, failureCode: 'github_updater_status_unavailable' );
		}

		try {
			$outer = $this->updater->status();
			if ( ! is_array( $outer ) || 5 !== count( $outer )
				|| array_diff( array_keys( $outer ), array( 'state', 'declaration_accepted', 'hooks_registered', 'code', 'native' ) ) !== array() ) {
				throw new LogicException( 'The public updater target status is incompatible.' );
			}
			if ( 'active' !== $outer['state']
				|| true !== $outer['declaration_accepted']
				|| true !== $outer['hooks_registered']
				|| 'target_active' !== $outer['code'] ) {
				if ( 'inactive' === $outer['state'] ) {
					$code = $this->status_code( $outer['code'] );

					return new RepositoryReleaseNativeTargetStatus(
						false,
						failureCode: '' === $code ? 'github_updater_status_unavailable' : 'github_updater_' . substr( $code, 0, 48 )
					);
				}

				return new RepositoryReleaseNativeTargetStatus( false );
			}
			$status = $outer['native'];
			if ( ! is_array( $status )
				|| count( $status ) !== 10
				|| array_diff(
					array_keys( $status ),
					array(
						'candidate_header_version',
						'candidate_tag',
						'candidate_validation_code',
						'candidate_version',
						'failure_code',
						'installed_version',
						'last_check',
						'offered_release_identity',
						'offered_version',
						'relationship',
					)
				) !== array() ) {
				throw new LogicException( 'The neutral updater status is incompatible.' );
			}
			if ( ! $this->valid_updater_status( $status ) ) {
				throw new LogicException( 'The neutral updater status is incompatible.' );
			}

			$candidate_code           = $this->candidate_code( $status['candidate_validation_code'] );
			$candidate_tag            = $this->status_text( $status['candidate_tag'], 100 );
			$candidate_version        = $this->status_version( $status['candidate_version'] );
			$candidate_header_version = $this->status_version( $status['candidate_header_version'] );
			if ( '' === $candidate_code || '' === $candidate_tag || '' === $candidate_version ) {
				$candidate_code           = '';
				$candidate_tag            = '';
				$candidate_version        = '';
				$candidate_header_version = '';
			}

			return new RepositoryReleaseNativeTargetStatus(
				true,
				$this->status_version( $status['offered_version'] ),
				$this->status_relationship( $status['relationship'] ),
				is_int( $status['last_check'] ) && 0 < $status['last_check'] ? $status['last_check'] : null,
				null,
				$this->status_code( $status['failure_code'] ),
				$candidate_code,
				$candidate_tag,
				$candidate_version,
				$candidate_header_version,
				$this->status_text( $status['offered_release_identity'], 191 )
			);
		} catch ( \Throwable ) {
			return new RepositoryReleaseNativeTargetStatus( false, failureCode: 'github_updater_status_unavailable' );
		}
	}

	public function refresh(): bool {
		if ( null === $this->updater || ! is_callable( array( $this->updater, 'refresh' ) ) ) {
			return false;
		}

		try {
			return true === $this->updater->refresh();
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function candidate_code( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return match ( $value ) {
			'archive_identity_verified' => 'release_identity_verified',
			'archive_version_mismatch' => 'release_version_mismatch',
			'archive_header_missing' => 'package_header_missing',
			'archive_header_duplicate' => 'package_header_ambiguous',
			'archive_metadata_identity_mismatch' => 'package_header_invalid',
			'archive_unreadable', 'archive_entry_unreadable', 'archive_header_unreadable' => 'package_archive_unreadable',
			'archive_zip_extension_unavailable' => 'package_zip_extension_unavailable',
			'archive_size_limit' => 'package_archive_too_large',
			'archive_path_unsafe', 'archive_file_identity_mismatch', 'archive_target_policy_invalid' => 'package_archive_path_unsafe',
			'archive_path_duplicate' => 'package_archive_path_duplicate',
			'archive_root_mismatch' => 'package_archive_root_invalid',
			'archive_entry_limit' => 'package_archive_entry_limit',
			'archive_update_uri_mismatch' => 'package_update_uri_invalid',
			'archive_php_requirement_incompatible', 'archive_wordpress_requirement_incompatible' => 'package_compatibility_invalid',
			default => 'github_updater_release_incompatible',
		};
	}

	/** @param array<string, mixed> $status */
	private function valid_updater_status( array $status ): bool {
		return ( null === $status['candidate_tag'] || '' !== $this->status_text( $status['candidate_tag'], 100 ) )
			&& ( null === $status['candidate_validation_code'] || '' !== $this->status_code( $status['candidate_validation_code'] ) )
			&& ( null === $status['candidate_version'] || '' !== $this->status_version( $status['candidate_version'] ) )
			&& ( null === $status['candidate_header_version'] || '' !== $this->status_version( $status['candidate_header_version'] ) )
			&& ( null === $status['failure_code'] || '' !== $this->status_code( $status['failure_code'] ) )
			&& ( null === $status['installed_version'] || '' !== $this->status_version( $status['installed_version'] ) )
			&& ( null === $status['last_check'] || ( is_int( $status['last_check'] ) && 0 < $status['last_check'] ) )
			&& ( null === $status['offered_version'] || '' !== $this->status_version( $status['offered_version'] ) )
			&& ( null === $status['offered_release_identity'] || '' !== $this->status_text( $status['offered_release_identity'], 191 ) )
			&& ( ( null === $status['offered_version'] ) === ( null === $status['offered_release_identity'] ) )
			&& ( null === $status['relationship'] || '' !== $this->status_relationship( $status['relationship'] ) );
	}

	private function status_code( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value ) ? $value : '';
	}

	private function status_relationship( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'newer', 'same', 'older', 'invalid' ), true ) ? $value : '';
	}

	private function status_text( mixed $value, int $maximum_length ): string {
		return is_string( $value ) && strlen( $value ) <= $maximum_length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	private function status_version( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value ) ? $value : '';
	}
}
