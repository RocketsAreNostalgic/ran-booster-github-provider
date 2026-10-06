<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

/** Proves GitHub consumes host/updater archive policy without owning it. */
final class ArchiveLimitBoundaryTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	public function test_release_inspection_resolves_and_validates_supplied_limit_lazily(): void {
		$limit_reads = 0;
		$registrar   = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function releases( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					/** @return array<string, mixed> */
					public function inspect( string $release_identity, string $tag ): array {
						return array(
							'ok'             => true,
							'code'           => 'release_inspected',
							'value'          => array(
								'release_identity'       => $release_identity,
								'tag'                    => $tag,
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
								'maximum_artifact_bytes' => 1048576,
							),
							'retry_after'    => null,
							'cleanup_status' => 'complete',
						);
					}
				};
			}
		};
		$provider    = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			static function () use ( &$limit_reads ): int {
				++$limit_reads;

				return 1048576;
			}
		);
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );

		self::assertSame( 0, $limit_reads );
		self::assertSame(
			'v2:' . str_repeat( 'b', 64 ),
			$provider->inspect_release( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint
		);
		self::assertSame( 2, $limit_reads );
		self::assertCount( 7, $registrar->arguments );
		self::assertSame( 1048576, $registrar->arguments[6] );
	}

	public function test_release_source_forwards_api11_host_limit(): void {
		$registrar  = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function maximum_artifact_bytes(): int {
				throw new \LogicException( 'GitHub must not discover host policy from the updater registrar.' );
			}

			public function releases( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					/** @return array<string, mixed> */
					public function list(): array {
						return array(
							'ok'             => true,
							'code'           => 'releases_listed',
							'value'          => array(
								'candidates'   => array(),
								'not_modified' => false,
							),
							'retry_after'    => null,
							'cleanup_status' => 'not_applicable',
						);
					}
				};
			}
		};
		$provider   = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			static fn (): int => 52_428_800
		);
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		$provider->list_release_candidates( 'plugin', $repository, 'stable' );
		self::assertCount( 7, $registrar->arguments );
		self::assertSame( 52_428_800, $registrar->arguments[6] );
	}

	public function test_unavailable_release_runtime_fails_only_at_release_boundary(): void {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new class() {
				public function releases( mixed ...$arguments ): object {
					unset( $arguments );

					return new class() {};
				}
			}
		);

		self::assertSame( 'gh', $provider->get_metadata()->code->value );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$provider->list_release_candidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);
	}

	public function test_native_target_forwards_supplied_host_limit(): void {
		$runtime = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			$runtime,
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			null,
			'stable',
			'manual',
			static fn (): int => 1048576
		);

		self::assertTrue( $target->register() );
		self::assertCount( 8, $runtime->arguments );
		self::assertSame( 1048576, $runtime->arguments[7] );
	}

	public function test_direct_native_target_without_host_limit_uses_updater_owned_default_contract(): void {
		$runtime = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			$runtime,
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			null,
			'stable',
			'manual'
		);

		self::assertTrue( $target->register() );
		self::assertCount( 7, $runtime->arguments );
	}

	public function test_provider_created_native_target_forwards_api11_host_limit(): void {
		$runtime  = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$runtime,
			static fn (): int => 52_428_800
		);
		$target   = $provider->create_native_target(
			'plugin',
			new RepositoryReference( 'owner/example', '42', false, null ),
			'/wordpress/wp-content/plugins/example/example.php',
			'example',
			'example/example.php',
			'stable',
			'manual'
		);

		self::assertTrue( $target->register() );
		self::assertCount( 8, $runtime->arguments );
		self::assertSame( 52_428_800, $runtime->arguments[7] );
	}

	public function test_git_hub_release_adapters_do_not_own_booster_default_literal(): void {
		foreach ( array( GitHubProvider::class, GitHubReleaseNativeTarget::class ) as $class ) {
			$file = ( new \ReflectionClass( $class ) )->getFileName();
			self::assertIsString( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source-level ownership regression assertion.
			$source = file_get_contents( $file );
			self::assertIsString( $source );
			self::assertStringNotContainsString( '52428800', $source );
			self::assertStringNotContainsString( 'DEFAULT_MAXIMUM_ARTIFACT_BYTES', $source );
		}
	}
}
