<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

final class TemplatePackArchiveContractTest extends TestCase {

	private const LIVE_SHA = '7518b7c30b23fe95fb6c3c5211607657394ffcf440d258323d55c20b15bb5b14';
	private const API1_SHA = '2c223e14287a1fab28aa91e92d6a454b27e647cfb33f1bb3df965ed995cd89db';

	public function test_closed_api3_contract_renders_deterministic_plugin_and_theme_bundles(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$result  = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );

		self::assertSame( 'ok', $result['code'] );
		$pack = $result['pack'];
		self::assertSame( '1.2.3', $pack->pack_version() );
		self::assertSame(
			array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ),
			$pack->profiles()
		);
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $pack->manifest_hash() );

		$plugin_first  = self::render_fixture_profile( $pack, 'plugin' );
		$plugin_second = self::render_fixture_profile( $pack, 'plugin' );
		$theme_first   = self::render_fixture_profile( $pack, 'theme' );
		$theme_second  = self::render_fixture_profile( $pack, 'theme' );
		self::assertSame( $plugin_first, $plugin_second );
		self::assertSame( $theme_first, $theme_second );
		self::assertNotSame( $plugin_first['bundle_sha256'], $theme_first['bundle_sha256'] );
		self::assertSame( self::target_paths(), array_keys( $plugin_first['managed_files'] ) );
		self::assertSame( self::target_paths(), array_keys( $theme_first['managed_files'] ) );
	}

	public function test_historical_api1_is_refused_without_an_adapter(): void {
		$archive = TemplatePackApi3Fixture::archive( TemplatePackApi3Fixture::manifest( 1 ) );

		self::assertSame(
			'template_pack_incompatible',
			TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code']
		);

		$drifted_manifest                  = TemplatePackApi3Fixture::manifest( 1 );
		$drifted_manifest['release']['id'] = 999;
		$drifted_archive                   = TemplatePackApi3Fixture::archive( $drifted_manifest );
		self::assertSame(
			'template_pack_incompatible',
			TemplatePack::from_archive( $drifted_archive, TemplatePackApi3Fixture::identity( $drifted_archive ) )['code']
		);
	}

	public function test_rejects_remote_identity_manifest_authority_digest_and_member_drift(): void {
		$archive                  = TemplatePackApi3Fixture::archive();
		$identity                 = TemplatePackApi3Fixture::identity( $archive );
		$identity['asset_sha256'] = str_repeat( '0', 64 );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, $identity )['code'] );

		$identity                   = TemplatePackApi3Fixture::identity( $archive );
		$identity['release_target'] = str_repeat( '1', 40 );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, $identity )['code'] );

		$identity                  = TemplatePackApi3Fixture::identity( $archive );
		$identity['repository_id'] = '987654321';
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, $identity )['code'] );

		$manifest = TemplatePackApi3Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-plugin/3']['entries']['release-workflow']['target_path'] = '.github/workflows/release.yml';
		$archive = TemplatePackApi3Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$manifest = TemplatePackApi3Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-theme/3']['entries']['release-workflow']['sha256'] = str_repeat( '0', 64 );
		$archive = TemplatePackApi3Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi3Fixture::archive( null, array(), array( 'templates/unlisted.txt' => 'not declared' ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi3Fixture::archive( null, array(), array( '../escape.txt' => 'unsafe' ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );
	}

	public function test_rejects_executable_unknown_tokens_and_high_compression_ratio(): void {
		$workflow_path = 'templates/shared/release-please.yml.tmpl';
		$archive       = TemplatePackApi3Fixture::archive( null, array(), array(), $workflow_path, 0100755 );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$content  = TemplatePackApi3Fixture::templates()[ $workflow_path ] . '{{RAN_REMOTE_COMMAND}}';
		$manifest = self::manifest_with_workflow_content( $content );
		$archive  = TemplatePackApi3Fixture::archive( $manifest, array( $workflow_path => $content ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$content  = str_repeat( 'A', 200000 );
		$manifest = self::manifest_with_workflow_content( $content );
		$archive  = TemplatePackApi3Fixture::archive( $manifest, array( $workflow_path => $content ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );
	}

	public function test_rejects_nul_and_invalid_utf8_members_with_exact_matching_size_and_digest(): void {
		$workflow_path = 'templates/shared/release-please.yml.tmpl';
		$workflow      = TemplatePackApi3Fixture::templates()[ $workflow_path ];
		foreach ( array(
			'NUL byte'      => $workflow . "\0",
			'invalid UTF-8' => $workflow . "\xC3\x28",
		) as $case => $content ) {
			$manifest = self::manifest_with_workflow_content( $content );
			foreach ( array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ) as $profile ) {
				$entry = $manifest['profiles'][ $profile ]['entries']['release-workflow'];
				self::assertSame( strlen( $content ), $entry['size'], $case );
				self::assertSame( hash( 'sha256', $content ), $entry['sha256'], $case );
			}
			$archive = TemplatePackApi3Fixture::archive( $manifest, array( $workflow_path => $content ) );
			$result  = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );

			self::assertSame( 'template_pack_invalid', $result['code'], $case );
			self::assertArrayNotHasKey( 'pack', $result, $case );
		}
	}

	public function test_valid_unicode_member_passes_without_transcoding_or_sanitisation(): void {
		$workflow_path = 'templates/shared/release-please.yml.tmpl';
		$content       = TemplatePackApi3Fixture::templates()[ $workflow_path ] . "label=Déploiement sûr 🚀\n";
		$manifest      = self::manifest_with_workflow_content( $content );
		$archive       = TemplatePackApi3Fixture::archive( $manifest, array( $workflow_path => $content ) );
		$result        = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );

		self::assertSame( 'ok', $result['code'] );
		$rendered = $result['pack']->render(
			'source-ready-wordpress-plugin/3',
			'release-workflow',
			array(
				'PACKAGE_SLUG' => 'unicode-plugin',
			)
		);
		self::assertSame( 'ok', $rendered['code'] );
		self::assertSame(
			str_replace( array( '{{RAN_DEFAULT_BRANCH}}', '{{RAN_PACKAGE_SLUG}}' ), array( 'main', 'unicode-plugin' ), $content ),
			$rendered['content']
		);
	}

	public function test_rejects_duplicate_missing_and_extra_declarations_and_every_resource_cap(): void {
		$manifest = TemplatePackApi3Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-extra/2'] = $manifest['profiles']['source-ready-wordpress-plugin/3'];
		$archive = TemplatePackApi3Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$manifest = TemplatePackApi3Fixture::manifest();
		$workflow = $manifest['profiles']['source-ready-wordpress-plugin/3']['entries']['release-workflow'];
		$manifest['profiles']['source-ready-wordpress-plugin/3']['entries']['release-please-config']['path']   = $workflow['path'];
		$manifest['profiles']['source-ready-wordpress-plugin/3']['entries']['release-please-config']['size']   = $workflow['size'];
		$manifest['profiles']['source-ready-wordpress-plugin/3']['entries']['release-please-config']['sha256'] = $workflow['sha256'];
		$archive = TemplatePackApi3Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi3Fixture::archive(
			null,
			array(),
			array(),
			'',
			0100644,
			array( 'templates/shared/quality.yml.tmpl' )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact deterministic oversized-manifest fixture.
		$manifest_bytes = (string) json_encode( TemplatePackApi3Fixture::manifest(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		$archive        = TemplatePackApi3Fixture::archive(
			null,
			array( 'template-pack.json' => $manifest_bytes . str_repeat( ' ', 65537 - strlen( $manifest_bytes ) ) )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$content  = self::pseudo_random_ascii( 262145 );
		$manifest = self::manifest_with_workflow_content( $content );
		$archive  = TemplatePackApi3Fixture::archive(
			$manifest,
			array( 'templates/shared/release-please.yml.tmpl' => $content )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$extra = array();
		for ( $index = 0; $index < 26; ++$index ) {
			$extra[ 'templates/count-' . $index . '.txt' ] = 'bounded';
		}
		$archive = TemplatePackApi3Fixture::archive( null, array(), $extra );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$extra = array();
		for ( $index = 0; $index < 5; ++$index ) {
			$extra[ 'templates/total-' . $index . '.txt' ] = self::pseudo_random_ascii( 220000 );
		}
		$archive = TemplatePackApi3Fixture::archive( null, array(), $extra );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi3Fixture::archive(
			null,
			array(),
			array( 'templates/archive-cap.txt' => self::pseudo_random_ascii( 4000000 ) )
		);
		self::assertGreaterThan( 2097152, strlen( $archive ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['code'] );
	}

	public function test_renderer_rejects_unsafe_or_incomplete_consumer_inputs(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$pack    = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['pack'];

		self::assertSame( 'invalid_render', $pack->render( 'unknown-profile', 'release-workflow', array() )['code'] );
		self::assertSame(
			'invalid_render',
			$pack->render( 'source-ready-wordpress-plugin/3', 'release-workflow', array() )['code']
		);
		self::assertSame(
			'invalid_render',
			$pack->render(
				'source-ready-wordpress-plugin/3',
				'release-workflow',
				array(
					'PACKAGE_SLUG' => '{{RAN_REMOTE_COMMAND}}',
				)
			)['code']
		);
		self::assertSame(
			'invalid_render',
			$pack->render(
				'source-ready-wordpress-plugin/3',
				'release-please-config',
				array(
					'BASE_SHA'         => TemplatePackApi3Fixture::COMMIT,
					'EXTRA_FILES_JSON' => '[{"type":"command","path":"build.sh"}]',
					'PACKAGE_SLUG'     => 'example-plugin',
				)
			)['code']
		);
	}

	#[Group( 'published-template-pack' )]
	public function test_exact_published_pack_when_explicitly_supplied(): void {
		$path = getenv( 'RAN_TEMPLATE_PACK_ZIP' );
		if ( ! is_string( $path ) || '' === $path ) {
			self::markTestSkipped( 'Set RAN_TEMPLATE_PACK_ZIP for the separately downloaded immutable API 2 asset.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Optional explicit local immutable fixture.
		$archive = file_get_contents( $path );
		self::assertIsString( $archive );
		$identity = self::live_identity();
		$result   = TemplatePack::from_archive( $archive, $identity );

		self::assertContains( $result['code'], array( 'template_pack_incompatible', 'template_pack_invalid' ) );
		self::assertArrayNotHasKey( 'pack', $result );
	}

	#[Group( 'published-template-pack' )]
	public function test_exact_historical_published_api1_pack_is_refused_when_explicitly_supplied(): void {
		$path = getenv( 'RAN_TEMPLATE_PACK_API1_ZIP' );
		if ( ! is_string( $path ) || '' === $path ) {
			self::markTestSkipped( 'Set RAN_TEMPLATE_PACK_API1_ZIP for the immutable historical API 1 asset.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Optional explicit local immutable fixture.
		$archive = file_get_contents( $path );
		self::assertIsString( $archive );
		$commit   = 'd1375ee8e38180b6483605779eb29b545f9cc061';
		$identity = self::published_identity(
			$archive,
			367444791,
			'v0.2.0',
			$commit,
			507386990,
			self::API1_SHA
		);

		self::assertSame( 'template_pack_incompatible', TemplatePack::from_archive( $archive, $identity )['code'] );
	}

	/** @return array{managed_files:array<string, string>,bundle_sha256:string} */
	public static function render_fixture_profile( TemplatePack $pack, string $type ): array {
		$profile      = 'source-ready-wordpress-' . $type . '/3';
		$slug         = 'fixture-' . $type;
		$header       = 'plugin' === $type ? 'fixture-plugin.php' : 'style.css';
		$values       = array(
			'release-workflow'      => array(
				'PACKAGE_SLUG' => $slug,
			),
			'release-please-config' => array(
				'BASE_SHA'         => TemplatePackApi3Fixture::COMMIT,
				'EXTRA_FILES_JSON' => '[{"type":"generic","path":"' . $header . '"}]',
				'PACKAGE_SLUG'     => $slug,
			),
			'build-release-script'  => array(
				'HEADER_PATH'  => $header,
				'PACKAGE_SLUG' => $slug,
				'PACKAGE_TYPE' => $type,
			),
			'verify-release-script' => array(
				'HEADER_PATH'  => $header,
				'PACKAGE_SLUG' => $slug,
				'PACKAGE_TYPE' => $type,
				'UPDATE_URI'   => 'https://github.com/example/' . $slug,
			),
			'quality-workflow'      => array(
				'PACKAGE_SLUG' => $slug,
				'PHP_VERSION'  => '8.2',
			),
		);
		$targets      = array(
			'release-workflow'      => '.github/workflows/release-please.yml',
			'release-please-config' => 'release-please-config.json',
			'build-release-script'  => 'scripts/build-release.sh',
			'verify-release-script' => 'scripts/verify-release.sh',
			'quality-workflow'      => '.github/workflows/quality.yml',
		);
		$mapped_paths = array_values( array_unique( array_values( $targets ) ) );
		sort( $mapped_paths, SORT_STRING );
		if ( array_keys( $targets ) !== array_keys( $values ) || self::target_paths() !== $mapped_paths ) {
			throw new \RuntimeException( 'Fixture logical-ID target map is invalid.' );
		}
		$managed = array();
		foreach ( $values as $logical_id => $placeholders ) {
			$rendered = $pack->render( $profile, $logical_id, $placeholders );
			if ( 'ok' !== $rendered['code'] ) {
				throw new \RuntimeException( 'Fixture render was refused.' );
			}
			$managed[ $targets[ $logical_id ] ] = $rendered['sha256'];
		}
		ksort( $managed, SORT_STRING );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic test-only digest input.
		$ledger = (string) json_encode( $managed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		return array(
			'managed_files' => $managed,
			'bundle_sha256' => hash( 'sha256', $ledger ),
		);
	}

	/** @return list<string> */
	private static function target_paths(): array {
		$paths = array(
			'.github/workflows/release-please.yml',
			'release-please-config.json',
			'scripts/build-release.sh',
			'scripts/verify-release.sh',
			'.github/workflows/quality.yml',
		);
		sort( $paths, SORT_STRING );

		return $paths;
	}

	/** @return array<string, mixed> */
	private static function manifest_with_workflow_content( string $content ): array {
		$manifest = TemplatePackApi3Fixture::manifest();
		foreach ( array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ) as $profile ) {
			$manifest['profiles'][ $profile ]['entries']['release-workflow']['size']   = strlen( $content );
			$manifest['profiles'][ $profile ]['entries']['release-workflow']['sha256'] = hash( 'sha256', $content );
		}

		return $manifest;
	}

	private static function pseudo_random_ascii( int $length ): string {
		$content = '';
		$chunks  = intdiv( $length + 63, 64 );
		for ( $index = 0; $index < $chunks; ++$index ) {
			$content .= hash( 'sha256', 'ran-template-cap-' . $index );
		}

		return substr( $content, 0, $length );
	}

	/** @return array<string, mixed> */
	private static function live_identity(): array {
		$commit  = 'df5508fffe1f3e6770b1e7d5d4af5bf195e676c9';
		$archive = str_repeat( 'x', 13879 );

		return self::published_identity( $archive, 368127015, 'v0.2.1', $commit, 509118185, self::LIVE_SHA, 13879 );
	}

	/** @return array<string, mixed> */
	private static function published_identity(
		string $archive,
		int $release_id,
		string $tag,
		string $commit,
		int $asset_id,
		string $sha256,
		?int $known_size = null
	): array {
		$size = $known_size ?? strlen( $archive );

		return array(
			'repository_name'    => TemplatePackApi3Fixture::REPOSITORY,
			'repository_id'      => '1322743261',
			'release_id'         => $release_id,
			'release_tag'        => $tag,
			'release_commit'     => $commit,
			'release_target'     => $commit,
			'tag_target'         => $commit,
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => 1,
			'asset_id'           => $asset_id,
			'asset_name'         => TemplatePackApi3Fixture::ASSET_NAME,
			'asset_state'        => 'uploaded',
			'asset_content_type' => 'application/zip',
			'asset_size'         => $size,
			'asset_digest'       => 'sha256:' . $sha256,
			'asset_sha256'       => $sha256,
		);
	}
}
