<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\Deployment\PreparedArtifact;
use RAN\Deployment\ReleaseArtifactCustodian;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RuntimeException;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ReleaseAcquisitionTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function tearDown(): void {
		NeutralReleaseUpdaterFixtures::cleanup();
	}

	public function test_provider_custody_transfers_only_through_bounded_core_copy(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue( array_merge( NeutralReleaseUpdaterFixtures::proof(), NeutralReleaseUpdaterFixtures::proof() ) );

		$artifact = $provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', $fingerprint, 'stable' );
		self::assertSame( 'example/example.php', $artifact->identifier( 'plugin' ) );
		$provider_paths = $GLOBALS['ran_booster_release_temp_paths'];
		self::assertNotEmpty( $provider_paths );
		self::assertFileExists( $provider_paths[ count( $provider_paths ) - 1 ] );

		$prepared = ReleaseArtifactCustodian::claim( $artifact->handoff_to_core() );
		self::assertInstanceOf( PreparedArtifact::class, $prepared );
		self::assertSame( str_repeat( 'a', 40 ), $prepared->getResolvedRef() );
		self::assertNotContains( $prepared->getPath(), $provider_paths );
		foreach ( $provider_paths as $path ) {
			self::assertFileDoesNotExist( $path );
		}
		self::assertSame( 0600, fileperms( $prepared->getPath() ) & 0777 );
		self::assertSame( 0700, fileperms( dirname( $prepared->getPath() ) ) & 0777 );
		$prepared->assertUnchanged();
		$directory = dirname( $prepared->getPath() );
		$prepared->cleanup();
		self::assertDirectoryDoesNotExist( $directory );
	}

	public function test_private_acquisition_resolves_credential_for_each_fresh_request_chain(): void {
		$public      = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $public, $repository );
		$credentials = new RepositoryResolverSecretsStub( array( 'private-release' => 'secret-token' ) );
		$private     = $this->provider( $credentials );
		NeutralReleaseUpdaterFixtures::queue( array_merge( NeutralReleaseUpdaterFixtures::proof(), NeutralReleaseUpdaterFixtures::proof() ) );

		$artifact = $private->acquire_release(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', true, 'private-release' ),
			'42',
			'v1.2.3',
			$fingerprint,
			'stable'
		);

		self::assertSame( array( 'private-release' ), $credentials->lookups );
		self::assertTrue( $artifact->discard() );
	}

	public function test_fingerprint_continuity_rejects_changed_release_and_cleans_provider_file(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof( version: '1.2.4', tag: 'v1.2.4' ) );

		try {
			$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.4', $fingerprint, 'stable' );
			self::fail( 'Changed prospective evidence must reject acquisition.' );
		} catch ( RepositoryReleaseAcquisitionRejected $exception ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $exception->reason );
		}

		foreach ( $GLOBALS['ran_booster_release_temp_paths'] as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	public function test_acquisition_failure_is_redacted_and_cleans_inspection_artifact(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue(
			array_merge(
				array_slice( NeutralReleaseUpdaterFixtures::proof(), 0, 4 ),
				array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) )
			)
		);

		try {
			$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', $fingerprint, 'stable' );
			self::fail( 'Operational acquisition failure must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'GitHub could not acquire the selected release.', $exception->getMessage() );
		}
		foreach ( $GLOBALS['ran_booster_release_temp_paths'] as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	private function fingerprint( GitHubProvider $provider, RepositoryReference $repository ): string {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof() );

		return $provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint;
	}

	private function provider( RepositoryResolverSecretsStub $credentials ): GitHubProvider&RepositoryReleaseAcquirer {
		$provider = GitHubProvider::create(
			$credentials,
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Test double preserves the Core or production override contract pending coordinated naming.
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			},
			NeutralReleaseUpdaterFixtures::registrar()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseAcquirer::class, $provider );

		return $provider;
	}
}
