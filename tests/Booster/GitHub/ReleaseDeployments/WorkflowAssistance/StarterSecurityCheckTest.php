<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\StarterOrigin;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\StarterSecurityCheck;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact hostile JSON fixture bytes.
final class StarterSecurityCheckTest extends TestCase {
	private function origin(): string {
		$bytes = TemplatePackApi3Fixture::archive();
		return StarterOrigin::encode( TemplatePack::from_archive( $bytes, TemplatePackApi3Fixture::identity( $bytes ) )['pack'], 'source-ready-wordpress-plugin/3' );
	}
	private function entry( bool $shared = false ): array {
		return array(
			'ghsa_id'    => $shared ? 'GHSA-3333-4444-5555' : 'GHSA-2222-3333-4444',
			'repository' => $shared ? StarterOrigin::SHARED_REPOSITORY : StarterOrigin::PACK_REPOSITORY,
			'affected'   => array(
				'pack_versions'            => $shared ? array() : array( '1.2.3' ),
				'shared_profile_b_commits' => $shared ? array( StarterOrigin::SHARED_COMMIT ) : array(),
			),
			'fixed'      => array(
				'pack_version'            => $shared ? null : '1.2.4',
				'shared_profile_b_commit' => $shared ? str_repeat( 'a', 40 ) : null,
			),
		);
	}
	private function index( array $entries ): array {
		return array(
			'schema'         => 'ran-release-starter-advisories',
			'schema_version' => 1,
			'advisories'     => $entries,
		);
	}
	private function run_check( array|string $index, array $advisory_override = array(), int $status = 200, string $token = '' ): array {
		$requests = array();
		$checker  = new StarterSecurityCheck(
			static function ( string $url, array $args ) use ( $index, $advisory_override, $status, $token, &$requests ): array {
				$requests[] = $url;
				self::assertSame( 0, $args['redirection'] );
				self::assertSame( 65537, $args['limit_response_size'] );
				if ( '' === $token ) {
					self::assertArrayNotHasKey( 'Authorization', $args['headers'] );
				} else {
					self::assertSame( 'Bearer ' . $token, $args['headers']['Authorization'] );
				}
				if ( str_contains( $url, '/contents/' ) ) {
					$data = $index; } elseif ( str_contains( $url, '/security-advisories/' ) ) {
								$id         = basename( $url );
								$repository = str_contains( $url, '/.github/' ) ? StarterOrigin::SHARED_REPOSITORY : StarterOrigin::PACK_REPOSITORY;
								$data       = array_replace(
									array(
										'ghsa_id'      => $id,
										'url'          => $url,
										'html_url'     => 'https://github.com/' . $repository . '/security/advisories/' . $id,
										'state'        => 'published',
										'published_at' => '2026-09-28T00:00:00Z',
										'withdrawn_at' => null,
									),
									$advisory_override
								);
					} else {
						$data = array(
							'id'             => 1322743261,
							'full_name'      => StarterOrigin::PACK_REPOSITORY,
							'default_branch' => 'main',
						);
					}
					return array(
						'response' => array( 'code' => $status ),
						'body'     => is_string( $data ) ? $data : json_encode( $data ),
					);
			}
		);
		return $checker->check( $this->origin(), $token );
	}
	public function test_maximum_index_requires_authentication_for_every_published_advisory_check(): void {
		$alphabet = '23456789cfghjmpqrvwx';
		$entries  = array();
		for ( $i = 0; $i < 64; ++$i ) {
			$entry            = $this->entry();
			$entry['ghsa_id'] = 'GHSA-2222-3333-22' . $alphabet[ intdiv( $i, strlen( $alphabet ) ) ] . $alphabet[ $i % strlen( $alphabet ) ];
			$entries[]        = $entry;
		}
		self::assertSame( 'unknown', $this->run_check( $this->index( $entries ) )['status'] );
		$verified = $this->run_check( $this->index( $entries ), array(), 200, 'fixture-token' );
		self::assertSame( 'matching_advisory', $verified['status'] );
		self::assertCount( 64, $verified['matches'] );
	}
	public function test_exact_pack_and_shared_matches_display_only_explicit_manual_targets(): void {
		$result = $this->run_check( $this->index( array( $this->entry(), $this->entry( true ) ) ) );
		self::assertSame( 'matching_advisory', $result['status'] );
		self::assertCount( 2, $result['matches'] );
		self::assertSame( '1.2.4', $result['matches'][0]['fixed']['pack_version'] );
		self::assertSame( str_repeat( 'a', 40 ), $result['matches'][1]['fixed']['shared_profile_b_commit'] );
	}
	public function test_independent_published_advisories_may_overlap_the_same_revision(): void {
		$pack_a                                       = $this->entry();
		$pack_b                                       = $pack_a;
		$pack_b['ghsa_id']                            = 'GHSA-4444-5555-6666';
		$pack_b['fixed']['pack_version']              = '1.2.5';
		$shared_a                                     = $this->entry( true );
		$shared_b                                     = $shared_a;
		$shared_b['ghsa_id']                          = 'GHSA-5555-6666-7777';
		$shared_b['fixed']['shared_profile_b_commit'] = str_repeat( 'b', 40 );
		$result                                       = $this->run_check( $this->index( array( $pack_a, $pack_b, $shared_a, $shared_b ) ) );
		self::assertSame( 'matching_advisory', $result['status'] );
		self::assertCount( 4, $result['matches'] );
		self::assertSame( array( '1.2.4', '1.2.5', null, null ), array_column( array_column( $result['matches'], 'fixed' ), 'pack_version' ) );
		self::assertSame( str_repeat( 'b', 40 ), $result['matches'][3]['fixed']['shared_profile_b_commit'] );
	}
	public function test_no_match_is_not_a_safety_certificate(): void {
		$entry                              = $this->entry();
		$entry['affected']['pack_versions'] = array( '1.0.0' );
		self::assertSame( 'no_matching_known_advisory', $this->run_check( $this->index( array( $entry ) ) )['status'] );
		self::assertSame( 'no_matching_known_advisory', $this->run_check( $this->index( array() ) )['status'] );
	}
	public function test_every_indexed_advisory_must_be_published_and_belong_to_its_canonical_repository(): void {
		foreach ( array( array( 'state' => 'draft' ), array( 'published_at' => null ), array( 'withdrawn_at' => '2026-09-28T00:00:00Z' ), array( 'ghsa_id' => 'GHSA-9999-9999-9999' ), array( 'url' => 'https://api.github.com/repos/attacker/fork/security-advisories/GHSA-2222-3333-4444' ), array( 'html_url' => 'https://example.org/advisory' ) ) as $override ) {
			self::assertSame( 'unknown', $this->run_check( $this->index( array( $this->entry() ) ), $override )['status'] );
		}
		foreach ( array( 403, 404, 429, 500 ) as $status ) {
			self::assertSame( 'unknown', $this->run_check( $this->index( array() ), array(), $status )['status'] );
		}
	}
	public function test_malformed_duplicate_contradictory_and_oversized_index_is_unknown(): void {
		$entry = $this->entry();
		$cases = array( '{"schema":"ran-release-starter-advisories","schema_version":1,"advisories":{}}', '{}', '{"schema":1,"schema":2}', '{"schema":1,"schem\\u0061":2}', str_repeat( ' ', 65537 ), $this->index( array_fill( 0, 65, $entry ) ), $this->index( array( $entry, $entry ) ), $this->index( array( $entry ) ) + array( 'authority' => 'write' ) );
		foreach ( array( 'latest', '01.2.3', '1.2.3-beta.1', '*', str_repeat( '1', 64 ) ) as $value ) {
			$bad                              = $entry;
			$bad['affected']['pack_versions'] = array( $value );
			$cases[]                          = $this->index( array( $bad ) );
		}
		$bad                                = $entry;
		$bad['affected']['pack_versions'][] = '1.2.3';
		$cases[]                            = $this->index( array( $bad ) );
		foreach ( $cases as $case ) {
			self::assertSame( 'unknown', $this->run_check( $case )['status'] );
		}
	}
	public function test_invalid_origin_makes_no_network_request_and_cannot_grant_capabilities(): void {
		$calls   = 0;
		$checker = new StarterSecurityCheck(
			static function () use ( &$calls ): array {
				++$calls;
				return array();
			}
		);
		$origin  = json_decode( $this->origin(), true );
		$cases   = array( '', '{}', str_repeat( ' ', 8193 ), json_encode( $origin + array( 'writes' => true ) ) );
		foreach ( array(
			'repository'    => 'attacker/fork',
			'repository_id' => '1',
			'version'       => '01.2.3',
			'profile'       => 'source-ready-wordpress-plugin/2',
			'commit'        => 'main',
		) as $field => $value ) {
			$bad                   = $origin;
			$bad['pack'][ $field ] = $value;
			$cases[]               = json_encode( $bad );
		}
		foreach ( $cases as $case ) {
			self::assertSame( 'unknown', $checker->check( $case )['status'] );
		}
		self::assertSame( 0, $calls );
	}
}
