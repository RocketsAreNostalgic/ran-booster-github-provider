<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessment;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;

final class SourceReadyAssessorTest extends TestCase {
	private const REPOSITORY = 'owner/example-plugin';
	private const VERSION    = '1.2.3';

	public function testPluginProfileProducesDeterministicBoundedEdits(): void {
		$documents = array(
			'example-plugin.php' => $this->pluginHeader(),
			'assets/app.css'     => 'body{}',
			'build/block.json'   => '{"name":"ran/example"}',
			'package.json'       => '{"name":"example-plugin"}',
			'readme.txt'         => "=== Example ===\nStable tag: 1.2.3\n",
			'.prettierignore'    => "vendor/\n",
			'tests/Test.php'     => '<?php',
		);
		$first     = ( new SourceReadyAssessor() )->assess( $this->snapshot( $documents ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY . '/' );
		$second    = ( new SourceReadyAssessor() )->assess( $this->snapshot( array_reverse( $documents, true ) ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY );

		self::assertTrue( $first->readyForBootstrap() );
		self::assertSame( 'source-ready-wordpress-plugin/3', $first->profile() );
		self::assertSame( $first->releaseFiles(), $second->releaseFiles() );
		self::assertSame( $first->extraFiles(), $second->extraFiles() );
		self::assertSame( array( 'assets/app.css', 'build/block.json', 'example-plugin.php', 'readme.txt' ), $first->releaseFiles() );
		self::assertStringContainsString( " * x-release-please-start-version\n * Version: 1.2.3\n * x-release-please-end", $first->modifiedFiles()['example-plugin.php'] );
		self::assertStringContainsString( "x-release-please-start-version\nStable tag: 1.2.3\nx-release-please-end", $first->modifiedFiles()['readme.txt'] );
		self::assertArrayNotHasKey( '.prettierignore', $first->modifiedFiles() );
		self::assertSame( array( 'example-plugin.php', 'readme.txt' ), array_column( $first->extraFiles(), 'path' ) );
	}

	public function testSourceEligibilityMatchesTheFixedBuildAndVerifySyntax(): void {
		foreach ( array( 'con', 'prn', 'aux', 'nul', 'com1', 'lpt9' ) as $slug ) {
			self::assertSame( 'repository_unsupported', ( new SourceReadyAssessor() )->assess( $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ) ), 'plugin', $slug, self::VERSION, 'https://github.com/' . self::REPOSITORY )->code(), $slug );
		}
		foreach ( array( 'assets/with space.css', 'assets/é.css', 'assets/two..dots.css', 'assets/name.', 'assets/CON.css' ) as $path ) {
			$assessment = ( new SourceReadyAssessor() )->assess(
				$this->snapshot(
					array(
						'example-plugin.php' => $this->pluginHeader(),
						$path                => 'body{}',
					)
				),
				'plugin',
				'example-plugin',
				self::VERSION,
				'https://github.com/' . self::REPOSITORY
			);
			self::assertFalse( $assessment->readyForBootstrap(), $path );
		}
		$header = str_replace( 'https://github.com/owner/example-plugin', 'https://github.com/owner/example-plugin/', $this->pluginHeader() );
		self::assertSame( 'repository_unsupported', ( new SourceReadyAssessor() )->assess( $this->snapshot( array( 'example-plugin.php' => $header ) ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY )->code() );
		$theme = "/*\n * Theme Name: Example\n * Requires PHP: 8.2\n * Requires at least: 7.0\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n";
		self::assertSame(
			'package_ambiguous',
			( new SourceReadyAssessor() )->assess(
				$this->snapshot(
					array(
						'style.css'            => $theme,
						'templates/index.html' => '',
					)
				),
				'theme',
				'example-theme',
				self::VERSION,
				'https://github.com/' . self::REPOSITORY
			)->code()
		);
	}

	public function testAlreadyAnnotatedVersionSourcesDoNotAppearAsChanges(): void {
		$header     = str_replace( ' * Version: 1.2.3', " * x-release-please-start-version\n * Version: 1.2.3\n * x-release-please-end", $this->pluginHeader() );
		$readme     = "=== Example ===\nx-release-please-start-version\nStable tag: 1.2.3\nx-release-please-end\n";
		$assessment = ( new SourceReadyAssessor() )->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $header,
					'readme.txt'         => $readme,
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);
		self::assertTrue( $assessment->readyForBootstrap() );
		self::assertSame( array(), $assessment->modifiedFiles() );
		self::assertContains( 'readme.txt', $assessment->releaseFiles() );
	}

	public function testRuntimeCaseCollisionsAreRefusedIncludingFileDirectoryCollisions(): void {
		foreach ( array( array( 'assets/Icon.svg', 'assets/icon.svg' ), array( 'assets/Icons', 'assets/icons/logo.svg' ), array( 'assets/Icons/a.svg', 'assets/icons/b.svg' ) ) as $paths ) {
			$documents = array( 'example-plugin.php' => $this->pluginHeader() );
			foreach ( $paths as $path ) {
				$documents[ $path ] = 'data';
			}
			self::assertSame( 'runtime_paths_unknown', ( new SourceReadyAssessor() )->assess( $this->snapshot( $documents ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY )->code() );
		}
	}

	public function testRuntimeSizeBudgetUsesMetadataAndReservesZipOverhead(): void {
		foreach ( array( array( 127826408 ), array( 52428800 ), array( 26000000, 26000000 ), array( 1000000 ) ) as $sizes ) {
			$base     = $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ) );
			$entries  = $base->entries();
			$prefixes = array();
			foreach ( $sizes as $index => $size ) {
				$path              = 'assets/file-' . $index . '.bin';
				$entries[ $path ]  = array(
					'type' => 'blob',
					'mode' => '100644',
					'sha'  => sha1( $path ),
					'size' => $size,
				);
				$prefixes[ $path ] = str_repeat( "\0", 43 );
			}
			$snapshot = new RepositorySnapshot( $base->repositoryId(), $base->repository(), 'main', $base->sha(), $entries, array( 'example-plugin.php' => $this->pluginHeader() ), $prefixes );
			$result   = ( new SourceReadyAssessor() )->assess( $snapshot, 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY );
			self::assertSame( array( 1000000 ) === $sizes, $result->readyForBootstrap() );
		}
	}

	public function testSourceAdmissionReservesSixGeneratedAssessmentDocumentsBeforeWrites(): void {
		$base = $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ) );
		foreach ( array(
			249 => true,
			250 => false,
		) as $assetCount => $ready ) {
			$entries  = $base->entries();
			$prefixes = array();
			for ( $index = 0; $index < $assetCount; ++$index ) {
				$path              = sprintf( 'assets/file-%03d.bin', $index );
				$entries[ $path ]  = array(
					'type' => 'blob',
					'mode' => '100644',
					'sha'  => sha1( $path ),
					'size' => 43,
				);
				$prefixes[ $path ] = str_repeat( 'a', 43 );
			}
			$snapshot   = new RepositorySnapshot( $base->repositoryId(), $base->repository(), 'main', $base->sha(), $entries, array( 'example-plugin.php' => $this->pluginHeader() ), $prefixes );
			$assessment = ( new SourceReadyAssessor() )->assess( $snapshot, 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY );
			self::assertSame( $ready, $assessment->readyForBootstrap(), (string) $assetCount );
			if ( ! $ready ) {
				self::assertSame( 'runtime_paths_unknown', $assessment->code() );
			}
		}
	}

	public function testUninspectedRuntimeBlobAndLfsPrefixRefuseBeforeSetup(): void {
		$base                       = $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ) );
		$entries                    = $base->entries();
		$entries['assets/logo.png'] = array(
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => str_repeat( 'b', 40 ),
			'size' => 123,
		);
		foreach ( array( array(), array( 'assets/logo.png' => "version https://git-lfs.github.com/spec/v1\n" ) ) as $prefixes ) {
			$snapshot = new RepositorySnapshot( $base->repositoryId(), $base->repository(), 'main', $base->sha(), $entries, array( 'example-plugin.php' => $this->pluginHeader() ), $prefixes );
			self::assertSame( 'runtime_paths_unknown', ( new SourceReadyAssessor() )->assess( $snapshot, 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY )->code() );
		}
	}

	public function testHeaderLabelsMustMatchTheGeneratedVerifierCasing(): void {
		$header = $this->pluginHeader();
		foreach ( array(
			'Plugin Name'       => 'package_ambiguous',
			'Requires PHP'      => 'repository_unsupported',
			'Requires at least' => 'repository_unsupported',
			'Update URI'        => 'repository_unsupported',
			'Version'           => 'version_mismatch',
		) as $label => $code ) {
			$changed = str_replace( $label . ':', strtolower( $label ) . ':', $header );
			self::assertSame( $code, ( new SourceReadyAssessor() )->assess( $this->snapshot( array( 'example-plugin.php' => $changed ) ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY )->code(), $label );
		}
		$theme = "/*\nTheme Name: Example\nRequires PHP: 8.2\nRequires at least: 7.0\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/example-plugin\n*/\n";
		self::assertSame(
			'package_ambiguous',
			( new SourceReadyAssessor() )->assess(
				$this->snapshot(
					array(
						'style.css'            => str_replace( 'Theme Name:', 'theme name:', $theme ),
						'templates/index.html' => '',
					)
				),
				'theme',
				'example-plugin',
				self::VERSION,
				'https://github.com/' . self::REPOSITORY
			)->code()
		);
	}

	public function testThemeProfileUsesStyleHeaderAndThemeRuntimePaths(): void {
		$assessment = ( new SourceReadyAssessor() )->assess(
			$this->snapshot(
				array(
					'style.css'            => "/*\nTheme Name: Example\nRequires PHP: 8.2\nRequires at least: 7.0\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/example-plugin\n*/\n",
					'functions.php'        => '<?php',
					'theme.json'           => '{}',
					'templates/index.html' => '<!-- wp:post-content /-->',
					'package.json'         => '{}',
				)
			),
			'theme',
			'example-theme',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertTrue( $assessment->readyForBootstrap() );
		self::assertSame( 'source-ready-wordpress-theme/3', $assessment->profile() );
		self::assertContains( 'templates/index.html', $assessment->releaseFiles() );
	}

	public function testVersionAndRepositoryContractsFailClosed(): void {
		$assessor  = new SourceReadyAssessor();
		$constant  = $assessor->assess( $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() . "define( 'EXAMPLE_VERSION', '1.2.3' );\n" ) ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY );
		$wrongUri  = $assessor->assess( $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ) ), 'plugin', 'example-plugin', self::VERSION, 'https://github.com/owner/other' );
		$duplicate = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'second.php'         => $this->pluginHeader(),
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertSame( 'version_contract_custom', $constant->code() );
		self::assertSame( 'repository_unsupported', $wrongUri->code() );
		self::assertSame( 'package_ambiguous', $duplicate->code() );
	}

	public function testUnknownRuntimeGeneratedCollisionAndExecutableModeRefuse(): void {
		$assessor   = new SourceReadyAssessor();
		$unknown    = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'mystery/data.bin'   => 'data',
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);
		$collision  = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'version.txt'        => self::VERSION,
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);
		$executable = $this->snapshot( array( 'example-plugin.php' => $this->pluginHeader() ), array( 'example-plugin.php' => '100755' ) );

		self::assertSame( 'runtime_paths_unknown', $unknown->code() );
		self::assertSame( 'release_path_conflict', $collision->code() );
		self::assertSame( 'runtime_paths_unknown', $assessor->assess( $executable, 'plugin', 'example-plugin', self::VERSION, 'https://github.com/' . self::REPOSITORY )->code() );
	}

	public function testCompetingReleaseAutomationOutranksAGeneratedPathCollision(): void {
		$result = ( new SourceReadyAssessor() )->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'version.txt'        => self::VERSION,
					'.github/workflows/publish-release.yml' => "steps:\n  - uses: softprops/action-gh-release@v2\n",
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertSame( 'release_automation_conflict', $result->code() );
	}


	public function testCustomPrettierOwnershipAndPotVersioningRefuse(): void {
		$assessor = new SourceReadyAssessor();
		$custom   = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'package.json'       => '{"version":"1.2.3","scripts":{"format":"prettier --ignore-path custom.ignore ."}}',
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);
		$negated  = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					'.prettierignore'    => "!/CHANGELOG.md\n",
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);
		$pot      = $assessor->assess(
			$this->snapshot(
				array(
					'example-plugin.php'    => $this->pluginHeader(),
					'languages/example.pot' => 'msgid ""',
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertSame( 'version_contract_custom', $custom->code() );
		self::assertTrue( $negated->readyForBootstrap() );
		self::assertArrayNotHasKey( '.prettierignore', $negated->modifiedFiles() );
		self::assertSame( 'version_contract_custom', $pot->code() );
	}

	public function testExistingWorkflowGraphsRequireManualIntegration(): void {
		$result = ( new SourceReadyAssessor() )->assess(
			$this->snapshot(
				array(
					'example-plugin.php'                   => $this->pluginHeader(),
					'.github/workflows/release-check.yml'  => "name: Release checks\npermissions:\n  contents: read\njobs:\n  inspect:\n    steps:\n      # uses: softprops/action-gh-release@v2\n      - uses: actions/upload-artifact@v4\n      - run: gh release view v1.2.3\n      - run: gh api --method GET repos/owner/example-plugin/releases\n",
					'.github/workflows/reusable-check.yml' => "# contents: write\npermissions:\n  contents: read\njobs:\n  checks:\n    uses: owner/tools/.github/workflows/publish.yml@main\n",
					'.github/workflows/write-quality.yml'  => "permissions:\n  contents: write\njobs:\n  checks:\n    uses: owner/tools/.github/workflows/quality.yml@main\n",
					'scripts/release-inspect.sh'           => "#!/bin/sh\n# gh release create v1.2.3\necho gh release create v1.2.3\necho gh api repos/owner/example-plugin/releases --field tag_name=v1.2.3\nprintf 'curl --data tag https://api.github.com/repos/owner/example-plugin/releases'\ngh release list\ncurl -f --request GET https://api.github.com/repos/owner/example-plugin/releases\ncurl -x post https://api.github.com/repos/owner/example-plugin/releases\n",
					'scripts/publish.sh'                   => "#!/bin/sh\nprintf '%s\\n' 'semantic-release release-please release-it'\n",
					'package.json'                         => '{"version":"1.2.3","description":"release-please semantic-release","scripts":{"publish":"printf release-please","inspect":"gh release view v1.2.3"},"devDependencies":{"release-please-docs":"1.0.0"}}',
					'composer.json'                        => '{"description":"semantic-release","scripts":{"publish":"printf release-it"}}',
					'Makefile'                             => "publish:\n\tprintf 'release-please'\n",
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertSame( 'release_automation_conflict', $result->code() );
	}

	#[DataProvider( 'existingReleaseAutomationProvider' )]
	public function testExistingReleaseAutomationFailsClosed( string $path, string $content ): void {
		$result = ( new SourceReadyAssessor() )->assess(
			$this->snapshot(
				array(
					'example-plugin.php' => $this->pluginHeader(),
					$path                => $content,
				)
			),
			'plugin',
			'example-plugin',
			self::VERSION,
			'https://github.com/' . self::REPOSITORY
		);

		self::assertSame( 'release_automation_conflict', $result->code() );
	}

	/** @return iterable<string,array{string,string}> */
	public static function existingReleaseAutomationProvider(): iterable {
		foreach ( array(
			'actions/create-release',
			'actions/upload-release-asset',
			'changesets/action',
			'cycjimmy/semantic-release-action',
			'googleapis/release-please-action',
			'goreleaser/goreleaser-action',
			'marvinpinto/action-automatic-releases',
			'ncipollo/release-action',
			'release-drafter/release-drafter',
			'softprops/action-gh-release',
			'svenstaro/upload-release-action',
		) as $action ) {
			yield 'known action ' . $action => array( '.github/workflows/ci.yml', "steps:\n  - uses: " . $action . "@v2\n" );
		}
		yield 'gh release create' => array( 'scripts/package.sh', "#!/bin/sh\ngh release create v1.2.3\n" );
		yield 'gh release upload after command' => array( 'scripts/package.sh', "#!/bin/sh\nprintf built && gh release upload v1.2.3 file.zip\n" );
		yield 'workflow gh release command' => array( '.github/workflows/release.yml', "steps:\n  - run: gh release delete v1.2.3\n" );
		yield 'release please executable' => array( 'scripts/package.sh', "#!/bin/sh\npnpm exec release-please github-release\n" );
		yield 'wrapped semantic release executable' => array( 'package.json', '{"version":"1.2.3","scripts":{"release":"cross-env CI=true semantic-release"}}' );
		yield 'semantic release executable' => array( 'package.json', '{"version":"1.2.3","scripts":{"release":"semantic-release"}}' );
		yield 'release it executable' => array( 'composer.json', '{"scripts":{"release":"release-it --ci"}}' );
		yield 'goreleaser executable' => array( 'Makefile', "release:\n\tgoreleaser release\n" );
		yield 'release tool dependency' => array( 'package.json', '{"version":"1.2.3","devDependencies":{"semantic-release":"24.0.0"}}' );
		yield 'release tool plugin dependency' => array( 'package.json', '{"version":"1.2.3","devDependencies":{"@semantic-release/github":"11.0.0"}}' );
		yield 'nested config' => array( 'config/release-please-config.json', '{}' );
		yield 'nested manifest' => array( 'config/.release-please-manifest.json', '{}' );
		yield 'write capable reusable release workflow' => array( '.github/workflows/ci.yaml', "permissions:\n  contents: write\njobs:\n  call:\n    uses: owner/repo/.github/workflows/publish.yml@main\n" );
		yield 'inline write capable reusable release workflow' => array( '.github/workflows/ci.yaml', "permissions: { actions: read, contents: write }\njobs:\n  call:\n    uses: owner/repo/.github/workflows/release.yml@main\n" );
		yield 'write all reusable release workflow' => array( '.github/workflows/ci.yaml', "permissions: write-all\njobs:\n  call:\n    uses: owner/repo/.github/workflows/release.yml@main\n" );
		yield 'curl explicit post' => array( '.ci/package.sh', "curl --request POST https://api.github.com/repos/owner/repo/releases\n" );
		yield 'curl implicit post' => array( '.ci/package.sh', "curl https://api.github.com/repos/owner/repo/releases --data tag_name=v1.2.3\n" );
		yield 'gh api explicit patch' => array( '.github/scripts/api.sh', "gh api repos/owner/repo/releases/7 --method PATCH\n" );
		yield 'gh api implicit post' => array( 'scripts/api.sh', "gh api repos/owner/repo/releases --raw-field tag_name=v1.2.3\n" );
		yield 'gh api short raw field' => array( 'scripts/api.sh', "gh api repos/owner/repo/releases -f tag_name=v1.2.3\n" );
		yield 'gh api short typed field' => array( 'scripts/api.sh', "gh api repos/owner/repo/releases -F tag_name=v1.2.3\n" );
		yield 'gh api input body' => array( 'scripts/api.sh', "gh api repos/owner/repo/releases --input release.json\n" );
		yield 'curl binary body' => array( 'scripts/api.sh', "curl --data-binary @release.json https://api.github.com/repos/owner/repo/releases\n" );
		yield 'curl encoded body' => array( 'scripts/api.sh', "curl --data-urlencode tag_name=v1.2.3 https://api.github.com/repos/owner/repo/releases\n" );
		yield 'curl form body' => array( 'scripts/api.sh', "curl --form tag_name=v1.2.3 https://api.github.com/repos/owner/repo/releases\n" );
		yield 'curl short form body' => array( 'scripts/api.sh', "curl -Ftag_name=v1.2.3 https://api.github.com/repos/owner/repo/releases\n" );
	}

	public function testAssessmentRejectsUnknownRefusalAndUnsafeReadyShapes(): void {
		try {
			SourceReadyAssessment::refused( 'open_ended_result' );
			self::fail( 'Unknown refusal codes must be closed.' );
		} catch ( InvalidArgumentException ) {
			self::assertTrue( true );
		}
		$this->expectException( InvalidArgumentException::class );
		SourceReadyAssessment::ready(
			'source-ready-wordpress-plugin/3',
			'example-plugin',
			'../example-plugin.php',
			self::VERSION,
			array( '../example-plugin.php' ),
			array( '../example-plugin.php' => '<?php' ),
			array(
				array(
					'type' => 'generic',
					'path' => '../example-plugin.php',
				),
			),
			'8.2'
		);
	}

	#[DataProvider( 'unsafeAssessmentPaths' )]
	public function testAssessmentRejectsUnsafePathEncoding( string $path ): void {
		$this->expectException( InvalidArgumentException::class );
		SourceReadyAssessment::ready(
			'source-ready-wordpress-plugin/3',
			'example-plugin',
			$path,
			self::VERSION,
			array( $path ),
			array( $path => '<?php' ),
			array(
				array(
					'type' => 'generic',
					'path' => $path,
				),
			),
			'8.2'
		);
	}

	/** @return iterable<string,array{string}> */
	public static function unsafeAssessmentPaths(): iterable {
		yield 'nul' => array( "example\0.php" );
		yield 'invalid utf8' => array( "example\xC3\x28.php" );
	}

	/** @param array<string,string> $documents @param array<string,string> $modes */
	private function snapshot( array $documents, array $modes = array() ): RepositorySnapshot {
		$entries = array();
		foreach ( $documents as $path => $document ) {
			$entries[ $path ] = array(
				'type' => 'blob',
				'mode' => $modes[ $path ] ?? '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $document ),
			);
		}
		return new RepositorySnapshot( '101', self::REPOSITORY, 'main', str_repeat( 'a', 40 ), $entries, $documents );
	}

	private function pluginHeader(): string {
		return "<?php\n/**\n * Plugin Name: Example\n * Requires PHP: 8.2\n * Requires at least: 7.0\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n";
	}
}
