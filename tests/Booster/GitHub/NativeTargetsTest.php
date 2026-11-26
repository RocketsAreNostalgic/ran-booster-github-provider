<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget;

final class NativeTargetsTest extends TestCase {

	public function test_check_source_matches_only_exact_captured_runtime_identity(): void {
		$target = $this->target( null );
		self::assertTrue( $target->matches_check_source( 'github', '42', 'stable' ) );
		self::assertFalse( $target->matches_check_source( 'gh', '42', 'stable' ) );
		self::assertFalse( $target->matches_check_source( 'github', '042', 'stable' ) );
		self::assertFalse( $target->matches_check_source( 'github', '42', 'prerelease' ) );
	}

	public function test_construction_does_not_resolve_private_credentials(): void {
		$reads  = 0;
		$target = $this->target(
			static function () use ( &$reads ): string {
				++$reads;

				return 'github_pat_current';
			}
		);

		self::assertSame( 0, $reads );
		self::assertFalse( $target->status()->active );
		self::assertFalse( $target->refresh() );
		self::assertSame( 0, $reads );
	}

	public function test_callable_looking_access_token_remains_credential_material(): void {
		$target       = $this->target( 'strlen' );
		$access_token = ( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'access_token' ) )->getValue( $target );

		self::assertInstanceOf( \Closure::class, $access_token );
	}

	public function test_native_status_fails_closed_until_the_neutral_runtime_supplies_one(): void {
		$status = $this->target( null )->status();

		self::assertFalse( $status->active );
		self::assertSame( '', $status->offered_version );
		self::assertSame( '', $status->failure_code );
		self::assertSame( '', $status->candidate_code );
	}

	public function test_registered_neutral_updater_is_active(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new \stdClass()
		);

		$status = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failure_code );
	}

	/** @param array<string, mixed> $patch */
	#[DataProvider( 'native_status_shapes' )]
	public function test_registered_neutral_updater_projects_its_bounded_status( array $patch, ?string $missing_key, bool $compatible ): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class( $patch, $missing_key ) {
				/** @param array<string, mixed> $patch */
				public function __construct( private array $patch, private ?string $missing_key ) {
				}

				/** @return array<string, mixed> */
				public function status(): array {
					$outer           = array(
						'state'                => 'active',
						'declaration_accepted' => true,
						'hooks_registered'     => true,
						'code'                 => 'target_active',
						'native'               => array(
							'candidate_header_version'  => '1.2.0',
							'candidate_tag'             => 'v1.2.0',
							'candidate_validation_code' => 'archive_identity_verified',
							'candidate_version'         => '1.2.0',
							'failure_code'              => null,
							'installed_version'         => '1.0.0',
							'last_check'                => 1_700_000_000,
							'last_native_outcome'       => null,
							'offered_release_identity'  => 'provider-release-42',
							'offered_version'           => '1.2.0',
							'relationship'              => 'newer',
						),
					);
					$outer['native'] = array_replace( $outer['native'], $this->patch );
					if ( null !== $this->missing_key ) {
						unset( $outer['native'][ $this->missing_key ] );
					}
					return $outer;
				}
			}
		);

		$status = $target->status();

		self::assertSame( $compatible, $status->active );
		if ( ! $compatible ) {
			self::assertSame( 'github_updater_status_unavailable', $status->failure_code );
			self::assertSame( '', $status->offered_version );
			return;
		}
		self::assertSame( '1.2.0', $status->offered_version );
		self::assertSame( 'newer', $status->version_relationship );
		self::assertSame( 1_700_000_000, $status->last_check );
		self::assertNull( $status->next_check );
		self::assertSame( 'release_identity_verified', $status->candidate_code );
		self::assertSame( 'v1.2.0', $status->candidate_release_tag );
		self::assertSame( '1.2.0', $status->candidate_release_version );
		self::assertSame( '1.2.0', $status->candidate_package_header_version );
		self::assertSame( 'provider-release-42', $status->candidate_provider_release_id );
	}

	/** @return array<string, array{array<string, mixed>, ?string, bool}> */
	public static function native_status_shapes(): array {
		$cases       = array(
			'null outcome'          => array( array(), null, true ),
			'empty array outcome'   => array( array( 'last_native_outcome' => array() ), null, true ),
			'opaque array outcome'  => array( array( 'last_native_outcome' => array( 'unconsumed' => new \stdClass() ) ), null, true ),
			'legacy ten keys'       => array( array(), 'last_native_outcome', true ),
			'unknown extra key'     => array( array( 'unknown' => null ), null, false ),
			'replaced required key' => array( array( 'unknown' => null ), 'last_check', false ),
		);
		$uninspected = array(
			'candidate_header_version'  => null,
			'candidate_validation_code' => null,
		);
		foreach ( array(
			'missing advisory tag'          => array( 'candidate_tag' => null ),
			'mismatched advisory version'   => array( 'candidate_version' => '1.1.0' ),
			'unverified header on advisory' => array( 'candidate_header_version' => '1.2.0' ),
			'non-newer advisory'            => array( 'relationship' => 'older' ),
		) as $label => $invalid ) {
			$cases[ $label ] = array( array_replace( $uninspected, $invalid ), null, false );
		}
		foreach ( array( false, 1, 'outcome', new \stdClass() ) as $index => $outcome ) {
			$cases[ 'invalid outcome ' . $index ] = array( array( 'last_native_outcome' => $outcome ), null, false );
		}
		foreach ( array( 'candidate_header_version', 'candidate_tag', 'candidate_validation_code', 'candidate_version', 'failure_code', 'installed_version', 'last_check', 'offered_release_identity', 'offered_version', 'relationship' ) as $key ) {
			$cases[ 'missing ' . $key ] = array( array(), $key, false );
			$cases[ 'invalid ' . $key ] = array( array( $key => false ), null, false );
		}
		return $cases;
	}

	public function test_uninspected_native_offer_remains_active_without_claiming_proof(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$registrar = new class() {
				/** @param array<int|string, mixed> $arguments */
				public function __call( string $name, array $arguments ): object {
					return new class() {
						public function register(): bool {
							return true;
						}
						/** @return array<string, mixed> */
						public function status(): array {
							return array(
								'state'                => 'active',
								'declaration_accepted' => true,
								'hooks_registered'     => true,
								'code'                 => 'target_active',
								'native'               => array(
									'candidate_header_version' => null,
									'candidate_tag'     => 'v1.2.0',
									'candidate_validation_code' => null,
									'candidate_version' => '1.2.0',
									'failure_code'      => null,
									'installed_version' => '1.0.0',
									'last_check'        => 1700000000,
									'offered_release_identity' => '42',
									'offered_version'   => '1.2.0',
									'relationship'      => 'newer',
								),
							);
						}
					};
				}
			};
			$target    = new GitHubReleaseNativeTarget( $registrar, $type, 'package/file.php', 'owner/repo', '123', null, 'stable', 'manual' );
			self::assertTrue( $target->register() );
			$status = $target->status();
			self::assertTrue( $status->active );
			self::assertSame( '1.2.0', $status->offered_version );
			self::assertSame( 'newer', $status->version_relationship );
			self::assertSame( '', $status->candidate_code );
			self::assertSame( '', $status->candidate_provider_release_id );
		}
	}

	public function test_registered_neutral_updater_projects_a_reordered_outer_status(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'native'               => array(
							'candidate_header_version'  => '1.2.0',
							'candidate_tag'             => 'v1.2.0',
							'candidate_validation_code' => 'archive_identity_verified',
							'candidate_version'         => '1.2.0',
							'failure_code'              => null,
							'installed_version'         => '1.0.0',
							'last_check'                => 1_700_000_000,
							'last_native_outcome'       => null,
							'offered_release_identity'  => 'provider-release-42',
							'offered_version'           => '1.2.0',
							'relationship'              => 'newer',
						),
						'code'                 => 'target_active',
						'hooks_registered'     => true,
						'declaration_accepted' => true,
						'state'                => 'active',
					);
				}
			}
		);

		$status = $target->status();

		self::assertTrue( $status->active );
		self::assertSame( '1.2.0', $status->offered_version );
		self::assertSame( 'release_identity_verified', $status->candidate_code );
	}

	public function test_native_offer_requires_its_opaque_identity_and_version_together(): void {
		foreach ( array(
			array( 'provider-release-42', null ),
			array( null, '1.2.0' ),
			array( "provider\x00release", '1.2.0' ),
		) as list( $identity, $version ) ) {
			$target = $this->target( null );
			( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
				$target,
				new class( $identity, $version ) {
					public function __construct( private mixed $identity, private mixed $version ) {
					}

					/** @return array<string, mixed> */
					public function status(): array {
						return array(
							'state'                => 'active',
							'declaration_accepted' => true,
							'hooks_registered'     => true,
							'code'                 => 'target_active',
							'native'               => array(
								'offered_version'          => $this->version,
								'candidate_tag'            => 'v1.2.0',
								'candidate_header_version' => '1.2.0',
								'candidate_validation_code' => 'archive_identity_verified',
								'candidate_version'        => '1.2.0',
								'failure_code'             => null,
								'installed_version'        => '1.0.0',
								'last_check'               => 1_700_000_000,
								'last_native_outcome'      => null,
								'relationship'             => 'newer',
								'offered_release_identity' => $this->identity,
							),
						);
					}
				}
			);

			self::assertFalse( $target->status()->active );
			self::assertSame( 'github_updater_status_unavailable', $target->status()->failure_code );
		}
	}

	public function test_null_native_offer_projects_no_identity(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'active',
						'declaration_accepted' => true,
						'hooks_registered'     => true,
						'code'                 => 'target_active',
						'native'               => array(
							'candidate_header_version'  => null,
							'candidate_tag'             => null,
							'candidate_validation_code' => null,
							'candidate_version'         => null,
							'failure_code'              => null,
							'installed_version'         => '1.0.0',
							'last_check'                => 1_700_000_000,
							'last_native_outcome'       => null,
							'offered_release_identity'  => null,
							'offered_version'           => null,
							'relationship'              => null,
						),
					);
				}
			}
		);

		$status = $target->status();
		self::assertTrue( $status->active );
		self::assertSame( '', $status->offered_version );
		self::assertSame( '', $status->candidate_provider_release_id );
	}

	/** @return iterable<string, array{string, string}> */
	public static function rejected_candidate_validation_codes(): iterable {
		yield 'digest missing' => array( 'digest_missing', 'digest_missing' );
		yield 'unknown code stays generic incompatible' => array( 'future_validation_code', 'github_updater_release_incompatible' );
	}

	#[DataProvider( 'rejected_candidate_validation_codes' )]
	public function test_candidate_validation_maps_digest_missing_without_collapsing_to_generic_incompatible( string $validation_code, string $expected_code ): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class( $validation_code ) {
				public function __construct( private string $validation_code ) {
				}

				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'active',
						'declaration_accepted' => true,
						'hooks_registered'     => true,
						'code'                 => 'target_active',
						'native'               => array(
							'candidate_header_version'  => null,
							'candidate_tag'             => 'v1.2.0',
							'candidate_validation_code' => $this->validation_code,
							'candidate_version'         => '1.2.0',
							'failure_code'              => null,
							'installed_version'         => '1.0.0',
							'last_check'                => 1_700_000_000,
							'last_native_outcome'       => null,
							'offered_release_identity'  => null,
							'offered_version'           => null,
							'relationship'              => 'newer',
						),
					);
				}
			}
		);

		$status = $target->status();

		self::assertTrue( $status->active );
		self::assertSame( $expected_code, $status->candidate_code );
		self::assertSame( 'v1.2.0', $status->candidate_release_tag );
		self::assertSame( '1.2.0', $status->candidate_release_version );
	}

	public function test_queued_and_inactive_neutral_states_do_not_claim_native_authority(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'queued',
						'declaration_accepted' => true,
						'hooks_registered'     => false,
						'code'                 => 'awaiting_activation',
						'native'               => null,
					);
				}
			}
		);

		self::assertFalse( $target->status()->active );
		self::assertSame( '', $target->status()->failure_code );

		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'inactive',
						'declaration_accepted' => false,
						'hooks_registered'     => false,
						'code'                 => 'runtime_handoff_invalid',
						'native'               => null,
					);
				}
			}
		);

		$status = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_runtime_handoff_invalid', $status->failure_code );
		self::assertLessThanOrEqual( 64, strlen( $status->failure_code ) );
	}

	public function test_repeated_registration_reflects_declined_and_throwing_public_handles(): void {
		$declined  = new class() {
			public int $calls = 0;

			public function register(): bool {
				++$this->calls;

				return false;
			}
		};
		$registrar = new class( $declined ) {
			public int $calls = 0;

			public function __construct( private object $handle ) {
			}

			public function plugin( mixed ...$arguments ): object {
				unset( $arguments );
				++$this->calls;

				return $this->handle;
			}
		};
		$target    = $this->target( null, $registrar );

		self::assertFalse( $target->register() );
		self::assertFalse( $target->register() );
		self::assertSame( 1, $registrar->calls );
		self::assertSame( 2, $declined->calls );

		$throwing = new class() {
			public function register(): bool {
				throw new \RuntimeException( 'declaration failed' );
			}
		};
		$target   = $this->target(
			null,
			new class( $throwing ) {
				public function __construct( private object $handle ) {
				}

				public function plugin( mixed ...$arguments ): object {
					unset( $arguments );

					return $this->handle;
				}
			}
		);

		self::assertFalse( $target->register() );
	}

	public function test_registered_neutral_updater_fails_closed_on_malformed_status(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, int|string|null> */
				public function status(): array {
					return array();
				}
			}
		);

		$status = $target->status();

		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failure_code );
		self::assertSame( '', $status->offered_version );
		self::assertSame( '', $status->candidate_code );
	}

	public function test_registration_rejects_missing_registrar_and_updater_methods(): void {
		self::assertFalse( $this->target( null )->register() );
		$registrar = new class() {
			public function plugin( mixed ...$arguments ): object {
				TestCase::assertCount( 7, $arguments );
				return new \stdClass();
			}
		};
		$target    = $this->target( null, $registrar );
		self::assertFalse( $target->register() );
		self::assertFalse( $target->register() );
	}

	private function target( string|callable|null $access_token, ?object $registrar = null ): GitHubReleaseNativeTarget {
		return new GitHubReleaseNativeTarget(
			$registrar ?? new \stdClass(),
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			$access_token,
			'stable',
			'manual'
		);
	}
}
