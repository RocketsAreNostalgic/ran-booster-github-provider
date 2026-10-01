<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';

final class TemplatePackRepositoryClientTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit owns the lifecycle override name.
	protected function setUp(): void {
		\RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions_reset();
	}

	public function test_latest_incompatible_pack_refuses_without_falling_back(): void {
		$compatible_manifest   = TemplatePackApi3Fixture::manifest();
		$compatible_archive    = TemplatePackApi3Fixture::archive( $compatible_manifest );
		$incompatible_manifest = $this->manifest_identity( TemplatePackApi3Fixture::manifest( 4, '2.0.0' ), 42, 'v2.0.0' );
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

	public function test_exact_refetch_requires_every_pinned_release_and_asset_identity(): void {
		$archive              = TemplatePackApi3Fixture::archive();
		$release              = $this->release( 41, 'v1.2.3', $archive );
		$transport            = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi3Fixture::REPOSITORY,
					)
				),
				$this->response( 200, $release ),
				$this->response( 200, $release ),
				$this->tag_response(),
				$this->response( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) ),
				$this->binary_response( 200, $archive ),
			)
		);
		$identity             = TemplatePackApi3Fixture::identity( $archive );
		$identity['asset_id'] = $release['assets'][0]['id'];

		$result = $this->client( $transport )->exact( $identity );

		self::assertSame( 'ok', $result['code'] );
		self::assertSame( $identity, $result['pack']->identity() );

		$missing = $identity;
		unset( $missing['tag_target'] );
		$missing_transport = new TemplatePackScriptedTransport( array() );
		self::assertSame( 'template_pack_changed', $this->client( $missing_transport )->exact( $missing )['code'] );
		self::assertCount( 0, $missing_transport->requests );

		$changed             = $identity;
		$changed['asset_id'] = 999;
		$changed_transport   = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi3Fixture::REPOSITORY,
					)
				),
				$this->response( 200, $release ),
			)
		);
		self::assertSame(
			'template_pack_changed',
			$this->client( $changed_transport )->exact( $changed )['code']
		);
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

				return array_shift( $responses );
			}
		);

		self::assertSame( 'ok', $client->discover( 'operation-token' )['code'] );
		self::assertCount( 6, $requests );
		foreach ( $requests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\template_pack_repository_actions( 'requests-requests.before_redirect' ) );
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

	public function test_exact_refetch_keeps_remote_unavailability_distinct_from_identity_drift(): void {
		$archive              = TemplatePackApi3Fixture::archive();
		$release              = $this->release( 41, 'v1.2.3', $archive );
		$identity             = TemplatePackApi3Fixture::identity( $archive );
		$identity['asset_id'] = $release['assets'][0]['id'];
		$transport            = new TemplatePackScriptedTransport(
			array(
				$this->repository_response(),
				$this->response( 200, $release ),
				$this->response( 503, array() ),
			)
		);

		self::assertSame( 'template_pack_unavailable', $this->client( $transport )->exact( $identity )['code'] );
		self::assertCount( 3, $transport->requests );
	}

	private function client( TemplatePackScriptedTransport $transport ): TemplatePackRepositoryClient {
		return new TemplatePackRepositoryClient( $transport );
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

	/** @param array<string, mixed> $manifest @return array<string, mixed> */
	private function manifest_identity( array $manifest, int $release_id, string $tag ): array {
		$manifest['release']['tag'] = $tag;

		return $manifest;
	}

	/** @return array<string, mixed> */
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
