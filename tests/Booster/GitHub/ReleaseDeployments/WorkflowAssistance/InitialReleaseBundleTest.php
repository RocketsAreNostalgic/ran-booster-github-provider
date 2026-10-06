<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\InitialReleaseBundle;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

final class InitialReleaseBundleTest extends TestCase {
	public function test_builds_one_repeatable_complete_api3_bootstrap_tree(): void {
		$archive     = TemplatePackApi3Fixture::archive();
		$pack_result = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );
		self::assertSame( 'ok', $pack_result['code'] );
		$snapshot   = $this->snapshot();
		$assessment = $this->assessment( $snapshot );
		$documents  = array();
		foreach ( array_reverse( $snapshot->document_paths() ) as $path ) {
			$documents[ $path ] = $snapshot->document( $path );
		}
		$reordered = new RepositorySnapshot(
			$snapshot->repository_id(),
			$snapshot->repository(),
			$snapshot->default_branch(),
			$snapshot->sha(),
			array_reverse( $snapshot->entries(), true ),
			$documents
		);
		$first     = InitialReleaseBundle::bootstrap( $pack_result['pack'], $assessment, $snapshot, 'https://github.com/owner/example-plugin/' );
		$second    = InitialReleaseBundle::bootstrap( $pack_result['pack'], $this->assessment( $reordered ), $reordered, 'https://github.com/owner/example-plugin' );

		self::assertSame( 'ok', $first['code'] );
		self::assertSame( $first['bundle']->hash(), $second['bundle']->hash() );
		self::assertSame( $first['bundle']->changed_path_hash(), $second['bundle']->changed_path_hash() );
		self::assertSame( $first['bundle']->allowlist_hash(), $second['bundle']->allowlist_hash() );
		self::assertSame( $first['bundle']->files(), $second['bundle']->files() );
		self::assertSame( 'source-ready-wordpress-plugin/3', $first['bundle']->profile() );
		$files = $first['bundle']->files();
		self::assertSame(
			array(
				'.github/workflows/quality.yml',
				'.github/workflows/release-please.yml',
				'.ran-booster-release-starter.json',
				'.release-please-manifest.json',
				'RELEASE-STARTER.md',
				'example-plugin.php',
				'release-contents.txt',
				'release-please-config.json',
				'scripts/build-release.sh',
				'scripts/verify-release.sh',
				'version.txt',
			),
			array_keys( $files )
		);
		self::assertSame( hash( 'sha256', implode( "\n", array_keys( $files ) ) . "\n" ), $first['bundle']->changed_path_hash() );
		self::assertSame( hash( 'sha256', $files['release-contents.txt']['content'] ), $first['bundle']->allowlist_hash() );
		self::assertSame( '100644', $files['scripts/build-release.sh']['mode'] );
		self::assertSame( 'modified', $files['example-plugin.php']['operation'] );
		$origin = \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\StarterOrigin::decode( $files[ InitialReleaseBundle::ORIGIN_PATH ]['content'] );
		self::assertNotNull( $origin );
		self::assertSame( 'source-ready-wordpress-plugin/3', $origin['pack']['profile'] );
		self::assertArrayNotHasKey( 'managed_files', $origin );
		self::assertArrayNotHasKey( 'release_id', $origin['pack'] );
		foreach ( $files as $file ) {
			self::assertSame( '100644', $file['mode'] );
		}
		self::assertSame( $files['.github/workflows/quality.yml']['git_sha'], $first['bundle']->expected_pull_files()[0]['sha'] );
	}


	public function test_guidance_tracks_the_workflow_pin_in_a_compatible_pack(): void {
		$path     = 'templates/shared/release-please.yml.tmpl';
		$pin      = str_repeat( 'b', 40 );
		$template = str_replace( '63c4a4b192bbb4cf203dab281b75a0907e85c3a9', $pin, TemplatePackApi3Fixture::templates()[ $path ] );
		foreach ( array( 'uses: RocketsAreNostalgic/', 'uses   :   "RocketsAreNostalgic/', "uses : 'RocketsAreNostalgic/" ) as $prefix ) {
			$rendered = str_replace( 'uses: RocketsAreNostalgic/', $prefix, $template );
			if ( str_contains( $prefix, '"' ) ) {
				$rendered = str_replace( '@' . $pin, '@' . $pin . '" # pinned', $rendered );
			} elseif ( str_contains( $prefix, "'" ) ) {
				$rendered = str_replace( '@' . $pin, '@' . $pin . "'", $rendered );
			}
			$manifest = TemplatePackApi3Fixture::manifest();
			foreach ( $manifest['profiles'] as &$profile ) {
				$profile['entries']['release-workflow']['sha256'] = hash( 'sha256', $rendered );
				$profile['entries']['release-workflow']['size']   = strlen( $rendered );
			}
			unset( $profile );
			$archive = TemplatePackApi3Fixture::archive( $manifest, array( $path => $rendered ) );
			$result  = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );
			self::assertSame( 'ok', $result['code'] );
			$snapshot = $this->snapshot();
			$bundle   = InitialReleaseBundle::bootstrap( $result['pack'], $this->assessment( $snapshot ), $snapshot, 'https://github.com/owner/example-plugin' );
			self::assertSame( 'ok', $bundle['code'] );
			$files = $bundle['bundle']->files();
			self::assertStringContainsString( 'blob/' . $pin . '/RELEASE_PROFILE_B.md', $files['RELEASE-STARTER.md']['content'] );
			self::assertStringContainsString( '@' . $pin, $files['.github/workflows/release-please.yml']['content'] );
			self::assertStringContainsString( $pin, $files[ InitialReleaseBundle::ORIGIN_PATH ]['content'] );
		}
	}

	public function test_block_scalar_pin_decoy_cannot_become_origin_provenance(): void {
		$path     = 'templates/shared/release-please.yml.tmpl';
		$original = TemplatePackApi3Fixture::templates()[ $path ];
		foreach ( array(
			str_replace( "  release:\n", "  release: |\n", $original ),
			str_replace( "jobs:\n  release:\n", "note: |\n    uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@" . str_repeat( 'a', 40 ) . "\njobs:\n  release:\n", str_replace( 'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@', 'uses: attacker/fork/.github/workflows/release.yml@', $original ) ),
		) as $template ) {
			$manifest = TemplatePackApi3Fixture::manifest();
			foreach ( $manifest['profiles'] as &$profile ) {
				$profile['entries']['release-workflow']['sha256'] = hash( 'sha256', $template );
				$profile['entries']['release-workflow']['size']   = strlen( $template );
			}
			unset( $profile );
			$archive = TemplatePackApi3Fixture::archive( $manifest, array( $path => $template ) );
			$pack    = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) );
			self::assertSame( 'ok', $pack['code'] );
			$snapshot = $this->snapshot();
			self::assertSame( 'invalid_bundle', InitialReleaseBundle::bootstrap( $pack['pack'], $this->assessment( $snapshot ), $snapshot, 'https://github.com/owner/example-plugin' )['code'] );
		}
	}

	public function test_refuses_non_ready_assessment_and_occupied_generated_path(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$pack    = TemplatePack::from_archive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['pack'];
		$base    = $this->snapshot();
		self::assertSame( 'invalid_bundle', InitialReleaseBundle::bootstrap( $pack, $this->assessment( $base ), $base, 'https://github.com/owner/other' )['code'] );
		$entries                = $base->entries();
		$docs                   = array(
			'example-plugin.php' => $base->document( 'example-plugin.php' ),
			'version.txt'        => '1.2.3',
		);
		$entries['version.txt'] = array(
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => sha1( 'version.txt' ),
			'size' => 5,
		);
		$occupied               = new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'a', 40 ), $entries, $docs );
		$assessment             = $this->assessment( $occupied );

		self::assertSame( 'release_path_conflict', $assessment->code() );
		self::assertSame( 'invalid_bundle', InitialReleaseBundle::bootstrap( $pack, $assessment, $occupied, 'https://github.com/owner/example-plugin' )['code'] );
	}


	private function assessment( RepositorySnapshot $snapshot ): object {
		return ( new SourceReadyAssessor() )->assess( $snapshot, 'plugin', 'example-plugin', '1.2.3', 'https://github.com/owner/example-plugin' );
	}

	private function snapshot(): RepositorySnapshot {
		$documents = array(
			'example-plugin.php' => "<?php\n/**\n * Plugin Name: Example\n * Requires PHP: 8.2\n * Requires at least: 7.0\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n",
			'assets/app.css'     => 'body{}',
		);
		$entries   = array();
		foreach ( $documents as $path => $document ) {
			$entries[ $path ] = array(
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $document ),
			);
		}
		return new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'a', 40 ), $entries, $documents );
	}
}
