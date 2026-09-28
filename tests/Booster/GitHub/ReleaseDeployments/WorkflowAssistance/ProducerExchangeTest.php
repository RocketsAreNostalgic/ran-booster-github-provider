<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

// phpcs:disable WordPress.WP.AlternativeFunctions -- Exact local producer evidence; no network or execution.
final class ProducerExchangeTest extends TestCase {
	public function testActualQualifiedProducerZipAndAllTenRenderedDigests(): void {
		$dir   = dirname( __DIR__, 4 ) . '/fixtures/api3-producer';
		$bytes = file_get_contents( $dir . '/ran-booster-release-bootstrap-templates.zip' );
		$e     = json_decode( file_get_contents( $dir . '/producer-exchange.json' ), true, 512, JSON_THROW_ON_ERROR );
		if ( strlen( $bytes ) !== 9996 || hash( 'sha256', $bytes ) !== 'f97165fc884770319f4a53d5ae3377adc94821b7bd5ed439a4023c426d92ba23' ) {
			throw new \RuntimeException( 'ZIP mismatch' );
		}
		// Unpublished candidate only: simulated numeric transport facts, never production discovery.
		$i      = array(
			'repository_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'repository_id'      => '1322743261',
			'release_id'         => 41,
			'release_tag'        => 'v0.2.1',
			'release_commit'     => $e['producer_sha'],
			'release_target'     => $e['producer_sha'],
			'tag_target'         => $e['producer_sha'],
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => 1,
			'asset_id'           => 73,
			'asset_name'         => $e['filename'],
			'asset_state'        => 'uploaded',
			'asset_content_type' => 'application/zip',
			'asset_size'         => strlen( $bytes ),
			'asset_digest'       => 'sha256:' . hash( 'sha256', $bytes ),
			'asset_sha256'       => hash( 'sha256', $bytes ),
		);
		$result = TemplatePack::fromArchive( $bytes, $i );
		if ( $result['code'] !== 'ok' ) {
			self::fail( $result['code'] );
		}
		$p = $result['pack'];
		if ( $p->manifestHash() !== $e['manifest_sha256'] ) {
			throw new \RuntimeException( 'Manifest mismatch' );
		}

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$profile = 'source-ready-wordpress-' . $type . '/3';
			$header  = $type === 'plugin' ? 'example-package.php' : 'style.css';
			$values  = array(
				'quality-workflow'      => array(
					'PACKAGE_SLUG' => 'example-package',
					'PHP_VERSION'  => '8.0',
				),
				'release-workflow'      => array( 'PACKAGE_SLUG' => 'example-package' ),
				'release-please-config' => array(
					'BASE_SHA'         => '0123456789abcdef0123456789abcdef01234567',
					'EXTRA_FILES_JSON' => json_encode(
						array(
							array(
								'type' => 'generic',
								'path' => $header,
							),
						),
						JSON_UNESCAPED_SLASHES
					),
					'PACKAGE_SLUG'     => 'example-package',
				),
				'build-release-script'  => array(
					'HEADER_PATH'  => $header,
					'PACKAGE_SLUG' => 'example-package',
					'PACKAGE_TYPE' => $type,
				),
				'verify-release-script' => array(
					'HEADER_PATH'  => $header,
					'PACKAGE_SLUG' => 'example-package',
					'PACKAGE_TYPE' => $type,
					'UPDATE_URI'   => 'https://github.com/example/example-package',
				),
			);
			foreach ( $values as $logical => $v ) {
				$rendered = $p->render( $profile, $logical, $v );
				if ( $rendered['code'] !== 'ok' || hash( 'sha256', $rendered['content'] ) !== $e['profiles'][ $profile ][ $logical ]['rendered_sha256'] ) {
					self::fail( 'Render mismatch: ' . $profile . '/' . $logical );
				}self::assertSame( $e['profiles'][ $profile ][ $logical ]['rendered_sha256'], hash( 'sha256', $rendered['content'] ) );}
		}
	}
}
