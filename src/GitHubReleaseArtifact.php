<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RuntimeException;
use Throwable;

/**
 * GitHub-owned release source retained until Core claims or discards it.
 *
 * @internal
 */
final class GitHubReleaseArtifact implements RepositoryReleaseArtifact, RepositoryReleaseArtifactCustody {

	private bool $handed_off      = false;
	private ?bool $discard_result = null;

	public function __construct(
		private object $artifact,
		private string $version,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $providerCommitId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $packageRoot,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $mainFile,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private int $artifactSize,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		int $maximumArtifactBytes,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		private string $artifactSha256
	) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| ! $this->bounded_opaque_value( $providerCommitId, 191 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $packageRoot )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $mainFile )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| $artifactSize < 1 || $maximumArtifactBytes < $artifactSize
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $artifactSha256 )
			|| ! method_exists( $artifact, 'inspect' ) || ! method_exists( $artifact, 'discard' ) ) {
			throw new RuntimeException( 'The GitHub release artifact is invalid.' );
		}
	}

	public function __destruct() {
		if ( null === $this->discard_result ) {
			try {
				$this->discard();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The synchronous caller owns the reportable cleanup postcondition.
			} catch ( Throwable ) {
				// The synchronous caller owns the reportable cleanup postcondition.
			}
		}
	}

	public function discard(): bool {
		if ( null !== $this->discard_result ) {
			return $this->discard_result;
		}
		try {
			$discarded            = true === $this->artifact->discard();
			$this->discard_result = $discarded ? true : ( $this->handed_off ? false : null );

			return $discarded;
		} catch ( Throwable $failure ) {
			if ( $this->handed_off ) {
				$this->discard_result = false;
			}
			throw $failure;
		}
	}

	public function handoff_to_core(): RepositoryReleaseArtifactCustody {
		if ( $this->handed_off || null !== $this->discard_result ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		$this->handed_off = true;

		return $this;
	}

	/** @param callable(string): mixed $inspection */
	public function inspect( callable $inspection ): mixed {
		if ( ! $this->handed_off || null !== $this->discard_result ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		return $this->artifact->inspect( $inspection );
	}

	public function resolved_ref(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
		return $this->providerCommitId;
	}

	public function size(): int {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
		return $this->artifactSize;
	}

	public function sha256(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
		return $this->artifactSha256;
	}

	public function version(): string {
		return $this->version;
	}

	public function package_root(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
		return $this->packageRoot;
	}

	public function main_file(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
		return $this->mainFile;
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
	public function identifier( string $packageType ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) ) {
			throw new RuntimeException( 'The GitHub release artifact package type is invalid.' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		return 'plugin' === $packageType
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
			? $this->packageRoot . '/' . $this->mainFile
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve promoted constructor or Core DTO property contracts.
			: $this->packageRoot;
	}

	private function bounded_opaque_value( string $value, int $maximum_bytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximum_bytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
