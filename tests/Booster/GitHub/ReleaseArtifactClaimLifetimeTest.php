<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture variables model isolated CLI or WordPress state; declaration prefixes remain checked.


declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Structural public-artifact fixture belongs beside its custody cases.

require_once __DIR__ . '/ReleaseArtifactFilesystemFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubReleaseArtifact;
use RAN\Deployment\ReleaseArtifactCustodian;
use RuntimeException;

final class ReleaseArtifactClaimLifetimeTest extends TestCase {
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_provider_artifact_is_copied_into_core_custody_before_provider_cleanup(): void {
		$path     = '';
		$artifact = null;

		try {
			$this->reset_filesystem_hooks();
			[ $artifact, $path ] = $this->artifact();

			$prepared = ReleaseArtifactCustodian::claim( $artifact->handoff_to_core() );
			self::assertFileDoesNotExist( $path );
			$prepared->assert_unchanged();
			$owned_path = $prepared->get_path();
			$prepared->cleanup();
			self::assertFileDoesNotExist( $owned_path );
			self::assertDirectoryDoesNotExist( dirname( $owned_path ) );
		} finally {
			$this->reset_filesystem_hooks();
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
			}
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_mkdir_collision_never_removes_preexisting_directory(): void {
		$this->reset_filesystem_hooks();
		$random    = str_repeat( "\x31", 16 );
		$directory = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only exact collision fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$before = lstat( $directory );
		self::assertIsArray( $before );
		$path = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes'] = $random;
			[ $artifact, $path ]                         = $this->artifact();
			$this->expect_handoff_failure( $artifact );

			self::assertFileDoesNotExist( $path );
			self::assertDirectoryExists( $directory );
			self::assertSame( $before, lstat( $directory ) );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only exact collision fixture cleanup.
			rmdir( $directory );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_mkdir_failure_does_not_attempt_path_cleanup(): void {
		$this->reset_filesystem_hooks();
		$random    = str_repeat( "\x32", 16 );
		$directory = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$path      = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']  = $random;
			$GLOBALS['ran_booster_custody_mkdir_failure'] = true;
			[ $artifact, $path ]                          = $this->artifact();
			$this->expect_handoff_failure( $artifact );

			self::assertFileDoesNotExist( $path );
			self::assertDirectoryDoesNotExist( $directory );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_directory_swap_before_destination_creation_leaves_replacement_untouched(): void {
		$this->reset_filesystem_hooks();
		$random        = str_repeat( "\x33", 16 );
		$directory     = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$quarantine    = $directory . '-original';
		$sentinel      = $directory . '/unrelated.txt';
		$provider_path = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']      = $random;
			$GLOBALS['ran_booster_custody_after_source_open'] = static function () use ( $directory, $quarantine, $sentinel ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test-only deterministic directory replacement.
				rename( $directory, $quarantine );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only deterministic directory replacement.
				mkdir( $directory, 0700 );
				file_put_contents( $sentinel, 'unrelated' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only replacement sentinel.
			};
				[ $artifact, $provider_path ]                 = $this->artifact();
				$this->expect_handoff_failure( $artifact, true );

				self::assertFileDoesNotExist( $provider_path );
				self::assertSame( 'unrelated', file_get_contents( $sentinel ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only replacement sentinel.
				self::assertFileDoesNotExist( $directory . '/archive.zip' );
				self::assertDirectoryExists( $quarantine );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $provider_path );
			$this->remove_exact_path( $sentinel );
			$this->remove_exact_path( $directory . '/archive.zip' );
			$this->remove_exact_directory( $directory );
			$this->remove_exact_directory( $quarantine );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_directory_identity_drift_prevents_failure_cleanup(): void {
		$this->reset_filesystem_hooks();
		$random        = str_repeat( "\x34", 16 );
		$directory     = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$archive       = $directory . '/archive.zip';
		$provider_path = '';

		try {
			$GLOBALS['ran_booster_custody_random_bytes']           = $random;
			$GLOBALS['ran_booster_custody_after_destination_open'] = static function () use ( $directory ): void {
				chmod( $directory, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only identity drift.
			};
				[ $artifact, $provider_path ]                      = $this->artifact();
				$this->expect_handoff_failure( $artifact, true );

				self::assertFileDoesNotExist( $provider_path );
				self::assertFileExists( $archive );
				self::assertSame( 'verified-release-archive', file_get_contents( $archive ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only retained fail-closed copy.
				self::assertSame( 0755, fileperms( $directory ) & 0777 );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $provider_path );
			$this->remove_exact_path( $archive );
			$this->remove_exact_directory( $directory );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_thrown_stream_close_still_attempts_core_copy_cleanup(): void {
		$this->reset_filesystem_hooks();
		$random        = str_repeat( "\x35", 16 );
		$directory     = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$archive       = $directory . '/archive.zip';
		$provider_path = $this->archive_path();

		try {
			$GLOBALS['ran_booster_custody_random_bytes']    = $random;
			$GLOBALS['ran_booster_custody_fclose_failures'] = 1;
			$digest = hash_file( 'sha256', $provider_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only artifact identity.
			self::assertIsString( $digest );
			$artifact = new GitHubReleaseArtifact(
				new StructuralReleaseArtifact( $provider_path ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php',
				strlen( 'verified-release-archive' ) - 1,
				52428800,
				$digest
			);
			$this->expect_handoff_failure( $artifact, true );

			self::assertFileDoesNotExist( $provider_path );
			self::assertFileDoesNotExist( $archive );
			self::assertDirectoryDoesNotExist( $directory );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $provider_path );
			$this->remove_exact_path( $archive );
			$this->remove_exact_directory( $directory );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_first_failed_handoff_discard_cannot_be_hidden_by_retry(): void {
		$this->reset_filesystem_hooks();
		$path = $this->archive_path();

		try {
			$source   = new StructuralReleaseArtifact( $path, array( false, true ) );
			$artifact = $this->artifact_from_source( $source );
			$this->expect_handoff_failure( $artifact, true );

			self::assertSame( 1, $source->discard_calls );
			self::assertFalse( $artifact->discard() );
			self::assertSame( 1, $source->discard_calls );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_thrown_handoff_discard_cannot_be_hidden_by_later_success(): void {
		$this->reset_filesystem_hooks();
		$path = $this->archive_path();

		try {
			$source   = new ThrowingDiscardStructuralReleaseArtifact( $path );
			$artifact = $this->artifact_from_source( $source );
			$this->expect_handoff_failure( $artifact, true );

			self::assertSame( 1, $source->discard_calls );
			self::assertFalse( $artifact->discard() );
			self::assertSame( 1, $source->discard_calls );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_post_reader_runtime_loss_removes_provisional_copy(): void {
		$this->reset_filesystem_hooks();
		$path = $this->archive_path();

		try {
			$source   = new FaultingStructuralReleaseArtifact( $path, false );
			$artifact = $this->artifact_from_source( $source );
			$this->expect_handoff_failure( $artifact );

			self::assertNotNull( $source->prepared );
			self::assertFileDoesNotExist( $source->prepared->get_path() );
			self::assertDirectoryDoesNotExist( dirname( $source->prepared->get_path() ) );
			self::assertFileDoesNotExist( $path );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_thrown_core_copy_removal_remains_cleanup_failure(): void {
		$this->reset_filesystem_hooks();
		$random        = str_repeat( "\x36", 16 );
		$directory     = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( $random );
		$archive       = $directory . '/archive.zip';
		$provider_path = $this->archive_path();

		try {
			$GLOBALS['ran_booster_custody_random_bytes'] = $random;
			$GLOBALS['ran_booster_custody_unlink_throw'] = true;
			$digest                                      = hash_file( 'sha256', $provider_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only artifact identity.
			self::assertIsString( $digest );
			$artifact = new GitHubReleaseArtifact(
				new StructuralReleaseArtifact( $provider_path ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php',
				strlen( 'verified-release-archive' ) - 1,
				52428800,
				$digest
			);
			$this->expect_handoff_failure( $artifact, true );

			self::assertFileDoesNotExist( $provider_path );
			self::assertFileExists( $archive );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $provider_path );
			$this->remove_exact_path( $archive );
			$this->remove_exact_directory( $directory );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_false_close_result_remains_cleanup_failure(): void {
		$this->reset_filesystem_hooks();
		$path = '';

		try {
			$GLOBALS['ran_booster_custody_fclose_false_results'] = 1;
			[ $artifact, $path ]                                 = $this->artifact();
			$this->expect_handoff_failure( $artifact, true );

			self::assertFileDoesNotExist( $path );
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_prepared_copy_cleanup_uncertainty_remains_reportable(): void {
		$this->reset_filesystem_hooks();
		$path = $this->archive_path();

		try {
			$source   = new FaultingStructuralReleaseArtifact( $path, true );
			$artifact = $this->artifact_from_source( $source );
			$this->expect_handoff_failure( $artifact, true );

			self::assertNotNull( $source->prepared );
			self::assertFileExists( $source->prepared->get_path() );
			self::assertTrue( $artifact->discard() );
			self::assertFileDoesNotExist( $path );
			chmod( $source->prepared->get_path(), 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only retained-copy cleanup.
			$source->prepared->cleanup();
		} finally {
			$this->reset_filesystem_hooks();
			$this->remove_exact_path( $path );
		}
	}

		/** @return array{GitHubReleaseArtifact, string} */
	private function artifact(): array {
		$path   = $this->archive_path();
		$digest = hash_file( 'sha256', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only artifact identity.
		self::assertIsString( $digest );

		return array(
			new GitHubReleaseArtifact(
				new StructuralReleaseArtifact( $path ),
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php',
				strlen( 'verified-release-archive' ),
				52428800,
				$digest
			),
			$path,
		);
	}

	private function archive_path(): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-real-release-artifact-' );
		self::assertIsString( $path );
		file_put_contents( $path, 'verified-release-archive' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only artifact.
		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.

		return $path;
	}

	private function artifact_from_source( object $source ): GitHubReleaseArtifact {
		return new GitHubReleaseArtifact( $source, '1.2.3', str_repeat( 'a', 40 ), 'example', 'example.php', strlen( 'verified-release-archive' ), 52428800, hash( 'sha256', 'verified-release-archive' ) );
	}

	private function expect_handoff_failure( GitHubReleaseArtifact $artifact, bool $cleanup_failure = false ): void {
		try {
			ReleaseArtifactCustodian::claim( $artifact->handoff_to_core() );
			self::fail( 'Unsafe custody handoff must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame(
				$cleanup_failure
					? 'The release artifact transfer could not be cleaned up safely.'
					: 'The release artifact could not be transferred to Core.',
				$exception->getMessage()
			);
		}
	}

	private function reset_filesystem_hooks(): void {
		unset(
			$GLOBALS['ran_booster_custody_random_bytes'],
			$GLOBALS['ran_booster_custody_mkdir_failure'],
			$GLOBALS['ran_booster_custody_after_source_open'],
			$GLOBALS['ran_booster_custody_after_destination_open'],
			$GLOBALS['ran_booster_custody_fclose_failures'],
			$GLOBALS['ran_booster_custody_fclose_false_results'],
			$GLOBALS['ran_booster_custody_unlink_throw']
		);
	}

	private function remove_exact_path( string $path ): void {
		if ( '' !== $path && ( is_file( $path ) || is_link( $path ) ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only exact cleanup.
		}
	}

	private function remove_exact_directory( string $directory ): void {
		if ( is_dir( $directory ) && ! is_link( $directory ) ) {
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only exact cleanup.
		}
	}
}

final class StructuralReleaseArtifact {
	public int $discard_calls = 0;

	/** @param list<bool> $discard_results */
	public function __construct( private string $path, private array $discard_results = array() ) {}

	public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	}

	public function discard(): bool {
		++$this->discard_calls;
		if ( array() !== $this->discard_results ) {
			return array_shift( $this->discard_results );
		}
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}

final class FaultingStructuralReleaseArtifact {
	public ?\RAN\Deployment\PreparedArtifact $prepared = null;

	public function __construct( private string $path, private bool $break_prepared_copy ) {}

	public function inspect( callable $reader ): mixed {
		$this->prepared = $reader( $this->path );
		if ( $this->break_prepared_copy ) {
			chmod( $this->prepared->get_path(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only prepared-copy identity drift.
		}

		throw new RuntimeException( 'Provider artifact runtime became unavailable.' );
	}

	public function discard(): bool {
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}

final class ThrowingDiscardStructuralReleaseArtifact {
	public int $discard_calls = 0;

	public function __construct( private string $path ) {}

	public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	}

	public function discard(): bool {
		++$this->discard_calls;
		if ( 1 === $this->discard_calls ) {
			throw new RuntimeException( 'The provider discard operation failed.' );
		}
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture discards its exact temporary artifact.
		}

		return ! file_exists( $this->path );
	}
}
