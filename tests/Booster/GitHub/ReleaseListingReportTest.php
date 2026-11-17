<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;

final class ReleaseListingReportTest extends TestCase {
	/** @return array<string, array{array<string, mixed>, ?bool, ?array<string, mixed>}> */
	public static function listing_reports(): array {
		$report     = array(
			'scanned_count' => 2,
			'dropped_count' => 1,
			'drops'         => array(
				array(
					'release_identity' => '42',
					'tag'              => '1.2.3+Build',
					'reason'           => 'release_draft',
				),
			),
			'truncated'     => false,
		);
		$projection = array(
			'scanned_count' => 2,
			'dropped_count' => 1,
			'drops'         => array(
				array(
					'reason' => 'release_draft',
					'tag'    => '1.2.3+Build',
				),
			),
			'truncated'     => false,
		);
		return array(
			'observed incomplete search'   => array(
				array(
					'search_exhausted' => true,
					'diagnostics'      => $report,
				),
				true,
				$projection,
			),
			'observed complete search'     => array(
				array(
					'search_exhausted' => false,
					'diagnostics'      => $report,
				),
				false,
				$projection,
			),
			'no observations'              => array(
				array(
					'search_exhausted' => null,
					'diagnostics'      => null,
				),
				null,
				null,
			),
			'no fresh report'              => array(
				array(
					'search_exhausted' => false,
					'diagnostics'      => null,
				),
				false,
				null,
			),
			'legacy updater'               => array( array(), null, null ),
			'flag only'                    => array( array( 'search_exhausted' => true ), null, null ),
			'report only'                  => array( array( 'diagnostics' => $report ), null, null ),
			'Core normalizes invalid flag' => array(
				array(
					'search_exhausted' => 1,
					'diagnostics'      => $report,
				),
				null,
				$projection,
			),
			'Core drops malformed report'  => array(
				array(
					'search_exhausted' => true,
					'diagnostics'      => array( 'invalid' => true ),
				),
				true,
				null,
			),
		);
	}

	/**
	 * @param array<string, mixed> $observations
	 * @param array<string, mixed>|null $expected_report
	 */
	#[DataProvider( 'listing_reports' )]
	public function test_optional_observations_cross_only_when_both_sides_support_them( array $observations, ?bool $expected_search, ?array $expected_report ): void {
		NeutralReleaseUpdaterFixtures::reset();
		$registrar = new class( $observations ) {
			/** @param array<string, mixed> $observations */
			public function __construct( private array $observations ) {}

			public function releases( mixed ...$arguments ): object {
				unset( $arguments );
				return new class( $this->observations ) {
					/** @param array<string, mixed> $observations */
					public function __construct( private array $observations ) {}

					/** @return array<string, mixed> */
					public function list(): array {
						return array(
							'ok'             => true,
							'code'           => 'releases_listed',
							'value'          => array(
								'candidates'   => array(
									array(
										'release_identity' => '50',
										'tag'              => 'v1.2.3',
										'version'          => '1.2.3',
										'prerelease'       => false,
										'published_at'     => '2026-11-17T12:00:00Z',
										'expected_asset_names' => array( 'example.zip' ),
										'details_url'      => 'https://github.com/owner/example/releases/tag/v1.2.3',
									),
								),
								'not_modified' => false,
							) + $this->observations,
							'retry_after'    => null,
							'cleanup_status' => 'not_applicable',
						);
					}
				};
			}
		};
		$provider  = GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), $registrar );
		$result    = $provider->list_release_candidates( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), 'stable' );
		self::assertCount( 1, $result->candidates );
		self::assertSame( '50', $result->candidates[0]->provider_release_id );
		if ( property_exists( RepositoryReleaseCandidateList::class, 'search_exhausted' ) && property_exists( RepositoryReleaseCandidateList::class, 'diagnostics' ) ) {
			$fields = get_object_vars( $result );
			self::assertSame( $expected_search, $fields['search_exhausted'] );
			self::assertSame( $expected_report, $fields['diagnostics'] );
		} else {
			self::assertSame( array( 'candidates' ), array_keys( get_object_vars( $result ) ) );
		}
		self::assertSame( array(), NeutralReleaseUpdaterFixtures::requests() );
	}
}
