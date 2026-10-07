<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\InitialReleaseBundle;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

final class ProducerExchangeTest extends TestCase {
	public function test_actual_qualified_producer_zip_and_all_ten_rendered_digests(): void {
		$dir = dirname( __DIR__, 4 ) . '/fixtures/api3-producer';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the committed producer ZIP/manifest bytes exactly for provenance and digest assertions.
		$bytes = file_get_contents( $dir . '/ran-booster-release-bootstrap-templates.zip' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the committed producer ZIP/manifest bytes exactly for provenance and digest assertions.
		$e = json_decode( file_get_contents( $dir . '/producer-exchange.json' ), true, 512, JSON_THROW_ON_ERROR );
		if ( strlen( $bytes ) !== 10132 || hash( 'sha256', $bytes ) !== '2da459b63715660226b43914d3466f8b176bf645961dc0009fb51168c21ae7cf' ) {
			throw new \RuntimeException( 'ZIP mismatch' );
		}
		self::assertSame( 11028442531, $e['artifact_id'] );
		self::assertSame( '36559291561', $e['workflow_run_id'] );
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
		$result = TemplatePack::from_archive( $bytes, $i );
		if ( 'ok' !== $result['code'] ) {
			self::fail( $result['code'] );
		}
		$p = $result['pack'];
		if ( $p->manifest_hash() !== $e['manifest_sha256'] ) {
			throw new \RuntimeException( 'Manifest mismatch' );
		}

		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$this->verify_bundle_execution( $p, $type );
			$profile = 'source-ready-wordpress-' . $type . '/3';
			$header  = 'plugin' === $type ? 'example-package.php' : 'style.css';
			$values  = array(
				'quality-workflow'      => array(
					'PACKAGE_SLUG' => 'example-package',
					'PHP_VERSION'  => '8.0',
				),
				'release-workflow'      => array( 'PACKAGE_SLUG' => 'example-package' ),
				'release-please-config' => array(
					'BASE_SHA'         => '0123456789abcdef0123456789abcdef01234567',
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Match producer EXTRA_FILES_JSON bytes with JSON_UNESCAPED_SLASHES for the recorded rendered digest.
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
				if ( 'ok' !== $rendered['code'] || hash( 'sha256', $rendered['content'] ) !== $e['profiles'][ $profile ][ $logical ]['rendered_sha256'] ) {
					self::fail( 'Render mismatch: ' . $profile . '/' . $logical );
				}self::assertSame( $e['profiles'][ $profile ][ $logical ]['rendered_sha256'], hash( 'sha256', $rendered['content'] ) );}
		}
	}
	private function verify_bundle_execution( TemplatePack $pack, string $type ): void {
		$header    = 'plugin' === $type ? 'example-package.php' : 'style.css';
		$content   = 'plugin' === $type
			? "<?php\n/**\n * Plugin Name: Example\n * Requires PHP: 8.0\n * Requires at least: 7.0\n * Version: 1.2.3\n * Update URI: https://github.com/example/example-package\n */\n"
			: "/*\nTheme Name: Example\nRequires PHP: 8.0\nRequires at least: 7.0\nVersion: 1.2.3\nUpdate URI: https://github.com/example/example-package\n*/\n";
		$documents = array(
			$header               => $content,
			'assets/repeated.txt' => str_repeat( 'A', 100000 ),
		);
		if ( 'theme' === $type ) {
			$documents['templates/index.html'] = '<!-- wp:post-content /-->'; }
		$entries = array();
		foreach ( $documents as $path => $bytes ) {
			$entries[ $path ] = array(
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ),
				'size' => strlen( $bytes ),
			);
		}
		$snapshot   = new RepositorySnapshot( '101', 'example/example-package', 'main', str_repeat( 'a', 40 ), $entries, $documents );
		$assessment = ( new SourceReadyAssessor() )->assess( $snapshot, $type, 'example-package', '1.2.3', 'https://github.com/example/example-package' );
		self::assertTrue( $assessment->ready_for_bootstrap() );
		$result = InitialReleaseBundle::bootstrap( $pack, $assessment, $snapshot, 'https://github.com/example/example-package' );
		self::assertSame( 'ok', $result['code'] );
		$files = $result['bundle']->files();
		self::assertSame( implode( "\n", $assessment->release_files() ) . "\n", $files['release-contents.txt']['content'] );
		$root = sys_get_temp_dir() . '/ran-api3-build-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Build and remove only the disposable local Git fixture using native filesystem semantics.
		mkdir( $root, 0700 );
		try {
			foreach ( $files as $path => $file ) {
				$documents[ $path ] = $file['content'];
				self::assertSame( '100644', $file['mode'] ); }
			foreach ( $documents as $path => $bytes ) {
				if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Build and remove only the disposable local Git fixture using native filesystem semantics.
					mkdir( dirname( $root . '/' . $path ), 0700, true ); }
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Build and remove only the disposable local Git fixture using native filesystem semantics.
				file_put_contents( $root . '/' . $path, $bytes );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set exact disposable fixture modes for reproducible local Git archives.
				chmod( $root . '/' . $path, 0644 );
			}
			$this->command( array( 'git', 'init', '-q' ), $root );
			$this->command( array( 'git', 'add', '.' ), $root );
			$this->command( array( 'git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'fixture' ), $root );
			$commit = trim( $this->command( array( 'git', 'rev-parse', 'HEAD' ), $root ) );
			foreach ( array( 'one', 'two' ) as $build ) {
				$this->command( array( 'bash', 'scripts/build-release.sh', $commit, '1.2.3', $root . '/dist-' . $build ), $root );
				$this->command( array( 'bash', 'scripts/verify-release.sh', $root . '/dist-' . $build . '/example-package-1.2.3.zip', '1.2.3', $commit ), $root );
			}
			self::assertSame( hash_file( 'sha256', $root . '/dist-one/example-package-1.2.3.zip' ), hash_file( 'sha256', $root . '/dist-two/example-package-1.2.3.zip' ) );
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $root . '/dist-one/example-package-1.2.3.zip' ) );
			self::assertSame( \ZipArchive::CM_STORE, $zip->statName( 'example-package/assets/repeated.txt' )['comp_method'] );
			$zip->close();
		} finally {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Build and remove only the disposable local Git fixture using native filesystem semantics.
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Build and remove only the disposable local Git fixture using native filesystem semantics.
			rmdir( $root );
		}
	}

	/** @param list<string> $arguments */
	private function command( array $arguments, string $directory ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Argument-vector process runs only the reviewed committed fixture in a disposable local repository.
		$process = proc_open(
			$arguments,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$directory
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess pipe; WordPress filesystem APIs do not manage process streams.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess pipe; WordPress filesystem APIs do not manage process streams.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess pipe; WordPress filesystem APIs do not manage process streams.
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $output . $error );
		return $output;
	}
}
