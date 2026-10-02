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
		private string $provider_commit_id,
		private string $package_root,
		private string $main_file,
		private int $artifact_size,
		int $maximum_artifact_bytes,
		private string $artifact_sha256
	) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! $this->bounded_opaque_value( $provider_commit_id, 191 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $package_root )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $main_file )
			|| $artifact_size < 1 || $maximum_artifact_bytes < $artifact_size
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $artifact_sha256 )
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
		return $this->provider_commit_id;
	}

	public function size(): int {
		return $this->artifact_size;
	}

	public function sha256(): string {
		return $this->artifact_sha256;
	}

	public function version(): string {
		return $this->version;
	}

	public function package_root(): string {
		return $this->package_root;
	}

	public function main_file(): string {
		return $this->main_file;
	}

	public function identifier( string $package_type ): string {
		if ( ! in_array( $package_type, array( 'plugin', 'theme' ), true ) ) {
			throw new RuntimeException( 'The GitHub release artifact package type is invalid.' );
		}

		return 'plugin' === $package_type
			? $this->package_root . '/' . $this->main_file
			: $this->package_root;
	}

	private function bounded_opaque_value( string $value, int $maximum_bytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximum_bytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
