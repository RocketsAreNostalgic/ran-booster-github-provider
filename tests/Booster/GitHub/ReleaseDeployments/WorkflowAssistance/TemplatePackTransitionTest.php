<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi2Fixture.php';

/** Transition recognition is not an API-3 rendering implementation. */
final class TemplatePackTransitionTest extends TestCase {

	public function testDeterministicApi3IsIncompatibleWithEitherUploadMediaType(): void {
		$archive = TemplatePackApi2Fixture::archive( self::nextManifest() );

		foreach ( array( 'application/zip', 'application/octet-stream' ) as $mediaType ) {
			$identity                       = TemplatePackApi2Fixture::identity( $archive, '2.0.0' );
			$identity['asset_content_type'] = $mediaType;

			self::assertSame(
				array( 'code' => 'template_pack_incompatible' ),
				TemplatePack::fromArchive( $archive, $identity )
			);
		}
		self::assertSame( 2, TemplatePack::CONSUMER_API );
	}

	public function testApi2StillRequiresItsEmbeddedIdAndZipMediaType(): void {
		$manifest = TemplatePackApi2Fixture::manifest();
		$archive  = TemplatePackApi2Fixture::archive( $manifest );
		$identity = TemplatePackApi2Fixture::identity( $archive );

		self::assertSame( 'ok', TemplatePack::fromArchive( $archive, $identity )['code'] );
		$identity['asset_content_type'] = 'application/octet-stream';
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );

		$manifest['release']['id'] = 99;
		self::assertInvalidManifest( $manifest, '1.2.3' );
		unset( $manifest['release']['id'] );
		self::assertInvalidManifest( $manifest, '1.2.3' );
	}

	public function testReleaseIdOmissionIsNotAGenericUnknownApiBypass(): void {
		foreach ( array( 1, 2, 4, 0, -1, '3' ) as $api ) {
			$manifest                 = self::nextManifest();
			$manifest['consumer_api'] = $api;
			self::assertInvalidManifest( $manifest );
		}
	}

	public function testApi3RejectsTheOldIdBearingShapeEvenWithMatchingTransport(): void {
		$manifest = TemplatePackApi2Fixture::manifest( 3, '2.0.0' );
		self::assertInvalidManifest( $manifest );

		$manifest['release']['id'] = 42;
		$responses                 = self::discoveryResponses( $manifest, 'application/zip' );
		$requests                  = array();

		self::assertSame( 'template_pack_invalid', self::client( $responses, $requests )->discover()['code'] );
		self::assertCount( 6, $requests );
	}

	public function testMalformedDeterministicEnvelopeFailsClosedBeforeFallback(): void {
		$changes = array(
			array( 'schema_version', 2 ),
			array( 'pack_version', '2.0.1' ),
			array(
				'repository',
				array(
					'name' => 'other/repository',
					'id'   => TemplatePackApi2Fixture::REPOSITORY_ID,
				),
			),
			array(
				'repository',
				array(
					'name' => TemplatePackApi2Fixture::REPOSITORY,
					'id'   => '1',
				),
			),
			array(
				'release',
				array(
					'tag'    => 'v2.0.1',
					'commit' => TemplatePackApi2Fixture::COMMIT,
				),
			),
			array(
				'release',
				array(
					'tag'    => 'v2.0.0',
					'commit' => str_repeat( 'a', 40 ),
				),
			),
			array(
				'release',
				array(
					'tag'    => array(),
					'commit' => TemplatePackApi2Fixture::COMMIT,
				),
			),
			array(
				'release',
				array(
					'id'     => 0,
					'tag'    => 'v2.0.0',
					'commit' => TemplatePackApi2Fixture::COMMIT,
				),
			),
			array( 'profiles', array() ),
			array( 'profiles', array( 'future' => array( 'permissions' => array() ) ) ),
		);
		foreach ( $changes as list( $key, $value ) ) {
			$manifest         = self::nextManifest();
			$manifest[ $key ] = $value;
			self::assertInvalidManifest( $manifest );
		}
	}

	public function testFutureEnvelopeCannotBypassTransportIdentityOrArchiveDigest(): void {
		$archive = TemplatePackApi2Fixture::archive( self::nextManifest() );
		$changes = array(
			'repository_id'      => '1',
			'release_id'         => 0,
			'asset_id'           => 0,
			'asset_count'        => 2,
			'release_draft'      => true,
			'release_immutable'  => false,
			'release_prerelease' => true,
			'tag_target'         => str_repeat( 'a', 40 ),
			'release_target'     => str_repeat( 'b', 40 ),
			'asset_name'         => 'other.zip',
			'asset_state'        => 'new',
			'asset_content_type' => 'text/html',
			'asset_sha256'       => str_repeat( '0', 64 ),
			'asset_digest'       => 'sha256:' . str_repeat( '0', 64 ),
			'asset_size'         => strlen( $archive ) + 1,
		);
		foreach ( $changes as $key => $value ) {
			$identity         = TemplatePackApi2Fixture::identity( $archive, '2.0.0' );
			$identity[ $key ] = $value;
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'], $key );
		}
	}

	public function testFutureEnvelopeStillRequiresSafeArchiveMembers(): void {
		$manifest = self::nextManifest();
		$archives = array(
			TemplatePackApi2Fixture::archive( $manifest, array(), array( '../escape.txt' => 'unsafe' ) ),
			TemplatePackApi2Fixture::archive( $manifest, array(), array( 'nul.txt' => "unsafe\0" ) ),
			TemplatePackApi2Fixture::archive( $manifest, array(), array( 'encoding.txt' => "\xC3\x28" ) ),
			TemplatePackApi2Fixture::archive( $manifest, array(), array(), 'template-pack.json', 0100755 ),
			TemplatePackApi2Fixture::archive( $manifest, array(), array(), 'template-pack.json', 0120777 ),
			TemplatePackApi2Fixture::archive( $manifest, array(), array( 'bomb.txt' => str_repeat( 'A', 200000 ) ) ),
		);
		foreach ( $archives as $archive ) {
			$identity                       = TemplatePackApi2Fixture::identity( $archive, '2.0.0' );
			$identity['asset_content_type'] = 'application/octet-stream';
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );
		}
	}

	public function testDiscoveryFallsBackToExactApi2AfterEitherApi3MediaType(): void {
		foreach ( array( 'application/zip', 'application/octet-stream' ) as $mediaType ) {
			$responses = self::discoveryResponses( self::nextManifest(), $mediaType );
			$requests  = array();
			$result    = self::client( $responses, $requests )->discover();

			self::assertSame( 'ok', $result['code'] );
			self::assertTrue( $result['newer_incompatible'] );
			self::assertSame( '1.2.3', $result['pack']->packVersion() );
			self::assertSame( 41, $result['pack']->identity()['release_id'] );
			self::assertSame( 73, $result['pack']->identity()['asset_id'] );
			self::assertSame( 'application/zip', $result['pack']->identity()['asset_content_type'] );
			self::assertCount( 10, $requests );
			self::assertStringEndsWith( '/releases/42', $requests[2] );
			self::assertStringEndsWith( '/releases/assets/74', $requests[5] );
			self::assertStringEndsWith( '/releases/41', $requests[6] );
			self::assertStringEndsWith( '/releases/assets/73', $requests[9] );
		}
	}

	public function testDiscoveryWithOnlyApi3ReturnsIncompatibleWithoutAPack(): void {
		$responses    = self::discoveryResponses( self::nextManifest(), 'application/octet-stream' );
		$next         = json_decode( $responses[2]['body'], true, 512, JSON_THROW_ON_ERROR );
		$responses[1] = self::jsonResponse( array( $next ) );
		$requests     = array();

		self::assertSame(
			array( 'code' => 'template_pack_incompatible' ),
			self::client( array_slice( $responses, 0, 6 ), $requests )->discover()
		);
		self::assertCount( 6, $requests );
	}

	public function testUnknownMimeAndExtraAssetsAreNotSkippedAsIncompatible(): void {
		foreach ( array( 'mime', 'extra_asset' ) as $change ) {
			$responses = self::discoveryResponses( self::nextManifest(), 'application/octet-stream' );
			$releases  = json_decode( $responses[1]['body'], true, 512, JSON_THROW_ON_ERROR );
			if ( 'mime' === $change ) {
				$releases[0]['assets'][0]['content_type'] = 'text/plain';
			} else {
				$releases[0]['assets'][] = array( 'name' => 'checksum.txt' );
			}
			$responses[1] = self::jsonResponse( $releases );
			$requests     = array();
			self::assertSame( 'template_pack_invalid', self::client( $responses, $requests )->discover()['code'] );
			self::assertCount( 2, $requests );
		}
	}

	public function testTamperedFutureArchiveDoesNotFallBack(): void {
		$responses               = self::discoveryResponses( self::nextManifest(), 'application/octet-stream' );
		$responses[5]['body'][0] = 'X';
		$requests                = array();

		self::assertSame( 'template_pack_invalid', self::client( $responses, $requests )->discover()['code'] );
		self::assertCount( 6, $requests );
	}

	public function testInvalidNewPackDoesNotFallBackToOlderPack(): void {
		$manifest                      = self::nextManifest();
		$manifest['release']['commit'] = str_repeat( 'a', 40 );
		$responses                     = self::discoveryResponses( $manifest, 'application/octet-stream' );
		$requests                      = array();

		self::assertSame( 'template_pack_invalid', self::client( $responses, $requests )->discover()['code'] );
		self::assertCount( 6, $requests );
	}

	public function testFutureReleaseRefetchDriftIsNotIncompatibility(): void {
		foreach ( array( 'id', 'asset' ) as $change ) {
			$responses = self::discoveryResponses( self::nextManifest(), 'application/octet-stream' );
			$release   = json_decode( $responses[2]['body'], true, 512, JSON_THROW_ON_ERROR );
			if ( 'id' === $change ) {
				$release['id'] = 99;
			} else {
				$release['assets'][0]['id'] = 99;
			}
			$responses[2] = self::jsonResponse( $release );
			$requests     = array();
			self::assertSame( 'template_pack_changed', self::client( $responses, $requests )->discover()['code'] );
			self::assertCount( 3, $requests );
		}
	}

	public function testUnsupportedOctetStreamCannotBecomeAPreviewPinnedPack(): void {
		$archive                        = TemplatePackApi2Fixture::archive( self::nextManifest() );
		$identity                       = TemplatePackApi2Fixture::identity( $archive, '2.0.0' );
		$identity['asset_content_type'] = 'application/octet-stream';
		$requests                       = array();

		self::assertSame( 'template_pack_changed', self::client( array(), $requests )->exact( $identity )['code'] );
		self::assertSame( array(), $requests );
	}

	/** @return array<string, mixed> */
	private static function nextManifest(): array {
		// The payload is inert test data, not a proposed API-3 logical-file map.
		$manifest = TemplatePackApi2Fixture::manifest( 3, '2.0.0' );
		unset( $manifest['release']['id'] );

		return $manifest;
	}

	/** @param array<string, mixed> $manifest */
	private static function assertInvalidManifest( array $manifest, string $version = '2.0.0' ): void {
		$archive = TemplatePackApi2Fixture::archive( $manifest );

		self::assertSame(
			array( 'code' => 'template_pack_invalid' ),
			TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive, $version ) )
		);
	}

	/** @param array<string, mixed> $manifest @return list<array<string, mixed>> */
	private static function discoveryResponses( array $manifest, string $mediaType ): array {
		$nextArchive = TemplatePackApi2Fixture::archive( $manifest );
		$oldArchive  = TemplatePackApi2Fixture::archive();
		$next        = self::release( 42, 'v2.0.0', $nextArchive, $mediaType );
		$old         = self::release( 41, 'v1.2.3', $oldArchive, 'application/zip' );
		$responses   = array(
			self::jsonResponse(
				array(
					'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
					'full_name' => TemplatePackApi2Fixture::REPOSITORY,
				)
			),
			self::jsonResponse( array( $next, $old ) ),
		);
		foreach ( array( array( $next, $nextArchive ), array( $old, $oldArchive ) ) as list( $release, $archive ) ) {
			$responses[] = self::jsonResponse( $release );
			$responses[] = self::jsonResponse(
				array(
					'object' => array(
						'type' => 'commit',
						'sha'  => TemplatePackApi2Fixture::COMMIT,
					),
				)
			);
			$responses[] = self::jsonResponse( array( 'sha' => TemplatePackApi2Fixture::COMMIT ) );
			$responses[] = array(
				'response' => array( 'code' => 200 ),
				'body'     => $archive,
			);
		}

		return $responses;
	}

	/** @return array<string, mixed> */
	private static function release( int $id, string $tag, string $archive, string $mediaType ): array {
		return array(
			'id'               => $id,
			'tag_name'         => $tag,
			'target_commitish' => TemplatePackApi2Fixture::COMMIT,
			'draft'            => false,
			'prerelease'       => false,
			'immutable'        => true,
			'assets'           => array(
				array(
					'id'           => 32 + $id,
					'name'         => TemplatePackApi2Fixture::ASSET_NAME,
					'state'        => 'uploaded',
					'content_type' => $mediaType,
					'size'         => strlen( $archive ),
					'digest'       => 'sha256:' . hash( 'sha256', $archive ),
				),
			),
		);
	}

	/** @param array<mixed> $value @return array<string, mixed> */
	private static function jsonResponse( array $value ): array {
		return array(
			'response' => array( 'code' => 200 ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact transport fixture bytes.
			'body'     => json_encode( $value, JSON_THROW_ON_ERROR ),
		);
	}

	/** @param list<array<string, mixed>> $responses @param list<string> $requests */
	private static function client( array $responses, array &$requests ): TemplatePackRepositoryClient {
		return new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$responses, &$requests ): array {
				self::assertSame( 'GET', $method );
				self::assertTrue( $args['reject_unsafe_urls'] );
				self::assertNotEmpty( $responses );
				$requests[] = $url;

				return array_shift( $responses );
			}
		);
	}
}
