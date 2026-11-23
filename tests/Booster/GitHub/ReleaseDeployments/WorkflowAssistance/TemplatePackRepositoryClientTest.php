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
		self::assertSame( 3, $transport->requests[5]['args']['redirection'] );
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
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$actions = \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' );
					self::assertCount( 1, $actions );
					$location = 'https://release-assets.githubusercontent.com/template-pack.zip';
					$headers  = $args['headers'];
					call_user_func_array(
						$actions[0]['callback'],
						array( &$location, &$headers, null, array(), (object) array( 'url' => $url ) )
					);
					self::assertArrayNotHasKey( 'Authorization', $headers );
					self::assertSame( 'RAN-Booster-Release-Deployments', $headers['User-Agent'] );
				}

				$response = array_shift( $responses );
				self::assertNotNull( $response );
				return $response;
			}
		);

		self::assertSame( 'ok', $client->discover( 'operation-token' )['code'] );
		self::assertCount( 6, $requests );
		foreach ( $requests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' ) );
	}

	public function test_multi_hop_redirects_assert_operation_scrub_headers_and_redact_traces(): void {
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
			$this->binary_response( 200, $archive ),
		);
		$hop_urls     = array(
			'https://github-releases.githubusercontent.com/hop-1.zip',
			'https://objects.githubusercontent.com/hop-2.zip',
			'https://release-assets.githubusercontent.com/hop-3.zip',
		);
		$hop_count    = 0;
		$client       = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$responses, $hop_urls, &$hop_count, &$assert_calls ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$actions = \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' );
					self::assertCount( 1, $actions );
					$callback = $actions[0]['callback'];
					self::assertInstanceOf( \Closure::class, $callback );
					$reflection = new \ReflectionFunction( $callback );
					self::assertCount( 1, $reflection->getParameters()[1]->getAttributes( \SensitiveParameter::class ) );

					$current_url = $url;
					foreach ( $hop_urls as $next_url ) {
						$before_asserts = $assert_calls;
						$location       = $next_url;
						$headers        = $args['headers'];
						call_user_func_array(
							$callback,
							array( &$location, &$headers, null, array(), (object) array( 'url' => $current_url ) )
						);
						self::assertSame( $before_asserts + 1, $assert_calls, 'assert_operation must execute on every redirect hop.' );
						self::assertArrayNotHasKey( 'Authorization', $headers, 'Authorization header must be stripped on every redirect hop.' );
						$current_url = $next_url;
						++$hop_count;
					}
				}

				$response = array_shift( $responses );
				self::assertNotNull( $response );
				return $response;
			},
			static function () use ( &$assert_calls ): void {
				++$assert_calls;
			}
		);

		self::assertSame( 'ok', $client->discover( $canary_token )['code'] );
		self::assertSame( 3, $hop_count );

		// Throwing from assert_operation on hop 2 must abort the redirect and redact $headers in the stack trace.
		$responses_abort = array(
			$this->repository_response(),
			$this->response( 200, array( $release ) ),
			$this->response( 200, $release ),
			$this->tag_response(),
			$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
			$this->binary_response( 200, $archive ),
		);
		$abort_state     = new class() {
			public bool $abort_on_hop = false;
			public string $trace      = '';
		};
		$abort_client    = new TemplatePackRepositoryClient(
			static function ( string $method, string $url, array $args ) use ( &$responses_abort, $abort_state ): array {
				unset( $method );
				if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
					$actions  = \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' );
					$callback = $actions[0]['callback'];
					$hop1     = 'https://github-releases.githubusercontent.com/hop-1.zip';
					$headers1 = $args['headers'];
					call_user_func_array( $callback, array( &$hop1, &$headers1, null, array(), (object) array( 'url' => $url ) ) );

					$abort_state->abort_on_hop = true;
					$hop2                      = 'https://objects.githubusercontent.com/hop-2.zip';
					$headers2                  = $args['headers'];
					try {
						call_user_func_array( $callback, array( &$hop2, &$headers2, null, array(), (object) array( 'url' => $hop1 ) ) );
					} catch ( \RuntimeException $exception ) {
						$abort_state->trace = $exception->getTraceAsString();
						throw $exception;
					}
				}
				$response = array_shift( $responses_abort );
				self::assertNotNull( $response );
				return $response;
			},
			static function () use ( $abort_state ): void {
				if ( $abort_state->abort_on_hop ) {
					throw new \RuntimeException( 'operation cancelled on hop 2' );
				}
			}
		);

		self::assertSame( 'template_pack_unavailable', $abort_client->discover( $canary_token )['code'] );
		self::assertNotSame( '', $abort_state->trace );
		self::assertStringNotContainsString( $canary_token, $abort_state->trace );
	}

	public function test_redirect_handler_rejects_disallowed_schemes_hosts_and_userinfo_on_any_hop(): void {
		$archive = TemplatePackApi3Fixture::archive();
		$release = $this->release( 41, 'v1.2.3', $archive );
		$bad     = array(
			'http downgrade'   => 'http://release-assets.githubusercontent.com/template-pack.zip',
			'third-party host' => 'https://downloads.example.test/template-pack.zip',
			'userinfo present' => 'https://user:pass@objects.githubusercontent.com/template-pack.zip',
			'non-443 port'     => 'https://objects.githubusercontent.com:8443/template-pack.zip',
			'relative path'    => '/template-pack.zip',
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
						$this->binary_response( 200, $archive ),
					);
					$client    = new TemplatePackRepositoryClient(
						static function ( string $method, string $url, array $args ) use ( &$responses, $bad_location, $bad_hop ): array {
							unset( $method );
							if ( 'application/octet-stream' === ( $args['headers']['Accept'] ?? null ) ) {
								$actions = \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' );
								self::assertCount( 1, $actions );
								$callback = $actions[0]['callback'];
								$current  = $url;
								if ( 2 === $bad_hop ) {
									$hop1     = 'https://github-releases.githubusercontent.com/hop-1.zip';
									$headers1 = $args['headers'];
									call_user_func_array( $callback, array( &$hop1, &$headers1, null, array(), (object) array( 'url' => $current ) ) );
									$current = $hop1;
								}
								$location = $bad_location;
								$headers  = $args['headers'];
								call_user_func_array( $callback, array( &$location, &$headers, null, array(), (object) array( 'url' => $current ) ) );
							}
							$response = array_shift( $responses );
							self::assertNotNull( $response );
							return $response;
						}
					);

					self::assertSame(
						'template_pack_unavailable',
						$client->discover( $token )['code'],
						sprintf( '%s (hop %d, auth=%s) must fail closed.', $label, $bad_hop, '' === $token ? 'no' : 'yes' )
					);
				}
			}
		}
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
}
