<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Narrow structural public-source fixtures belong with mapping cases.

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class PublicReleaseResultMappingTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	public function test_maps_public_success_and_acquires_without_an_extra_inspection(): void {
		$source     = new PublicReleaseSourceFixture(
			$this->listing(),
			$this->envelope( true, 'release_inspected', $this->facts(), 'complete' ),
			$this->envelope(
				true,
				'release_acquired',
				array(
					'inspection' => $this->facts(),
					'artifact'   => new PublicReleaseArtifactFixture( $this->archive() ),
				),
				'retained'
			)
		);
		$provider   = $this->provider( $source );
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		self::assertCount( 1, $provider->list_release_candidates( 'plugin', $repository, 'stable' )->candidates );
		self::assertSame( 'v2:' . str_repeat( 'b', 64 ), $provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint );
		$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' )->discard();
		self::assertSame( 1, $source->inspect_calls );
		self::assertSame( 1, $source->acquire_calls );
	}

	public function test_maps_reordered_public_envelopes_and_acquisition_value(): void {
		$source     = new PublicReleaseSourceFixture(
			array_reverse( $this->listing(), true ),
			array_reverse( $this->envelope( true, 'release_inspected', $this->facts(), 'complete' ), true ),
			array_reverse(
				$this->envelope(
					true,
					'release_acquired',
					array(
						'artifact'   => new PublicReleaseArtifactFixture( $this->archive() ),
						'inspection' => $this->facts(),
					),
					'retained'
				),
				true
			)
		);
		$provider   = $this->provider( $source );
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		self::assertCount( 1, $provider->list_release_candidates( 'plugin', $repository, 'stable' )->candidates );
		self::assertSame( 'v2:' . str_repeat( 'b', 64 ), $provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint );
		$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' )->discard();
	}

	public function test_maps_reordered_public_failure_envelope(): void {
		$provider = $this->provider(
			new PublicReleaseSourceFixture(
				$this->listing(),
				$this->envelope( true, 'release_inspected', $this->facts(), 'complete' ),
				array_reverse( $this->envelope( false, 'release_changed', null ), true )
			)
		);

		try {
			$provider->acquire_release( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' );
			self::fail( 'A reordered release-change failure was not mapped.' );
		} catch ( RepositoryReleaseAcquisitionRejected $failure ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $failure->reason );
		}
	}

	public function test_rejects_v1_before_the_public_source_and_maps_cleanup_before_failure(): void {
		$source     = new PublicReleaseSourceFixture( $this->listing(), $this->envelope( true, 'release_inspected', $this->facts(), 'complete' ), $this->envelope( false, 'release_changed', null, 'failed' ) );
		$provider   = $this->provider( $source );
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		try {
			$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', 'v1:' . str_repeat( 'b', 64 ), 'stable' );
			self::fail( 'v1 was accepted.' );
		} catch ( RepositoryReleaseAcquisitionRejected $failure ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $failure->reason );
			self::assertSame( 0, $source->acquire_calls ); }
		try {
			$provider->acquire_release( 'plugin', $repository, '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' );
			self::fail( 'failed cleanup was accepted.' );
		} catch ( RepositoryReleaseAcquisitionRejected $failure ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::CLEANUP_FAILED, $failure->reason ); }
	}

	public function test_rate_limit_with_null_retry_uses_existing_read_fallback(): void {
		$provider = $this->provider( new PublicReleaseSourceFixture( array_reverse( $this->envelope( false, 'rate_limited', null ), true ), $this->envelope( true, 'release_inspected', $this->facts(), 'complete' ), $this->envelope( false, 'operation_failed', null ) ) );
		$this->expectException( RepositoryReleaseReadUnavailable::class );
		$provider->list_release_candidates( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), 'stable' );
	}

	public function test_rejects_non_boolean_success_and_mismatched_inspection_facts(): void {
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		$provider   = $this->provider(
			new PublicReleaseSourceFixture(
				array(
					'ok'             => 'true',
					'code'           => 'releases_listed',
					'value'          => array(),
					'retry_after'    => null,
					'cleanup_status' => 'not_applicable',
				),
				$this->envelope( true, 'release_inspected', array_replace( $this->facts(), array( 'channel' => 'preview' ) ), 'complete' ),
				$this->envelope( false, 'operation_failed', null )
			)
		);

		try {
			$provider->list_release_candidates( 'plugin', $repository, 'stable' );
			self::fail( 'A non-boolean success result was accepted.' );
		} catch ( \RuntimeException ) {
			self::addToAssertionCount( 1 );
		}
		$provider = $this->provider(
			new PublicReleaseSourceFixture(
				$this->listing(),
				$this->envelope( true, 'release_inspected', array_replace( $this->facts(), array( 'channel' => 'preview' ) ), 'complete' ),
				$this->envelope( false, 'operation_failed', null )
			)
		);
		$this->expectException( \RuntimeException::class );
		$provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' );
	}

	public function test_discards_malformed_retained_acquisition_before_rejecting_it(): void {
		$fixture  = new PublicReleaseArtifactFixture( $this->archive() );
		$provider = $this->provider(
			new PublicReleaseSourceFixture(
				$this->listing(),
				$this->envelope( true, 'release_inspected', $this->facts(), 'complete' ),
				$this->envelope(
					true,
					'release_acquired',
					array(
						'inspection' => $this->facts(),
						'artifact'   => $fixture,
						'unexpected' => true,
					),
					'retained'
				)
			)
		);

		$this->expectException( \RuntimeException::class );
		$provider->acquire_release( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' );
	}

	private function provider( PublicReleaseSourceFixture $source ): GitHubProvider {
		return GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), new PublicReleaseRegistrarFixture( $source ) ); }
	private function envelope( bool $ok, string $code, mixed $value, string $cleanup = 'not_applicable' ): array {
		return array(
			'ok'             => $ok,
			'code'           => $code,
			'value'          => $value,
			'retry_after'    => null,
			'cleanup_status' => $cleanup,
		); }
	private function listing(): array {
		return $this->envelope(
			true,
			'releases_listed',
			array(
				'candidates'   => array(
					array(
						'release_identity'     => '42',
						'tag'                  => 'v1.2.3',
						'version'              => '1.2.3',
						'prerelease'           => false,
						'published_at'         => '2026-09-06T12:00:00Z',
						'expected_asset_names' => array( 'example.zip' ),
						'details_url'          => 'https://github.com/owner/example/releases/tag/v1.2.3',
					),
				),
				'not_modified' => false,
			)
		); }
	private function archive(): string {
		$path = tempnam( sys_get_temp_dir(), 'p3-mapping-' );
		file_put_contents( $path, 'protocol3-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact temporary structural-artifact fixture.
		return $path; }
	private function facts(): array {
		return array(
			'release_identity'       => '42',
			'tag'                    => 'v1.2.3',
			'version'                => '1.2.3',
			'commit_identity'        => str_repeat( 'a', 40 ),
			'package_root'           => 'example',
			'main_file'              => 'example.php',
			'fingerprint'            => 'v2:' . str_repeat( 'b', 64 ),
			'target_type'            => 'plugin',
			'channel'                => 'stable',
			'canonical_update_uri'   => 'https://github.com/owner/example',
			'repository_locator'     => 'owner/example',
			'repository_identity'    => '123456789',
			'maximum_artifact_bytes' => 52428800,
			'artifact_size'          => 15,
			'artifact_sha256'        => hash( 'sha256', 'protocol3-bytes' ),
		); }
}

final class PublicReleaseRegistrarFixture {
	public function __construct( private PublicReleaseSourceFixture $source ) {}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Structural release-registrar fixture accepts the real contract arguments.
	public function releases( mixed ...$arguments ): object {
		return $this->source; }
}
final class PublicReleaseSourceFixture {
	public int $inspect_calls = 0;
	public int $acquire_calls = 0;
	public function __construct( private array $release_list, private array $inspect, private array $acquire ) {} public function list(): array {
		return $this->release_list;
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Structural release-source fixture preserves the public lookup signature.
	public function inspect( string $id, string $tag ): array {
		++$this->inspect_calls;
		return $this->inspect;
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Structural release-source fixture preserves the public acquisition signature.
	public function acquire( string $id, string $tag, string $fingerprint ): array {
		++$this->acquire_calls;
		return $this->acquire; }
}
final class PublicReleaseArtifactFixture {
	public function __construct( private string $path ) {} public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	} public function discard(): bool {
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact temporary structural-artifact fixture.
		} return ! file_exists( $this->path ); }
}
