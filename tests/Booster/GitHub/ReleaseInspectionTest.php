<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RuntimeException;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ReleaseInspectionTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function tearDown(): void {
		NeutralReleaseUpdaterFixtures::cleanup();
	}

	public function test_maps_exact_neutral_inspection_without_exposing_provider_path(): void {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof() );
		$provider   = $this->provider();
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		$result = $provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' );

		self::assertSame( '42', $result->provider_release_id );
		self::assertSame( 'v1.2.3', $result->tag );
		self::assertSame( '1.2.3', $result->version );
		self::assertSame( str_repeat( 'a', 40 ), $result->provider_commit_id );
		self::assertSame( 'example', $result->package_root );
		self::assertSame( 'example.php', $result->main_file );
		self::assertMatchesRegularExpression( '/\Av2:[a-f0-9]{64}\z/D', $result->fingerprint );
		self::assertSame( 'https://github.com/owner/example/releases/tag/v1.2.3', $provider->release_details_url( $repository, $result->tag ) );
		foreach ( NeutralReleaseUpdaterFixtures::requests() as $request ) {
			self::assertStringNotContainsString( sys_get_temp_dir(), $request[0] );
		}
	}

	public function test_inspects_theme_identity_through_the_same_service(): void {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof( 'theme' ) );

		$result = $this->provider()->inspect_release(
			'theme',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);

		self::assertSame( 'example', $result->package_root );
		self::assertSame( 'style.css', $result->main_file );
	}

	public function test_opaque_core_identity_is_rejected_by_the_git_hub_service_without_http(): void {
		try {
			$this->provider()->inspect_release(
				'plugin',
				new RepositoryReference( 'owner/example', '123456789', false, null ),
				'release:opaque/42',
				'v1.2.3',
				'stable'
			);
			self::fail( 'GitHub must reject its non-decimal release identity.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $exception->reason );
		}

		self::assertSame( array(), NeutralReleaseUpdaterFixtures::requests() );
	}

	public function test_operational_inspection_failure_is_redacted(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub could not inspect the selected release.' );
		$this->provider()->inspect_release(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);
	}

	public function test_repository_access_failure_preserves_fallback_signal(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( 404, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RepositoryReleaseReadUnavailable::class );
		$this->expectExceptionMessage( 'GitHub release inspection access is unavailable.' );
		$this->provider()->inspect_release(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);
	}

	private function provider(): RepositoryReleaseInspector&GitHubProvider {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			},
			NeutralReleaseUpdaterFixtures::registrar()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseInspector::class, $provider );

		return $provider;
	}
}
