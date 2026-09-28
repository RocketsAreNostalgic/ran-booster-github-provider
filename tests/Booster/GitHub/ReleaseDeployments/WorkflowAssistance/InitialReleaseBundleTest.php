<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\InitialReleaseBundle;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

final class InitialReleaseBundleTest extends TestCase {
	public function testBuildsOneRepeatableCompleteApi3BootstrapTree(): void {
		$archive    = TemplatePackApi3Fixture::archive();
		$packResult = TemplatePack::fromArchive( $archive, TemplatePackApi3Fixture::identity( $archive ) );
		self::assertSame( 'ok', $packResult['code'] );
		$snapshot   = $this->snapshot();
		$assessment = $this->assessment( $snapshot );
		$documents  = array();
		foreach ( array_reverse( $snapshot->documentPaths() ) as $path ) {
			$documents[ $path ] = $snapshot->document( $path );
		}
		$reordered = new RepositorySnapshot(
			$snapshot->repositoryId(),
			$snapshot->repository(),
			$snapshot->defaultBranch(),
			$snapshot->sha(),
			array_reverse( $snapshot->entries(), true ),
			$documents
		);
		$first     = InitialReleaseBundle::bootstrap( $packResult['pack'], $assessment, $snapshot, 'https://github.com/owner/example-plugin/' );
		$second    = InitialReleaseBundle::bootstrap( $packResult['pack'], $this->assessment( $reordered ), $reordered, 'https://github.com/owner/example-plugin' );

		self::assertSame( 'ok', $first['code'] );
		self::assertSame( $first['bundle']->hash(), $second['bundle']->hash() );
		self::assertSame( $first['bundle']->changedPathHash(), $second['bundle']->changedPathHash() );
		self::assertSame( $first['bundle']->allowlistHash(), $second['bundle']->allowlistHash() );
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
		self::assertSame( hash( 'sha256', implode( "\n", array_keys( $files ) ) . "\n" ), $first['bundle']->changedPathHash() );
		self::assertSame( hash( 'sha256', $files['release-contents.txt']['content'] ), $first['bundle']->allowlistHash() );
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
		self::assertSame( $files['.github/workflows/quality.yml']['git_sha'], $first['bundle']->expectedPullFiles()[0]['sha'] );
	}


	public function testRefusesNonReadyAssessmentAndOccupiedGeneratedPath(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, TemplatePackApi3Fixture::identity( $archive ) )['pack'];
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
