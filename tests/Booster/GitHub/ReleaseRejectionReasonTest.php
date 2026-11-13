<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseRejectionReasonTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	/**
	 * Updater reason => Core reason code, or null where Core has no code yet.
	 *
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function reason_map(): array {
		$map  = array(
			'release_draft'              => null,
			'tag_not_version'            => 'release_version_invalid',
			'tag_mismatch'               => null,
			'prerelease_not_allowed'     => null,
			'no_package_zip'             => 'package_asset_missing',
			'multiple_package_zips'      => 'package_asset_ambiguous',
			'asset_not_uploaded'         => null,
			'asset_metadata_invalid'     => null,
			'asset_too_large'            => 'package_archive_too_large',
			'digest_missing'             => null,
			'download_size_mismatch'     => 'package_archive_size_invalid',
			'download_digest_mismatch'   => null,
			'archive_unreadable'         => 'package_archive_unreadable',
			'archive_entry_limit'        => 'package_archive_entry_limit',
			'archive_size_limit'         => 'package_archive_too_large',
			'archive_path_unsafe'        => 'package_archive_path_unsafe',
			'archive_path_duplicate'     => 'package_archive_path_duplicate',
			'archive_root_not_single'    => 'package_archive_root_invalid',
			'no_package_header'          => 'package_header_missing',
			'header_plugin_ambiguous'    => 'package_header_ambiguous',
			'header_name_missing'        => 'package_header_missing',
			'header_version_missing'     => null,
			'header_update_uri_missing'  => 'package_update_uri_missing',
			'header_invalid'             => 'package_header_invalid',
			'header_duplicate'           => 'package_header_ambiguous',
			'header_version_mismatch'    => 'release_version_mismatch',
			'header_update_uri_mismatch' => 'package_update_uri_invalid',
			'header_requires_php_unmet'  => 'package_compatibility_invalid',
			'header_requires_wp_unmet'   => 'package_compatibility_invalid',
		);
		$rows = array();
		foreach ( $map as $reason => $code ) {
			$rows[ $reason ] = array( $reason, $code );
		}

		return $rows;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'reason_map' )]
	public function test_each_updater_reason_maps_to_its_core_code_or_stays_generic( string $reason, ?string $code ): void {
		$registrar = new RejectionRegistrarFixture(
			new RejectionSourceFixture(
				$this->rejected(
					array(
						'reason' => $reason,
						'detail' => null,
					)
				)
			)
		);

		$rejection = $this->rejection( $registrar );

		self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $rejection->reason );
		self::assertSame( $code, $rejection->detail );
		self::assertSame( array( true ), $registrar->flags );
	}

	public function test_free_text_detail_never_travels(): void {
		$registrar = new RejectionRegistrarFixture(
			new RejectionSourceFixture(
				$this->rejected(
					array(
						'reason' => 'header_version_mismatch',
						'detail' => 'found 1.2.0, expected 1.2.3',
					)
				)
			)
		);

		$rejection = $this->rejection( $registrar );

		self::assertSame( 'release_version_mismatch', $rejection->detail );
		self::assertStringNotContainsString( '1.2.0', $rejection->getMessage() );
	}

	/** @return array<string,array{0:mixed}> */
	public static function malformed_rejections(): array {
		return array(
			'unknown reason'      => array(
				array(
					'reason' => 'brand_new_reason',
					'detail' => null,
				),
			),
			'non string reason'   => array(
				array(
					'reason' => 7,
					'detail' => null,
				),
			),
			'uppercase reason'    => array(
				array(
					'reason' => 'Header_Invalid',
					'detail' => null,
				),
			),
			'oversized reason'    => array(
				array(
					'reason' => 'a' . str_repeat( 'b', 64 ),
					'detail' => null,
				),
			),
			'hostile reason'      => array(
				array(
					'reason' => "header_invalid\n<script>",
					'detail' => null,
				),
			),
			'extra key'           => array(
				array(
					'reason' => 'header_invalid',
					'detail' => null,
					'x'      => 1,
				),
			),
			'missing detail'      => array( array( 'reason' => 'header_invalid' ) ),
			'oversized detail'    => array(
				array(
					'reason' => 'header_invalid',
					'detail' => str_repeat( 'a', 121 ),
				),
			),
			'control byte detail' => array(
				array(
					'reason' => 'header_invalid',
					'detail' => "a\x00b",
				),
			),
			'non string detail'   => array(
				array(
					'reason' => 'header_invalid',
					'detail' => array(),
				),
			),
			'not an array'        => array( 'header_invalid' ),
			'null rejection'      => array( null ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'malformed_rejections' )]
	public function test_unknown_or_malformed_rejections_are_generic_incompatible( mixed $value ): void {
		$rejection = $this->rejection( new RejectionRegistrarFixture( new RejectionSourceFixture( $this->rejected( $value ) ) ) );

		self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $rejection->reason );
		self::assertNull( $rejection->detail );
	}

	public function test_legacy_five_key_incompatible_envelope_is_unchanged(): void {
		$legacy    = $this->envelope( false, 'package_incompatible', null );
		$rejection = $this->rejection( new RejectionRegistrarFixture( new RejectionSourceFixture( $legacy ) ) );

		self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $rejection->reason );
		self::assertNull( $rejection->detail );
	}

	public function test_rejection_is_ignored_for_other_failure_codes_and_success(): void {
		foreach ( array( 'invalid_release', 'operation_failed', 'release_changed' ) as $code ) {
			$result = $this->envelope( false, $code, null ) + array(
				'rejection' => array(
					'reason' => 'no_package_zip',
					'detail' => null,
				),
			);
			try {
				$this->provider( new RejectionRegistrarFixture( new RejectionSourceFixture( $result ) ) )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
				self::fail( 'A failure carrying a rejection under another code was accepted.' );
			} catch ( RepositoryReleaseInspectionRejected | \RuntimeException $failure ) {
				if ( $failure instanceof RepositoryReleaseInspectionRejected ) {
					self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $failure->reason );
					self::assertNull( $failure->detail );
				}
			}
		}
		// A non-null rejection beside a success is malformed and is not accepted.
		$success = $this->envelope( true, 'release_inspected', array(), 'complete' ) + array(
			'rejection' => array(
				'reason' => 'no_package_zip',
				'detail' => null,
			),
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub could not inspect the selected release.' );
		$this->provider( new RejectionRegistrarFixture( new RejectionSourceFixture( $success ) ) )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
	}

	public function test_invalid_release_is_unaffected_by_the_opt_in_key(): void {
		$result = $this->envelope( false, 'invalid_release', null ) + array( 'rejection' => null );
		try {
			$this->provider( new RejectionRegistrarFixture( new RejectionSourceFixture( $result ) ) )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
			self::fail( 'invalid_release was not rejected.' );
		} catch ( RepositoryReleaseInspectionRejected $failure ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $failure->reason );
			self::assertNull( $failure->detail );
		}
	}

	public function test_older_runtime_invalid_configuration_retries_without_the_opt_in(): void {
		$registrar = new RejectionRegistrarFixture(
			new RejectionSourceFixture( $this->envelope( false, 'invalid_configuration', null ) ),
			new RejectionSourceFixture( $this->envelope( false, 'package_incompatible', null ) )
		);

		$rejection = $this->rejection( $registrar );

		self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $rejection->reason );
		self::assertNull( $rejection->detail );
		self::assertSame( array( true, false ), $registrar->flags );
	}

	public function test_invalid_configuration_with_the_sixth_key_also_retries_and_other_failures_do_not(): void {
		$registrar = new RejectionRegistrarFixture(
			new RejectionSourceFixture( $this->rejected( null, 'invalid_configuration', false ) ),
			new RejectionSourceFixture( $this->envelope( false, 'package_incompatible', null ) )
		);
		$this->rejection( $registrar );
		self::assertSame( array( true, false ), $registrar->flags );

		$registrar = new RejectionRegistrarFixture( new RejectionSourceFixture( $this->envelope( false, 'operation_failed', null ) ) );
		try {
			$this->provider( $registrar )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
			self::fail( 'operation_failed was accepted.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 502, $failure->getCode() );
		}
		self::assertSame( array( true ), $registrar->flags );
	}

	public function test_registrar_without_the_opt_in_parameter_uses_its_plain_signature(): void {
		$registrar = new LegacyRejectionRegistrarFixture( new RejectionSourceFixture( $this->envelope( false, 'package_incompatible', null ) ) );

		try {
			GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), $registrar )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
			self::fail( 'The inspection was not rejected.' );
		} catch ( RepositoryReleaseInspectionRejected $rejection ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $rejection->reason );
			self::assertNull( $rejection->detail );
		}
		self::assertSame( 1, $registrar->calls );
	}

	private function rejection( RejectionRegistrarFixture $registrar ): RepositoryReleaseInspectionRejected {
		try {
			$this->provider( $registrar )->inspect_release( 'plugin', $this->repository(), '42', 'v1.2.3', 'stable' );
		} catch ( RepositoryReleaseInspectionRejected $rejection ) {
			return $rejection;
		}
		self::fail( 'The inspection was not rejected.' );
	}

	private function provider( RejectionRegistrarFixture $registrar ): GitHubProvider {
		return GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), $registrar );
	}

	private function repository(): RepositoryReference {
		return new RepositoryReference( 'owner/example', '123456789', false, null );
	}

	/** @return array<string,mixed> */
	private function rejected( mixed $rejection, string $code = 'package_incompatible', bool $ok = false ): array {
		return $this->envelope( $ok, $code, null ) + array( 'rejection' => $rejection );
	}

	/** @return array<string,mixed> */
	private function envelope( bool $ok, string $code, mixed $value, string $cleanup = 'not_applicable' ): array {
		return array(
			'ok'             => $ok,
			'code'           => $code,
			'value'          => $value,
			'retry_after'    => null,
			'cleanup_status' => $cleanup,
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Structural registrar double belongs beside the mapping contract it exercises.
final class RejectionRegistrarFixture {
	/** @var list<bool> */
	public array $flags = array();
	private ?RejectionSourceFixture $plain;

	public function __construct( private RejectionSourceFixture $opted_in, ?RejectionSourceFixture $plain = null ) {
		$this->plain = $plain;
	}

	public function with_operation_guard( callable $guard ): object {
		unset( $guard );
		return $this;
	}

	public function releases( mixed ...$arguments ): object {
		$flag          = true === ( $arguments['rejection_reasons'] ?? false );
		$this->flags[] = $flag;

		return $flag || null === $this->plain ? $this->opted_in : $this->plain;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Structural release-source double belongs beside the mapping contract it exercises.
final class RejectionSourceFixture {
	/** @param array<array-key,mixed> $inspect */
	public function __construct( private array $inspect ) {}

	/** @return array<array-key,mixed> */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Structural release-source double preserves the public inspection signature.
	public function inspect( string $id, string $tag ): array {
		return $this->inspect;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Structural registrar double belongs beside the mapping contract it exercises.
final class LegacyRejectionRegistrarFixture {
	public int $calls = 0;

	public function __construct( private RejectionSourceFixture $source ) {}

	public function with_operation_guard( callable $guard ): object {
		unset( $guard );
		return $this;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the protocol-7 registrar signature that predates the opt-in.
	public function releases( string $provider, string $package_type, string $repository, string $repository_id, string $channel = 'stable', ?callable $credentials = null, mixed $maximum_artifact_bytes = 52428800 ): object {
		++$this->calls;

		return $this->source;
	}
}
