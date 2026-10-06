<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ReleaseUpdaterAdoptionTest extends TestCase {

	public function test_installed_release_matches_lock_and_its_runtime_content_identity(): void {
		$provider_root = dirname( __DIR__, 3 );
		$root          = $provider_root . '/vendor/ran/wp-release-updater';
		require $provider_root . '/vendor/autoload.php';
		$lock     = $this->read_json( $provider_root . '/composer.lock' );
		$packages = array_values( array_filter( $lock['packages'], static fn ( array $package ): bool => 'ran/wp-release-updater' === $package['name'] ) );
		self::assertCount( 1, $packages );
		$copy = $this->read_json( $root . '/runtime-copy.json' );
		self::assertSame( 5, $copy['runtime_protocol'] );
		self::assertSame( ltrim( $packages[0]['version'], 'v' ), $copy['package_version'] );
		self::assertSame( $packages[0]['source']['reference'], \Composer\InstalledVersions::getReference( 'ran/wp-release-updater' ) );
		self::assertSame( realpath( $root ), realpath( \Composer\InstalledVersions::getInstallPath( 'ran/wp-release-updater' ) ) );

		$files    = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			self::assertFalse( $entry->isLink() );
			if ( $entry->isFile() && 'php' === $entry->getExtension() ) {
				$files[] = substr( $entry->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}
		self::assertSame( $copy['package_revision'], hash( 'sha256', $payload ) );
	}

	public function test_repeated_installed_bootstrap_shares_protocol_and_fails_closed_before_activation(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterWordPressFunctions.php';
		$root   = dirname( __DIR__, 3 ) . '/vendor/ran/wp-release-updater';
		$first  = require $root . '/bootstrap.php';
		$second = require $root . '/bootstrap.php';
		self::assertSame( 5, $first->diagnostics()['protocol_version'] );
		self::assertSame( 1, $first->diagnostics()['candidate_count'] );
		self::assertSame( $first->diagnostics(), $second->diagnostics() );
		foreach ( array( $first, $second ) as $registrar ) {
			foreach ( array( 'plugin', 'theme', 'releases', 'diagnostics' ) as $method ) {
				self::assertTrue( is_callable( array( $registrar, $method ) ) );
			}
			$source = $registrar->releases( 'github', 'plugin', 'owner/example', '123456789' );
			$result = $source->list();
			self::assertFalse( $result['ok'] );
			self::assertSame( 'runtime_not_ready', $result['code'] );
			self::assertNull( $result['value'] );
			self::assertSame( 'not_applicable', $result['cleanup_status'] );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_release_requests'] ?? array() );

		require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';
		NeutralReleaseUpdaterFixtures::reset();
		self::assertSame( 'active', $first->diagnostics()['state'] );
		self::assertSame( 1, $second->diagnostics()['candidate_count'] );
		\WP_Filesystem();
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::listing( array() ) ) );
		$result = $source->list();
		self::assertTrue( $result['ok'] );
		self::assertSame( array(), $result['value']['candidates'] );
		self::assertCount( 1, NeutralReleaseUpdaterFixtures::requests() );
	}

	/** @return array<string, mixed> */
	private function read_json( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the installed dependency and committed lock without network access.
		return json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
	}
}
