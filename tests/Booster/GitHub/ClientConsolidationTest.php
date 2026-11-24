<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\CoreContractBridge;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy;
use RAN\BoosterGitHubProvider\V1\GitHubHttpClient;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\GitHubRepositoryIdentity;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\StarterOrigin;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\RepositoryWebhookClient;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;
use RuntimeException;

final class ClientConsolidationTest extends TestCase {

	/** @return array<string, array{int, array<string, mixed>, string, bool}> */
	public static function status_classifications(): array {
		return array(
			'200 ok'                        => array( 200, array(), 'ok', false ),
			'201 created'                   => array( 201, array(), 'ok', false ),
			'204 no content'                => array( 204, array(), 'ok', false ),
			'302 redirect blocked'          => array( 302, array(), 'redirect_blocked', false ),
			'307 redirect blocked'          => array( 307, array(), 'redirect_blocked', false ),
			'400 bad request'               => array( 400, array(), 'bad_request', false ),
			'401 credential rejected'       => array( 401, array(), 'credential_rejected', false ),
			'403 permission denied'         => array( 403, array(), 'permission_denied', false ),
			'403 primary rate limit'        => array( 403, array( 'headers' => array( 'X-RateLimit-Remaining' => '0' ) ), 'rate_limited', true ),
			'403 secondary retry seconds'   => array( 403, array( 'headers' => array( 'Retry-After' => '120' ) ), 'rate_limited', true ),
			'403 secondary retry http date' => array( 403, array( 'headers' => array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ) ), 'rate_limited', true ),
			'403 malformed retry after'     => array( 403, array( 'headers' => array( 'retry-after' => 'soon' ) ), 'permission_denied', false ),
			'404 not found'                 => array( 404, array(), 'not_found', false ),
			'410 gone'                      => array( 410, array(), 'gone', false ),
			'422 unprocessable'             => array( 422, array(), 'unprocessable', false ),
			'429 rate limited'              => array( 429, array(), 'rate_limited', true ),
			'418 other 4xx'                 => array( 418, array(), 'http_4xx_other', false ),
			'500 server error'              => array( 500, array(), 'http_5xx', false ),
			'503 service unavailable'       => array( 503, array(), 'http_5xx', false ),
			'0 invalid status'              => array( 0, array(), 'response_invalid', false ),
		);
	}

	/** @param array<string, mixed> $response */
	#[DataProvider( 'status_classifications' )]
	public function test_http_client_classifies_statuses_and_rate_limits_consistently(
		int $status,
		array $response,
		string $expected_classification,
		bool $expected_rate_limited
	): void {
		self::assertSame( $expected_classification, GitHubHttpClient::classify_status( $status, $response ) );
		self::assertSame( $expected_rate_limited, GitHubHttpClient::is_rate_limited_response( $response, $status ) );
		self::assertSame( $expected_rate_limited, RepositoryBrowser::is_rate_limited_response( $response, $status ) );
	}

	public function test_http_client_strips_authorization_on_redirect_and_enforces_host_allowlist(): void {
		$canary     = 'ghp_redirect_consolidation_canary_1234567890';
		$observed   = array();
		$responses  = array(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'Location' => 'https://objects.githubusercontent.com/releases/pack.zip' ),
				'body'     => '',
			),
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => 'zip-bytes',
			),
		);
		$guard_hits = 0;

		$headers = GitHubHttpClient::build_headers(
			'application/octet-stream',
			GitHubHttpClient::WORKFLOW_API_VERSION,
			'RAN-Booster-Release-Deployments',
			$canary
		);
		$args    = GitHubHttpClient::build_request_args( 15, $headers, 1024 );
		$result  = GitHubHttpClient::send(
			'GET',
			'https://api.github.com/repos/owner/example/releases/assets/1',
			$args,
			static function ( string $method, string $url, #[\SensitiveParameter] array $request_args ) use ( &$observed, &$responses ): array {
				$observed[] = array(
					'method' => $method,
					'url'    => $url,
					'args'   => $request_args,
				);
				$next       = array_shift( $responses );
				self::assertIsArray( $next );
				return $next;
			},
			static function () use ( &$guard_hits ): void {
				++$guard_hits;
			},
			2,
			array( 'objects.githubusercontent.com' )
		);

		self::assertSame( 200, $result['status'] );
		self::assertSame( 'zip-bytes', $result['body'] );
		self::assertFalse( $result['has_authorization'] );
		self::assertFalse( $result['redirect_rejected'] );
		self::assertSame( 4, $guard_hits );
		self::assertCount( 2, $observed );
		self::assertSame( 'Bearer ' . $canary, $observed[0]['args']['headers']['Authorization'] ?? null );
		self::assertArrayNotHasKey( 'Authorization', $observed[1]['args']['headers'] );
		self::assertSame( 0, $observed[0]['args']['redirection'] );
		self::assertSame( 0, $observed[1]['args']['redirection'] );
		self::assertTrue( $observed[0]['args']['reject_unsafe_urls'] );
		self::assertTrue( $observed[1]['args']['reject_unsafe_urls'] );

		$blocked = GitHubHttpClient::send(
			'GET',
			'https://api.github.com/repos/owner/example/releases/assets/1',
			$args,
			static function ( string $method, string $url, #[\SensitiveParameter] array $request_args ): array {
				unset( $method, $url, $request_args );
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'Location' => 'https://evil.example/pack.zip' ),
					'body'     => '',
				);
			},
			null,
			2,
			array( 'objects.githubusercontent.com' )
		);
		self::assertTrue( $blocked['redirect_rejected'] );

		$exhausted_hops = 0;
		$exhausted      = GitHubHttpClient::send(
			'GET',
			'https://api.github.com/repos/owner/example/releases/assets/1',
			$args,
			static function ( string $method, string $url, #[\SensitiveParameter] array $request_args ) use ( &$exhausted_hops ): array {
				unset( $method, $url, $request_args );
				++$exhausted_hops;
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'Location' => 'https://objects.githubusercontent.com/releases/hop-' . $exhausted_hops . '.zip' ),
					'body'     => '',
				);
			},
			null,
			2,
			array( 'objects.githubusercontent.com' )
		);
		self::assertSame( 3, $exhausted_hops );
		self::assertSame( 302, $exhausted['status'] );
		self::assertTrue( $exhausted['redirect_rejected'] );
		self::assertFalse( $exhausted['has_authorization'] );

		$no_redirects = GitHubHttpClient::send(
			'GET',
			'https://api.github.com/repos/owner/example/releases/assets/1',
			$args,
			static function ( string $method, string $url, #[\SensitiveParameter] array $request_args ): array {
				unset( $method, $url, $request_args );
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'Location' => 'https://objects.githubusercontent.com/releases/pack.zip' ),
					'body'     => '',
				);
			},
			null,
			0,
			array()
		);
		self::assertSame( 302, $no_redirects['status'] );
		self::assertFalse( $no_redirects['redirect_rejected'] );
	}

	public function test_canonical_repository_identity_validator_is_shared_across_layers(): void {
		foreach ( array( 'a', 'octocat', 'Rockets-Are-Nostalgic', str_repeat( 'a', 39 ) ) as $owner ) {
			self::assertTrue( GitHubRepositoryIdentity::is_valid_owner( $owner ), $owner );
		}
		foreach ( array( '', '-lead', 'trail-', 'double--hyphen', 'under_score', 'dot.owner', '.', '..', str_repeat( 'a', 40 ), "octocat\n" ) as $owner ) {
			self::assertFalse( GitHubRepositoryIdentity::is_valid_owner( $owner ), $owner );
		}

		foreach ( array( 'octocat/hello-world', 'RocketsAreNostalgic/.github', 'owner-1/repo_name.v2', 'a/' . str_repeat( 'r', 100 ) ) as $repo ) {
			self::assertTrue( GitHubRepositoryIdentity::is_valid_repository( $repo ), $repo );
			self::assertNotNull( GitHubRepositoryIdentity::encode_repository( $repo ), $repo );
			self::assertSame( $repo, GitHubRepositoryIdentity::parse_github_url( 'https://github.com/' . $repo ), $repo );
			self::assertSame( $repo, GitHubRepositoryIdentity::parse_github_url( 'https://github.com/' . $repo . '/', true ), $repo );
			self::assertNull( GitHubRepositoryIdentity::parse_github_url( 'https://github.com/' . $repo . '/' ), $repo );
		}

		$invalid_repos = array(
			'',
			'no-slash',
			'owner/repo/extra',
			'-bad/repo',
			'bad--owner/repo',
			'bad_owner/repo',
			'owner/.',
			'owner/..',
			'./repo',
			'../repo',
			'owner/' . str_repeat( 'r', 101 ),
			"owner/repo\n",
		);
		$provider      = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		$credential_policy = new CredentialPolicy();
		$webhook_policy    = new WebhookPolicy();
		$webhook_client    = new RepositoryWebhookClient();
		$workflow_client   = new GitHubRepositoryClient();

		foreach ( $invalid_repos as $invalid ) {
			self::assertFalse( GitHubRepositoryIdentity::is_valid_repository( $invalid ), $invalid );
			self::assertNull( GitHubRepositoryIdentity::encode_repository( $invalid ), $invalid );
			self::assertSame( 'invalid_request', $workflow_client->repository( $invalid )['code'], $invalid );

			$webhook_rejected = false;
			try {
				$webhook_policy->normalize_webhook(
					array(
						'label'        => 'Hook',
						'scope'        => 'repository',
						'target'       => $invalid,
						'authority_id' => '101',
					),
					'github-webhook-policy-secret-value-0001'
				);
			} catch ( InvalidWebhookInput ) {
				$webhook_rejected = true;
			}
			self::assertTrue( $webhook_rejected, 'WebhookPolicy must reject: ' . $invalid );

			$client_rejected = false;
			try {
				$webhook_client->assess_setup( '101', $invalid, 'token-value' );
			} catch ( RuntimeException ) {
				$client_rejected = true;
			}
			self::assertTrue( $client_rejected, 'RepositoryWebhookClient must reject: ' . $invalid );

			$snapshot_rejected = false;
			try {
				new RepositorySnapshot( '101', $invalid, 'main', str_repeat( 'a', 40 ), array(), array() );
			} catch ( \InvalidArgumentException ) {
				$snapshot_rejected = true;
			}
			self::assertTrue( $snapshot_rejected, 'RepositorySnapshot must reject: ' . $invalid );
		}

		$owner_rejected = false;
		try {
			$credential_policy->normalize_credential(
				array(
					'label'         => 'Fine-grained',
					'kind'          => 'fine-grained',
					'configuration' => array( 'owner' => 'bad--owner' ),
				),
				'github_pat_' . str_repeat( 'a', 40 )
			);
		} catch ( InvalidCredentialInput ) {
			$owner_rejected = true;
		}
		self::assertTrue( $owner_rejected );
		self::assertSame( '', $provider->expected_update_uri( new RepositoryReference( 'bad--owner/repo', '101', false, null ) ) );
	}

	public function test_production_source_contains_no_runtime_reflection_shims(): void {
		$root     = dirname( __DIR__, 3 ) . '/src';
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$found    = array();
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static source boundary check over package files.
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			foreach ( token_get_all( $source ) as $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && str_starts_with( $token[1], 'Reflection' ) ) {
					$found[] = $file->getFilename() . ':' . $token[2] . ':' . $token[1];
				}
			}
		}

		self::assertSame( array(), $found );
	}

	public function test_core_contract_bridge_supports_active_host_without_reflection(): void {
		$probe = CoreContractBridge::webhook_probe( '12345' );
		self::assertInstanceOf( WebhookEnvelope::class, $probe );
		self::assertTrue( $probe->is_probe() );

		$ignored = CoreContractBridge::webhook_ignored( 'ignored_type' );
		self::assertInstanceOf( WebhookEnvelope::class, $ignored );
		self::assertTrue( $ignored->is_ignored() );

		$rejected = CoreContractBridge::webhook_rejected( 401, 'Webhook authentication failed.', 'scope_mismatch' );
		self::assertInstanceOf( WebhookRejected::class, $rejected );
		self::assertSame( 401, $rejected->get_status_code() );

		$list = CoreContractBridge::release_candidate_list( array(), true, false, null );
		self::assertInstanceOf( RepositoryReleaseCandidateList::class, $list );
		self::assertSame( array(), $list->candidates );

		$read_unavailable = CoreContractBridge::release_read_unavailable( 'GitHub release listing is rate limited.', 502, 'rate_limited', 30, 'release_updater.rate_limited' );
		self::assertInstanceOf( RepositoryReleaseReadUnavailable::class, $read_unavailable );
		self::assertSame( 502, $read_unavailable->getCode() );

		$fs_error = CoreContractBridge::filesystem_unsupported( 'GitHub release listing requires a direct filesystem.' );
		self::assertSame( 501, $fs_error->getCode() );

		$request_error = CoreContractBridge::request_failed( 'rate_limited', 429, 60 );
		$validation    = CoreContractBridge::credential_validation_failure( 'rate_limited', 'rate_limited', 429, 60 );
		self::assertSame( CredentialValidationResult::RATE_LIMITED, $validation->reason );
		if ( class_exists( ProviderFailure::class ) && class_exists( ProviderRequestFailed::class ) ) {
			self::assertInstanceOf( ProviderRequestFailed::class, $request_error );
			self::assertInstanceOf( RepositoryReleaseReadUnavailable::class, $fs_error );
		} else {
			self::assertNull( $request_error );
		}
	}

	public function test_consolidated_json_duplicate_key_and_template_pack_identity_validators(): void {
		self::assertTrue( StarterOrigin::has_unique_object_keys( '{"a":1,"b":{"c":"x:y"}}' ) );
		self::assertFalse( StarterOrigin::has_unique_object_keys( '{"a":1,"a":2}' ) );
		self::assertFalse( StarterOrigin::has_unique_object_keys( '{"a":1,"\u0061":2}' ) );
		self::assertTrue( StarterOrigin::has_unique_object_keys( '{"0":1,"00":2,"1":3}' ) );
		self::assertFalse( StarterOrigin::has_unique_object_keys( '{"0":1,"\u0030":2}' ) );

		$padded_16k           = str_repeat( 'x', 16384 );
		$padded_duplicate     = '{"pad":"' . $padded_16k . '","k":1,"k":2}';
		$padded_unique_60k    = '{"pad":"' . str_repeat( 'y', 61440 ) . '","k":1,"m":2}';
		$escaped_quotes_large = '{"esc":"' . str_repeat( '\\"', 16384 ) . '","k":1}';
		$escaped_quotes_dup   = '{"esc":"' . str_repeat( '\\"', 16384 ) . '","k":1,"k":2}';
		self::assertFalse( StarterOrigin::has_unique_object_keys( $padded_duplicate ) );
		self::assertNull( StarterOrigin::json( $padded_duplicate, 65536 ) );
		self::assertTrue( StarterOrigin::has_unique_object_keys( $padded_unique_60k ) );
		self::assertSame( 1, StarterOrigin::json( $padded_unique_60k, 65536 )['k'] ?? null );
		self::assertTrue( StarterOrigin::has_unique_object_keys( $escaped_quotes_large ) );
		self::assertSame( 1, StarterOrigin::json( $escaped_quotes_large, 65536 )['k'] ?? null );
		self::assertFalse( StarterOrigin::has_unique_object_keys( $escaped_quotes_dup ) );
		self::assertNull( StarterOrigin::json( $escaped_quotes_dup, 65536 ) );

		foreach (
			array(
				'{"\uZZZZ":1}',
				'{"a":1',
				'{"a":1}{"a":1}',
				'{"]:1,"a":1,"a":2}',
				'{\"]:1,"a":1,"a":2}',
				'not-json',
				"{\"a\":1}\0",
			) as $malformed
		) {
			self::assertFalse( StarterOrigin::has_unique_object_keys( $malformed ), $malformed );
		}

		// End-to-end TemplatePack::decode_manifest() regression: a >= 16 KB string value before a shadowed duplicate key must fail closed.
		$incompressible_16k = '';
		for ( $block = 0; $block < 256; ++$block ) {
			$incompressible_16k .= hash( 'sha256', 'manifest-pad-' . $block );
		}
		self::assertSame( 16384, strlen( $incompressible_16k ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact manifest fixture encoding.
		$valid_manifest_json = (string) json_encode( TemplatePackApi3Fixture::manifest(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		$shadowed_manifest   = str_replace(
			'"pack_version":"1.2.3"',
			'"pack_version":"' . $incompressible_16k . '","pack_version":"1.2.3"',
			$valid_manifest_json
		);
		self::assertNotSame( $valid_manifest_json, $shadowed_manifest );

		$valid_archive = TemplatePackApi3Fixture::archive(
			null,
			array( TemplatePack::MANIFEST_PATH => $valid_manifest_json )
		);
		self::assertSame( 'ok', TemplatePack::from_archive( $valid_archive, TemplatePackApi3Fixture::identity( $valid_archive ) )['code'] );

		$shadowed_archive = TemplatePackApi3Fixture::archive(
			null,
			array( TemplatePack::MANIFEST_PATH => $shadowed_manifest )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::from_archive( $shadowed_archive, TemplatePackApi3Fixture::identity( $shadowed_archive ) )['code'] );

		$archive  = 'archive-fixture-bytes';
		$sha256   = hash( 'sha256', $archive );
		$commit   = str_repeat( 'a', 40 );
		$identity = array(
			'repository_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'repository_id'      => '1322743261',
			'release_id'         => 10,
			'release_tag'        => 'v1.2.3',
			'release_commit'     => $commit,
			'release_target'     => $commit,
			'tag_target'         => $commit,
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => 1,
			'asset_id'           => 20,
			'asset_name'         => 'ran-booster-release-bootstrap-templates.zip',
			'asset_state'        => 'uploaded',
			'asset_content_type' => 'application/zip',
			'asset_size'         => strlen( $archive ),
			'asset_digest'       => 'sha256:' . $sha256,
			'asset_sha256'       => $sha256,
		);

		self::assertCount( 18, TemplatePack::IDENTITY_KEYS );
		self::assertTrue( TemplatePack::is_valid_identity( $identity ) );
		self::assertTrue( TemplatePack::is_valid_identity( $identity, $archive ) );
		self::assertFalse( TemplatePack::is_valid_identity( $identity, 'different-archive-bytes' ) );

		$prerelease                = $identity;
		$prerelease['release_tag'] = 'v1.2.3-beta.1';
		self::assertFalse( TemplatePack::is_valid_identity( $prerelease ) );

		$reordered = array_reverse( $identity, true );
		self::assertFalse( TemplatePack::is_valid_identity( $reordered ) );
	}
}
