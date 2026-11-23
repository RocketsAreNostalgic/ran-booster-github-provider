<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

final class TemplatePackRepositoryClientTest extends TestCase {

	public function test_operation_guard_refuses_before_transport_and_discards_late_response(): void {
		foreach ( array( false, true ) as $start_available ) {
			$requests  = 0;
			$available = $start_available;
			$client    = new TemplatePackRepositoryClient(
				static function () use ( &$requests, &$available ): array {
					++$requests;
					$available = false;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{}',
					);
				},
				static function () use ( &$available ): void {
					if ( ! $available ) {
						throw new \RuntimeException( 'Selection changed.' );
					}
				}
			);
			self::assertSame( 'template_pack_unavailable', $client->discover()['code'] );
			self::assertSame( $start_available ? 1 : 0, $requests );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit owns the lifecycle override name.
	protected function setUp(): void {
		\RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions_reset();
	}

	public function test_latest_incompatible_pack_refuses_without_falling_back(): void {
		$compatible_manifest   = TemplatePackApi3Fixture::manifest();
		$compatible_archive    = TemplatePackApi3Fixture::archive( $compatible_manifest );
		$incompatible_manifest = $this->manifest_identity( TemplatePackApi3Fixture::manifest( 4, '2.0.0' ), 'v2.0.0' );
		$incompatible_archive  = TemplatePackApi3Fixture::archive( $incompatible_manifest );

		$compatible   = $this->release( 41, 'v1.2.3', $compatible_archive );
		$incompatible = $this->release( 42, 'v2.0.0', $incompatible_archive );
		$draft        = $this->release( 43, 'v9.0.0', $compatible_archive, draft: true );
		$prerelease   = $this->release( 44, 'v8.0.0', $compatible_archive, prerelease: true );
		$mutable      = $this->release( 45, 'v7.0.0', $compatible_archive, immutable: false );
		$transport    = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi3Fixture::REPOSITORY,
					)
				),
				$this->response( 200, array( $draft, $prerelease, $mutable, $compatible, $incompatible ) ),
				$this->response( 200, $incompatible ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $incompatible_archive ),
				$this->response( 200, $compatible ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $compatible_archive ),
			)
		);
		$client       = $this->client( $transport );

		$result = $client->discover();

		self::assertSame( 'template_pack_incompatible', $result['code'] );
		self::assertArrayNotHasKey( 'pack', $result );
		self::assertCount( 6, $transport->requests );
		self::assertStringEndsWith( '/releases/42', $transport->requests[2]['url'] );
		self::assertSame( 'application/octet-stream', $transport->requests[5]['args']['headers']['Accept'] );
		self::assertSame( 0, $transport->requests[5]['args']['redirection'] );
		self::assertTrue( $transport->requests[5]['args']['reject_unsafe_urls'] );
		self::assertArrayNotHasKey( 'Authorization', $transport->requests[5]['args']['headers'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'GET', $request['method'] );
			self::assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		}
	}

	public function test_discovery_ignores_unprefixed_and_oversized_tags_before_fetching_assets(): void {
		$archive = TemplatePackApi3Fixture::archive();
		foreach ( array( '1.2.3', 'v' . str_repeat( '1', 64 ) . '.2.3' ) as $tag ) {
			$transport = new TemplatePackScriptedTransport(
				array(
					$this->response(
						200,
						array(
							'id'        => 1322743261,
							'full_name' => TemplatePackApi3Fixture::REPOSITORY,
						)
					),
					$this->response( 200, array( $this->release( 41, $tag, $archive ) ) ),
				)
			);
			self::assertSame( 'template_pack_unavailable', $this->client( $transport )->discover()['code'] );
			self::assertCount( 2, $transport->requests );
		}
	}



	public function test_authenticated_requests_send_the_operation_token_for_json_and_asset_reads(): void {
		$archive   = TemplatePackApi3Fixture::archive();
		$release   = $this->release( 41, 'v1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			)
		);

		self::assertSame( 'ok', $this->client( $transport )->discover( 'operation-token' )['code'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
		self::assertSame( 'application/octet-stream', $transport->requests[5]['args']['headers']['Accept'] );
	}

	public function test_asset_redirect_scrubs_the_operation_token_without_changing_canonical_api_authentication(): void {
		$archive   = TemplatePackApi3Fixture::archive();
		$release   = $this->release( 41, 'v1.2.3', $archive );
		$responses = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://release-assets.githubusercontent.com/template-pack.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$requests  = array();
		$client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$requests, &$responses ): array {
				$requests[] = array(
					'method' => $method,
					'url'    => $url,
					'args'   => $args,
				);
				$response   = array_shift( $responses );
				self::assertNotNull( $response );
				return $response;
			}
		);

		self::assertSame( 'ok', $client->discover( 'operation-token' )['code'] );
		self::assertCount( 7, $requests );
		for ( $i = 0; $i < 6; ++$i ) {
			self::assertSame( 'Bearer operation-token', $requests[ $i ]['args']['headers']['Authorization'] );
			self::assertSame( 0, $requests[ $i ]['args']['redirection'] );
		}
		self::assertSame( 'https://release-assets.githubusercontent.com/template-pack.zip', $requests[6]['url'] );
		self::assertSame( 0, $requests[6]['args']['redirection'] );
		self::assertArrayNotHasKey( 'Authorization', $requests[6]['args']['headers'] );
		self::assertSame( 'RAN-Booster-Release-Deployments', $requests[6]['args']['headers']['User-Agent'] );
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' ) );
	}

	public function test_multi_hop_redirects_normalize_urls_resolve_same_host_relative_paths_and_assert_operation(): void {
		$archive      = TemplatePackApi3Fixture::archive();
		$release      = $this->release( 41, 'v1.2.3', $archive );
		$canary_token = 'ghp_redirect_trace_canary_1234567890';
		$assert_calls = 0;
		$responses    = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://GITHUB-RELEASES.GitHubUserContent.com:443/dir/hop-1.zip?sig=a%20b' ),
			$this->redirect_response( 302, '/resolved/hop-2.zip?token=1' ),
			$this->redirect_response( 307, 'https://objects.githubusercontent.com/hop-3.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$asset_hops   = array();
		$client       = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$responses, &$asset_hops, &$assert_calls ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$asset_hops[] = array(
						'url'           => $url,
						'headers'       => $args['headers'],
						'redirection'   => $args['redirection'],
						'assert_before' => $assert_calls,
					);
				}
				$response = array_shift( $responses );
				if ( null === $response ) {
					throw new \RuntimeException( 'Unexpected extra request.' );
				}
				return $response;
			},
			static function () use ( &$assert_calls ): void {
				++$assert_calls;
			}
		);

		self::assertSame( 'ok', $client->discover( $canary_token )['code'] );
		self::assertCount( 4, $asset_hops );
		self::assertSame( 'Bearer ' . $canary_token, $asset_hops[0]['headers']['Authorization'] ?? null );
		self::assertSame( 'https://github-releases.githubusercontent.com/dir/hop-1.zip?sig=a%20b', $asset_hops[1]['url'] );
		self::assertSame( 'https://github-releases.githubusercontent.com/resolved/hop-2.zip?token=1', $asset_hops[2]['url'] );
		self::assertSame( 'https://objects.githubusercontent.com/hop-3.zip', $asset_hops[3]['url'] );
		for ( $i = 1; $i < 4; ++$i ) {
			self::assertSame( 0, $asset_hops[ $i ]['redirection'] );
			self::assertArrayNotHasKey( 'Authorization', $asset_hops[ $i ]['headers'] );
			self::assertGreaterThan( $asset_hops[ $i - 1 ]['assert_before'], $asset_hops[ $i ]['assert_before'] );
		}

		// Anonymous requests follow valid same-host relative redirects preserving directory and empty-segment semantics.
		$anon_responses = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://release-assets.githubusercontent.com/packs/v1/start' ),
			$this->redirect_response( 302, '.' ),
			$this->redirect_response( 302, 'asset.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$anon_urls      = array();
		$anon_client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$anon_responses, &$anon_urls ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$anon_urls[] = $url;
				}
				$response = array_shift( $anon_responses );
				if ( null === $response ) {
					throw new \RuntimeException( 'Unexpected extra anonymous request.' );
				}
				return $response;
			}
		);
		self::assertSame( 'ok', $anon_client->discover( '' )['code'] );
		self::assertSame(
			array(
				'https://api.github.com/repos/RocketsAreNostalgic/ran-booster-release-bootstrap-templates/releases/assets/114',
				'https://release-assets.githubusercontent.com/packs/v1/start',
				'https://release-assets.githubusercontent.com/packs/v1/',
				'https://release-assets.githubusercontent.com/packs/v1/asset.zip',
			),
			$anon_urls
		);

		// Empty path segments in Location must be preserved verbatim rather than collapsed.
		$double_slash_responses = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://release-assets.githubusercontent.com/packs//bundle.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$double_slash_urls      = array();
		$double_slash_client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$double_slash_responses, &$double_slash_urls ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$double_slash_urls[] = $url;
				}
				$response = array_shift( $double_slash_responses );
				if ( null === $response ) {
					throw new \RuntimeException( 'Unexpected extra double-slash request.' );
				}
				return $response;
			}
		);
		self::assertSame( 'ok', $double_slash_client->discover( '' )['code'] );
		self::assertSame(
			'https://release-assets.githubusercontent.com/packs//bundle.zip',
			$double_slash_urls[1] ?? null
		);

		// Aborting assert_operation on hop 2 stops redirects and keeps any canary token out of traces.
		$responses_abort = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://github-releases.githubusercontent.com/hop-1.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$abort_state     = new class() {
			public int $asset_requests = 0;
			public bool $abort_next    = false;
			public string $trace       = '';
		};
		$abort_client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$responses_abort, $abort_state ): array {
				unset( $method, $url );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					++$abort_state->asset_requests;
					if ( 1 === $abort_state->asset_requests ) {
						$abort_state->abort_next = true;
					}
				}
				$response = array_shift( $responses_abort );
				if ( null === $response ) {
					throw new \RuntimeException( 'Unexpected extra abort request.' );
				}
				return $response;
			},
			static function () use ( $abort_state ): void {
				if ( $abort_state->abort_next ) {
					$exception          = new \RuntimeException( 'operation cancelled on hop 2' );
					$abort_state->trace = $exception->getTraceAsString();
					throw $exception;
				}
			}
		);

		self::assertSame( 'template_pack_unavailable', $abort_client->discover( $canary_token )['code'] );
		self::assertSame( 1, $abort_state->asset_requests );
		self::assertNotSame( '', $abort_state->trace );
		self::assertStringNotContainsString( $canary_token, $abort_state->trace );
	}

	public function test_redirect_handler_rejects_disallowed_schemes_hosts_and_userinfo_on_any_hop(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$release = $this->release( 41, 'v1.2.3', $archive );
		$bad     = array(
			'http downgrade'   => 'http://release-assets.githubusercontent.com/template-pack.zip',
			'third-party host' => 'https://downloads.example.test/template-pack.zip',
			'scheme-relative'  => '//downloads.example.test/template-pack.zip',
			'userinfo present' => 'https://user:pass@objects.githubusercontent.com/template-pack.zip',
			'non-443 port'     => 'https://objects.githubusercontent.com:8443/template-pack.zip',
			'fragment present' => 'https://objects.githubusercontent.com/template-pack.zip#frag',
			'control bytes'    => "https://objects.githubusercontent.com/template-pack.zip\r\nX-Injected: 1",
		);

		foreach ( $bad as $label => $bad_location ) {
			foreach ( array( '', 'operation-token' ) as $token ) {
				foreach ( array( 1, 2 ) as $bad_hop ) {
					$responses = array(
						$this->repository_response(),
						$this->response( 200, array( $release ) ),
						$this->response( 200, $release ),
						$this->tag_response(),
						$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
					);
					if ( 2 === $bad_hop ) {
						// Use mixed-case host and explicit :443 on hop 1 to prove hop 2 still stays guarded.
						$responses[] = $this->redirect_response( 302, 'https://GITHUB-RELEASES.GitHubUserContent.com:443/hop-1.zip?x=1' );
					}
					$responses[]  = $this->redirect_response( 302, $bad_location );
					$responses[]  = $this->binary_response( 200, $archive );
					$observed_log = array();
					$client       = new TemplatePackRepositoryClient(
						static function ( string $method, string $url, array $args ) use ( &$responses, &$observed_log ): array {
							unset( $method );
							if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
								$observed_log[] = array(
									'url'         => $url,
									'redirection' => $args['redirection'],
									'has_auth'    => array_key_exists( 'Authorization', $args['headers'] ),
								);
							}
							$response = array_shift( $responses );
							if ( null === $response ) {
								throw new \RuntimeException( 'Unexpected extra request.' );
							}
							return $response;
						}
					);

					$result = $client->discover( $token );
					self::assertSame(
						'template_pack_unavailable',
						$result['code'],
						sprintf( '%s (hop %d, auth=%s) must fail closed.', $label, $bad_hop, '' === $token ? 'no' : 'yes' )
					);
					self::assertCount(
						$bad_hop,
						$observed_log,
						sprintf( '%s (hop %d, auth=%s) must never follow the rejected Location.', $label, $bad_hop, '' === $token ? 'no' : 'yes' )
					);
					foreach ( $observed_log as $index => $entry ) {
						self::assertSame( 0, $entry['redirection'] );
						if ( $index > 0 ) {
							self::assertFalse( $entry['has_auth'] );
						}
					}
				}
			}
		}

		// A fourth redirect beyond the 3-hop cap must also fail closed without following hop 4.
		$too_many_responses = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->redirect_response( 302, 'https://github-releases.githubusercontent.com/hop-1.zip' ),
			$this->redirect_response( 302, 'https://objects.githubusercontent.com/hop-2.zip' ),
			$this->redirect_response( 302, 'https://release-assets.githubusercontent.com/hop-3.zip' ),
			$this->redirect_response( 302, 'https://release-assets.githubusercontent.com/hop-4.zip' ),
			$this->binary_response( 200, $archive ),
		);
		$too_many_urls      = array();
		$too_many_client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$too_many_responses, &$too_many_urls ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$too_many_urls[] = $url;
				}
				$response = array_shift( $too_many_responses );
				if ( null === $response ) {
					throw new \RuntimeException( 'Unexpected extra request.' );
				}
				return $response;
			}
		);
		self::assertSame( 'template_pack_unavailable', $too_many_client->discover( 'operation-token' )['code'] );
		self::assertCount( 4, $too_many_urls );
		self::assertNotContains( 'https://release-assets.githubusercontent.com/hop-4.zip', $too_many_urls );
	}

	public function test_repository_and_asset_digest_mismatches_fail_closed(): void {
		$wrong_repository = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => '123',
						'full_name' => TemplatePackApi3Fixture::REPOSITORY,
					)
				),
			)
		);
		self::assertSame( 'template_pack_changed', $this->client( $wrong_repository )->discover()['code'] );
		self::assertCount( 1, $wrong_repository->requests );

		$archive                        = TemplatePackApi3Fixture::archive();
		$release                        = $this->release( 41, 'v1.2.3', $archive );
		$release['assets'][0]['digest'] = 'sha256:' . str_repeat( '0', 64 );
		$transport                      = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi3Fixture::REPOSITORY,
					)
				),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			)
		);
		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
	}

	public function test_malformed_higher_stable_release_and_duplicate_version_refuse_fallback(): void {
		$archive             = TemplatePackApi3Fixture::archive();
		$older               = $this->release( 41, 'v1.2.3', $archive );
		$malformed           = $this->release( 42, 'v2.0.0', $archive );
		$malformed['assets'] = array();
		$transport           = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, array( $older, $malformed ) ),
			)
		);

		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
		self::assertCount( 2, $transport->requests );

		$duplicate = $this->release( 43, 'v1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, array( $older, $duplicate ) ),
			)
		);

		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
		self::assertCount( 2, $transport->requests );
	}

	public function test_http_401_is_classified_as_unauthorised_instead_of_unavailable(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$release = $this->release( 41, 'v1.2.3', $archive );
		foreach ( array( 0, 1, 2, 3, 4, 5 ) as $boundary ) {
			$responses              = array(
				$this->repository_response(),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			);
			$responses[ $boundary ] = 5 === $boundary ? $this->binary_response( 401, '' ) : $this->response( 401, array() );
			$transport              = new TemplatePackScriptedTransport( $responses );
			$result                 = $this->client( $transport )->discover( 'expired-token' );
			self::assertSame( 'unauthorised', $result['code'], (string) $boundary );
			self::assertArrayNotHasKey( 'pack', $result, (string) $boundary );
			self::assertCount( $boundary + 1, $transport->requests, (string) $boundary );
		}
	}

	public function test_ineligible_older_historical_release_does_not_abort_newer_valid_release_discovery(): void {
		$archive                           = TemplatePackApi3Fixture::archive();
		$current                           = $this->release( 41, 'v1.2.3', $archive );
		$no_assets                         = $this->release( 31, 'v0.1.0', $archive );
		$no_assets['assets']               = array();
		$extra_asset                       = $this->release( 32, 'v0.2.0', $archive );
		$extra_asset['assets'][]           = array(
			'id'           => 999,
			'name'         => 'checksums.txt',
			'size'         => 64,
			'state'        => 'uploaded',
			'content_type' => 'text/plain',
		);
		$branch_target                     = $this->release( 33, 'v0.3.0', $archive );
		$branch_target['target_commitish'] = 'main';
		$missing_digest                    = $this->release( 34, 'v0.4.0', $archive );
		unset( $missing_digest['assets'][0]['digest'] );

		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, array( $no_assets, $extra_asset, $branch_target, $missing_digest, $current ) ),
				$this->response( 200, $current ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			)
		);

		$result = $this->client( $transport )->discover();
		self::assertSame( 'ok', $result['code'] );
		self::assertSame( '1.2.3', $result['pack']->pack_version() );
		self::assertCount( 6, $transport->requests );
	}

	public function test_historical_api1_pack_is_refused_without_fallback_or_adapter(): void {
		$manifest  = TemplatePackApi3Fixture::manifest( 1 );
		$archive   = TemplatePackApi3Fixture::archive( $manifest );
		$release   = $this->release( 41, 'v1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			)
		);

		self::assertSame( 'template_pack_incompatible', $this->client( $transport )->discover()['code'] );
		self::assertCount( 6, $transport->requests );
	}



	private function client( TemplatePackScriptedTransport $transport ): TemplatePackRepositoryClient {
		return new TemplatePackRepositoryClient( $transport );
	}

	public function test_discovery_requires_fresh_release_source_and_asset_checks(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$release = $this->release( 41, 'v1.2.3', $archive );
		foreach ( array(
			array( 2, $this->response( 404, array() ), 'template_pack_changed', 3 ),
			array( 2, $this->response( 403, array() ), 'template_pack_unavailable', 3 ),
			array( 2, $this->response( 200, array_replace( $release, array( 'immutable' => false ) ) ), 'template_pack_changed', 3 ),
			array( 3, $this->response( 403, array() ), 'template_pack_unavailable', 4 ),
			array(
				3,
				$this->response(
					200,
					array(
						'object' => array(
							'type' => 'commit',
							'sha'  => str_repeat( '9', 40 ),
						),
					)
				),
				'template_pack_invalid',
				5,
			),
			array( 4, $this->response( 403, array() ), 'template_pack_unavailable', 5 ),
			array( 4, $this->response( 200, array( 'sha' => str_repeat( '9', 40 ) ) ), 'template_pack_invalid', 5 ),
			array( 5, $this->binary_response( 403, '' ), 'template_pack_unavailable', 6 ),
			array( 5, $this->binary_response( 200, str_repeat( 'x', strlen( $archive ) ) ), 'template_pack_invalid', 6 ),
		) as [ $boundary, $failure, $expected, $requests ] ) {
			$responses              = array( $this->repository_response(), $this->response( 200, array( $release ) ), $this->response( 200, $release ), $this->tag_response(), $this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ), $this->binary_response( 200, $archive ) );
			$responses[ $boundary ] = $failure;
			$transport              = new TemplatePackScriptedTransport( $responses );
			$result                 = $this->client( $transport )->discover();
			self::assertSame( $expected, $result['code'] );
			self::assertArrayNotHasKey( 'pack', $result );
			self::assertCount( $requests, $transport->requests );
		}
	}

	/** @return array<string, mixed> */
	private function release( int $id, string $tag, string $archive, bool $draft = false, bool $prerelease = false, bool $immutable = true ): array {
		return array(
			'id'               => $id,
			'tag_name'         => $tag,
			'target_commitish' => TemplatePackApi3Fixture::COMMIT,
			'draft'            => $draft,
			'prerelease'       => $prerelease,
			'immutable'        => $immutable,
			'assets'           => array(
				array(
					'id'           => TemplatePackApi3Fixture::ASSET_ID + $id,
					'name'         => TemplatePackApi3Fixture::ASSET_NAME,
					'size'         => strlen( $archive ),
					'state'        => 'uploaded',
					'content_type' => 'application/zip',
					'digest'       => 'sha256:' . hash( 'sha256', $archive ),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function tag_response( string $sha = TemplatePackApi3Fixture::COMMIT, string $type = 'commit' ): array {
		return $this->response(
			200,
			array(
				'object' => array(
					'type' => $type,
					'sha'  => $sha,
				),
			)
		);
	}

	/** @return array<string, mixed> */
	private function repository_response(): array {
		return $this->response(
			200,
			array(
				'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
				'full_name' => TemplatePackApi3Fixture::REPOSITORY,
			)
		);
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function manifest_identity( array $manifest, string $tag ): array {
		$manifest['release']['tag'] = $tag;

		return $manifest;
	}

	/**
	 * @return array<string, mixed>
	 * @param array<array-key,mixed> $body
	 */
	private function response( int $status, array $body ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport requires throwing deterministic JSON encoding.
		return $this->binary_response( $status, (string) json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	}

	/** @return array<string, mixed> */
	private function binary_response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	/** @return array<string, mixed> */
	private function redirect_response( int $status, string $location ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'location' => $location ),
			'body'     => '',
		);
	}
}
