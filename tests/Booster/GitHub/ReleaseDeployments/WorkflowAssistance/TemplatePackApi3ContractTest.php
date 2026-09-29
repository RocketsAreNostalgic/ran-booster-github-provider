<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture as Fixture;

require_once dirname( __DIR__, 5 ) . '/src/ReleaseDeployments/WorkflowAssistance/TemplatePack.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

/** Native ZIP tests of the candidate reader; these fixtures are not producer qualification. */
final class TemplatePackApi3ContractTest extends TestCase {
	public function testOnlyApi3RendersBothProfilesDeterministically(): void {
		$archive = Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['pack'];
		self::assertSame( 3, TemplatePack::CONSUMER_API );
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$values = array(
				'PACKAGE_SLUG' => 'acorn-' . $type,
				'PHP_VERSION'  => '8.2',
			);
			$first  = $pack->render( 'source-ready-wordpress-' . $type . '/3', 'quality-workflow', $values );
			self::assertSame( 'ok', $first['code'] );
			self::assertSame( $first, $pack->render( 'source-ready-wordpress-' . $type . '/3', 'quality-workflow', $values ) );
			self::assertStringContainsString( 'acorn-' . $type, $first['content'] );
		}
	}

	public function testRejectsOldApiWithoutRenderingOrFallback(): void {
		foreach ( array( 1, 2, 4 ) as $api ) {
			$manifest                  = Fixture::manifest( $api );
			$manifest['release']['id'] = 41;
			$archive                   = Fixture::archive( $manifest );
			self::assertSame( array( 'code' => 'template_pack_incompatible' ), TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) ) );
		}
	}

	public function testRejectsDuplicateAndEscapedEquivalentJsonKeys(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact raw JSON mutation fixture.
		$bytes = json_encode( Fixture::manifest(), JSON_THROW_ON_ERROR );
		foreach ( array( '"consumer_api":2,"consumer_api":3', '"consumer_\\u0061pi":2,"consumer_api":3' ) as $duplicate ) {
			$archive = Fixture::archive( null, array( 'template-pack.json' => str_replace( '"consumer_api":3', $duplicate, $bytes ) ) );
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['code'] );
		}
	}

	public function testEmbeddedReleaseIdAndNonStringRepositoryIdAreInvalid(): void {
		$manifest                  = Fixture::manifest();
		$manifest['release']['id'] = 41;
		$this->assertInvalidManifest( $manifest );
		$manifest                     = Fixture::manifest();
		$manifest['repository']['id'] = 1322743261;
		$this->assertInvalidManifest( $manifest );
	}

	public function testFixedMemberMapRejectsRenamedMemberEvenWithMatchingDigest(): void {
		$manifest = Fixture::manifest();
		$path     = 'templates/shared/quality.yml.tmpl';
		foreach ( $manifest['profiles'] as &$profile ) {
			$profile['entries']['quality-workflow']['path'] = 'templates/shared/other.yml.tmpl';
		}
		unset( $profile );
		$archive = Fixture::archive( $manifest, array(), array( 'templates/shared/other.yml.tmpl' => Fixture::templates()[ $path ] ), '', 0100644, array( $path ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['code'] );
	}

	public function testTransportAcceptsOnlyReviewedMimeTypesAndImmutableState(): void {
		$archive = Fixture::archive();
		foreach ( array( 'application/zip', 'application/octet-stream' ) as $mime ) {
			$identity                       = Fixture::identity( $archive );
			$identity['asset_content_type'] = $mime;
			self::assertSame( 'ok', TemplatePack::fromArchive( $archive, $identity )['code'] );
		}
		foreach ( array(
			'asset_content_type' => 'text/plain',
			'release_immutable'  => false,
			'release_draft'      => true,
			'release_prerelease' => true,
			'asset_sha256'       => str_repeat( '0', 64 ),
		) as $key => $value ) {
			$identity         = Fixture::identity( $archive );
			$identity[ $key ] = $value;
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );
		}
	}

	public function testNativeZipRejectsExecutableSymlinkExtraAndUnsafeMembers(): void {
		foreach ( array( 0100755, 0120644 ) as $mode ) {
			$archive = Fixture::archive( null, array(), array(), 'templates/shared/quality.yml.tmpl', $mode );
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['code'] );
		}
		foreach ( array( 'other.txt', '../escape', 'templates/shared/quality.yml.tmpl/' ) as $path ) {
			$archive = Fixture::archive( null, array(), array( $path => 'extra' ) );
			self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['code'] );
		}
	}

	public function testClosedPlaceholderTypesAndBounds(): void {
		$archive = Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['pack'];
		foreach ( array( 'a--b', '-abc', 'abc-', str_repeat( 'a', 101 ), 'a$(id)', 'a/b' ) as $slug ) {
			self::assertSame(
				'invalid_render',
				$pack->render(
					'source-ready-wordpress-plugin/3',
					'quality-workflow',
					array(
						'PACKAGE_SLUG' => $slug,
						'PHP_VERSION'  => '8.2',
					)
				)['code']
			);
		}
		foreach ( array( '8.6', '8.2.0', '', '8.2;id' ) as $php ) {
			self::assertSame(
				'invalid_render',
				$pack->render(
					'source-ready-wordpress-plugin/3',
					'quality-workflow',
					array(
						'PACKAGE_SLUG' => 'abc',
						'PHP_VERSION'  => $php,
					)
				)['code']
			);
		}
		foreach ( array( 'nested/plugin.php', '..', 'x;id.php' ) as $path ) {
			self::assertSame(
				'invalid_render',
				$pack->render(
					'source-ready-wordpress-plugin/3',
					'build-release-script',
					array(
						'HEADER_PATH'  => $path,
						'PACKAGE_SLUG' => 'abc',
						'PACKAGE_TYPE' => 'plugin',
					)
				)['code']
			);
		}
	}

	public function testExtraFilesAreOnlyHeaderAndOptionalConventionalReadme(): void {
		$archive = Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['pack'];
		foreach ( array( '[{"type":"json","path":"package.json","jsonpath":"$.version"}]', '[{"type":"generic","path":"plugin.php"},{"type":"generic","path":"custom.txt"}]', '[{"type":"generic","path":"nested/plugin.php"}]' ) as $fragment ) {
			self::assertSame(
				'invalid_render',
				$pack->render(
					'source-ready-wordpress-plugin/3',
					'release-please-config',
					array(
						'BASE_SHA'         => Fixture::COMMIT,
						'EXTRA_FILES_JSON' => $fragment,
						'PACKAGE_SLUG'     => 'abc',
					)
				)['code']
			);
		}
		$valid = '[{"type":"generic","path":"plugin.php"},{"type":"generic","path":"readme.txt"}]';
		self::assertSame(
			'ok',
			$pack->render(
				'source-ready-wordpress-plugin/3',
				'release-please-config',
				array(
					'BASE_SHA'         => Fixture::COMMIT,
					'EXTRA_FILES_JSON' => $valid,
					'PACKAGE_SLUG'     => 'abc',
				)
			)['code']
		);
	}

	private function assertInvalidManifest( array $manifest ): void {
		$archive = Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, Fixture::identity( $archive ) )['code'] );
	}
}
