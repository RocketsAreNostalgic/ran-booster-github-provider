<?php


declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RuntimeException;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class RepositoryResolverTest extends TestCase {

	private const TOKEN = 'github-resolution-token-canary';

	private bool $had_webhook_transients;
	private mixed $previous_webhook_transients;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		parent::setUp();

		$this->had_webhook_transients      = array_key_exists( 'ran_booster_webhook_test_transients', $GLOBALS );
		$this->previous_webhook_transients = $GLOBALS['ran_booster_webhook_test_transients'] ?? null;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_webhook_test_transients'] = array();

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => 987654321,
					'full_name'      => 'RocketsAreNostalgic/ran-booster',
					'private'        => false,
					'default_branch' => 'main',
				)
			)
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function tearDown(): void {
		if ( $this->had_webhook_transients ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_webhook_test_transients'] = $this->previous_webhook_transients;
		} else {
			unset( $GLOBALS['ran_booster_webhook_test_transients'] );
		}

		parent::tearDown();
	}

	public function test_documents_organisation_scoped_fine_grained_token_profiles(): void {
		$setup = $this->provider( new RepositoryResolverSecretsStub() )->get_metadata()->admin?->setup;

		self::assertNotNull( $setup );
		self::assertStringContainsString( 'limited to one user or organisation', $setup->credential_summary );
		self::assertStringContainsString( 'select the project repositories once', $setup->credential_summary );
		self::assertStringContainsString( 'Booster does not change that GitHub repository selection', $setup->credential_summary );
		self::assertStringContainsString( 'Contents to Read-only', $setup->credential_summary );
		self::assertStringContainsString( 'admin:repo_hook', $setup->credential_summary );
		self::assertStringContainsString( 'Webhooks: Read and write', $setup->credential_summary );
		self::assertStringContainsString( 'Workflows: Read and write', $setup->credential_summary );
	}

	public function test_anonymous_lookup_resolves_canonical_public_repository_metadata(): void {
		$secrets    = new RepositoryResolverSecretsStub();
		$repository = ( new RepositoryBrowser( $secrets ) )->repository( 'rocketsarenostalgic/ran-booster' );

		self::assertSame(
			array(
				'provider'               => 'gh',
				'locator'                => 'RocketsAreNostalgic/ran-booster',
				'package_slug'           => 'ran-booster',
				'provider_repository_id' => '987654321',
				'private'                => false,
				'default_branch'         => 'main',
				'credential_id'          => null,
			),
			$repository->to_array()
		);
		self::assertSame( array(), $secrets->lookups );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.github.com/repos/rocketsarenostalgic/ran-booster',
			$requests[0]['url']
		);
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertArrayNotHasKey(
			'Authorization',
			$requests[0]['arguments']['headers']
		);
		self::assertSame(
			array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => RepositoryBrowser::API_VERSION,
				'User-Agent'           => 'RAN-Booster',
			),
			$requests[0]['arguments']['headers']
		);
	}

	public function test_mixed_case_repository_name_keeps_canonical_provider_identity(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => 565105478,
					'full_name'      => 'RocketsAreNostalgic/tnyGmaps',
					'private'        => false,
					'default_branch' => 'master',
				)
			)
		);

		$repository = ( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->repository(
			'RocketsAreNostalgic/tnyGmaps'
		);

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $repository->locator );
		self::assertSame( 'tnyGmaps', $repository->package_slug );
		self::assertSame( '565105478', $repository->provider_repository_id );
	}

	public function test_selected_git_hub_credential_resolves_actual_private_repository_metadata(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => '9223372036854775807123',
					'full_name'      => 'RocketsAreNostalgic/private-plugin',
					'private'        => true,
					'default_branch' => 'develop',
				)
			)
		);
		$secrets    = new RepositoryResolverSecretsStub(
			array(
				'private-profile' => self::TOKEN,
			)
		);
		$repository = ( new RepositoryBrowser( $secrets ) )->repository(
			'RocketsAreNostalgic/private-plugin',
			'private-profile'
		);
		$requests   = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertTrue( $repository->private );
		self::assertSame( 'develop', $repository->default_branch );
		self::assertSame( '9223372036854775807123', $repository->provider_repository_id );
		self::assertSame( 'private-profile', $repository->credential_id );
		self::assertSame( array( 'private-profile' ), $secrets->lookups );
		self::assertSame(
			'Bearer ' . self::TOKEN,
			$requests[0]['arguments']['headers']['Authorization']
		);
		self::assertStringNotContainsString( self::TOKEN, $requests[0]['url'] );
	}

	public function test_invalid_git_hub_handle_is_rejected_before_any_http_request(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'invalid owner/repository' );
			self::fail( 'Invalid GitHub handles must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		}
	}

	public function test_missing_selected_credential_fails_before_any_http_request(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'RocketsAreNostalgic/private-plugin', 'missing-profile' );
			self::fail( 'A missing selected credential must not become an anonymous request.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertStringNotContainsString( 'missing-profile', $exception->getMessage() );
			self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		}
	}

	public function test_rejected_credential_never_appears_in_url_or_error(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"credential":"' . self::TOKEN . '"}',
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'rejected-profile' => self::TOKEN ) )
		);

		try {
			$browser->repository( 'RocketsAreNostalgic/private-plugin', 'rejected-profile' );
			self::fail( 'Rejected credentials must fail repository resolution.' );
		} catch ( RuntimeException $exception ) {
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

			self::assertSame( 401, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( self::TOKEN, $requests[0]['url'] );
		}
	}

	public function test_anonymous_lookup_carries_only_valid_numeric_rate_limit_facts(): void {
		foreach ( array( array( '120', '2000000000', 120, 2000000000 ), array( 'header-canary', '999999999999999999999999', null, null ), array( '0', '-1', null, null ), array( '86401', '1', null, 1 ) ) as $facts ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
				$this->error_response(
					403,
					array(
						'X-RateLimit-Remaining' => '0',
						'Retry-After'           => $facts[0],
						'X-RateLimit-Reset'     => $facts[1],
					)
				)
			);
			try {
				( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->repository( 'fixture/rate-limited' );
				self::fail( 'Rate-limited lookup must fail.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ? 403 : 429, $exception->getCode() );
				self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
				self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
				if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
					self::assertInstanceOf( \RAN\RepositoryProvider\ProviderRequestFailed::class, $exception );
					$failure = \RAN\RepositoryProvider\ProviderFailure::from_throwable( $exception );
					self::assertNotNull( $failure );
					self::assertSame( 'rate_limited', $failure->reason );
					self::assertSame( 403, $failure->http_status );
					self::assertSame( $facts[2], $failure->retry_after );
					self::assertSame( $facts[3], $failure->reset_at );
				} else {
					self::assertSame( RuntimeException::class, $exception::class );
					self::assertSame( 'GitHub API rate limit has been reached. Try again later.', $exception->getMessage() );
				}
			}
		}
	}

	/**
	 * @param array<array-key,mixed> $headers
	 */
	#[DataProvider( 'rate_limit_response_provider' )]
	public function test_exact_repository_maps_rate_limit_responses(
		int $status,
		array $headers,
		int $expected_status
	): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->error_response( $status, $headers )
		);
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'RocketsAreNostalgic/rate-limited-plugin' );
			self::fail( 'A failed exact-repository request must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ? $status : $expected_status, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	/**
	 * @param array<array-key,mixed> $headers
	 */
	#[DataProvider( 'rate_limit_response_provider' )]
	public function test_authenticated_listing_maps_rate_limit_responses(
		int $status,
		array $headers,
		int $expected_status
	): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->error_response( $status, $headers )
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );
			self::fail( 'A failed authenticated listing must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expected_status, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	/**
	 * @param array<array-key,mixed> $headers
	 */
	#[DataProvider( 'rate_limit_response_provider' )]
	public function test_public_listing_maps_rate_limit_responses(
		int $status,
		array $headers,
		int $expected_status
	): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->error_response( $status, $headers )
		);
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic' ) );
			self::fail( 'A failed public listing must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expected_status, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, array{string, array{token: string, kind: string, owner: string}}>
	 */
	public static function public_lookup_profile_provider(): array {
		return array(
			'classic PAT across owners'      => array(
				'classic-public',
				array(
					'token' => 'github-classic-public-canary',
					'kind'  => 'classic',
					'owner' => '',
				),
			),
			'fine-grained PAT across owners' => array(
				'fine-public',
				array(
					'token' => 'github-fine-public-canary',
					'kind'  => 'fine-grained',
					'owner' => 'ConfiguredElsewhere',
				),
			),
		);
	}

	/**
	 * @param array{token: string, kind: string, owner: string} $profile
	 */
	#[DataProvider( 'public_lookup_profile_provider' )]
	public function test_explicit_public_profile_authenticates_owner_and_every_page_without_associating_results(
		string $profile_id,
		array $profile
	): void {
		$first_page = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$first_page[] = array(
				'id'             => $index,
				'full_name'      => 'UnrelatedOwner/package-' . $index,
				'private'        => 30 === $index,
				'default_branch' => 'main',
			);
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'Organization',
						'login' => 'UnrelatedOwner',
					)
				),
				$this->response( 200, $first_page ),
				$this->response(
					200,
					array(
						array(
							'id'             => 31,
							'full_name'      => 'UnrelatedOwner/package-31',
							'private'        => false,
							'default_branch' => 'main',
						),
					)
				),
			)
		);
		$secrets = new RepositoryResolverSecretsStub( array( $profile_id => $profile ) );
		$result  = ( new RepositoryBrowser( $secrets ) )->browse(
			RepositoryBrowseRequest::public_owner( 'UnrelatedOwner', $profile_id )
		);

		self::assertCount( 30, $result->repositories );
		self::assertSame( array( $profile_id ), $secrets->lookups );
		foreach ( $result->repositories as $repository ) {
			self::assertFalse( $repository->private );
			self::assertNull( $repository->credential_id );
		}

		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertCount( 3, $requests );
		self::assertSame( 'https://api.github.com/users/UnrelatedOwner', $requests[0]['url'] );
		self::assertStringContainsString( '/orgs/UnrelatedOwner/repos?type=public&', $requests[1]['url'] );
		foreach ( $requests as $request ) {
			self::assertSame( 'Bearer ' . $profile['token'], $request['arguments']['headers']['Authorization'] );
			self::assertStringNotContainsString( $profile['token'], $request['url'] );
		}
	}

	public function test_missing_explicit_public_profile_fails_before_any_http_request(): void {
		$secrets = new RepositoryResolverSecretsStub();
		$browser = new RepositoryBrowser( $secrets );

		try {
			$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic', 'missing-profile' ) );
			self::fail( 'A missing public lookup profile must not become an anonymous request.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertStringNotContainsString( 'missing-profile', $exception->getMessage() );
			self::assertSame( array( 'missing-profile' ), $secrets->lookups );
			self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		}
	}

	/**
	 * @param array<array-key,mixed> $headers
	 */
	#[DataProvider( 'rate_limit_response_provider' )]
	public function test_explicit_public_profile_never_retries_denials_anonymously(
		int $status,
		array $headers,
		int $expected_status
	): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->error_response( $status, $headers ) );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'public-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic', 'public-profile' ) );
			self::fail( 'An authenticated public lookup denial must fail closed.' );
		} catch ( RuntimeException $exception ) {
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
			self::assertSame( $expected_status, $exception->getCode() );
			self::assertCount( 1, $requests );
			self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		}
	}

	public function test_explicit_public_profile_fails_closed_when_a_later_page_is_rate_limited(): void {
		$first_page = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$first_page[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/package-' . $index,
				'private'        => false,
				'default_branch' => 'main',
			);
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, $first_page ),
				$this->error_response( 429, array() ),
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'public-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic', 'public-profile' ) );
			self::fail( 'A later authenticated page denial must not return partial results.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 429, $exception->getCode() );
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
			self::assertCount( 3, $requests );
			foreach ( $requests as $request ) {
				self::assertSame( 'Bearer ' . self::TOKEN, $request['arguments']['headers']['Authorization'] );
			}
		}
	}

	public function test_anonymous_public_lookup_never_infers_a_constant_or_remembers_a_prior_profile(): void {
		$secrets = new RepositoryResolverSecretsStub(
			array(
				'constant'       => 'github-constant-canary',
				'public-profile' => self::TOKEN,
			)
		);
		$browser = new RepositoryBrowser( $secrets );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, array() ),
			)
		);

		$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic', 'public-profile' ) );
		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		foreach ( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() as $request ) {
			self::assertSame( 'Bearer ' . self::TOKEN, $request['arguments']['headers']['Authorization'] );
		}

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, array() ),
			)
		);
		$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic' ) );

		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		foreach ( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() as $request ) {
			self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
		}

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->repository_identity_response() );
		$browser->repository( 'RocketsAreNostalgic/example-plugin' );
		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		self::assertArrayNotHasKey(
			'Authorization',
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests()[0]['arguments']['headers']
		);
	}

	public function test_listing_keeps_earlier_results_when_a_later_page_is_rate_limited(): void {
		$items = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$items[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/package-' . $index,
				'private'        => true,
				'default_branch' => 'main',
			);
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $items ),
				$this->error_response( 429, array() ),
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		$result = $browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );

		self::assertTrue( $result->is_partial() );
		self::assertSame( RepositoryBrowseResult::RATE_LIMIT, $result->partial_reason );
		self::assertCount( 30, $result->repositories );
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_listing_returns_an_explicit_partial_result_after_five_pages(): void {
		$pages = array();
		for ( $page = 0; $page < RepositoryBrowseRequest::MAX_REMOTE_CALLS; ++$page ) {
			$items = array();
			for ( $index = 1; $index <= 30; ++$index ) {
				$id      = $page * 30 + $index;
				$items[] = array(
					'id'             => $id,
					'full_name'      => 'RocketsAreNostalgic/package-' . $id,
					'private'        => true,
					'default_branch' => 'main',
				);
			}
			$pages[] = $this->response( 200, $items );
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( $pages );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		$result = $browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );

		self::assertTrue( $result->is_partial() );
		self::assertSame( RepositoryBrowseResult::LIMIT, $result->partial_reason );
		self::assertCount( 150, $result->repositories );
		self::assertCount( RepositoryBrowseRequest::MAX_REMOTE_CALLS, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_thirty_representative_repository_objects_fit_within_the_response_budget(): void {
		$items = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$items[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/representative-package-' . $index,
				'private'        => false,
				'default_branch' => 'main',
				'description'    => str_repeat( 'Representative repository metadata. ', 150 ),
			);
		}
		$large_page = $this->response( 200, $items );
		self::assertGreaterThan( 150000, strlen( $large_page['body'] ) );
		self::assertLessThanOrEqual( RepositoryBrowseRequest::PER_RESPONSE_BYTES, strlen( $large_page['body'] ) );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$large_page,
				$this->response( 200, array() ),
			)
		);
		$result = ( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) ) ) )->browse(
			RepositoryBrowseRequest::accessible( 'listing-profile' )
		);

		self::assertFalse( $result->is_partial() );
		self::assertCount( 30, $result->repositories );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertCount( 2, $requests );
		self::assertStringContainsString( 'per_page=30', $requests[0]['url'] );
		self::assertSame( RepositoryBrowseRequest::PER_RESPONSE_BYTES + 1, $requests[0]['arguments']['limit_response_size'] );
	}

	public function test_listing_rejects_an_invalid_success_response_without_leaking_it(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response( 200, array( 'message' => 'upstream-response-canary' ) )
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );
			self::fail( 'An invalid repository-list response must not be accepted.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 422, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, array{int, array<string, string>, int}>
	 */
	public static function rate_limit_response_provider(): array {
		return array(
			'explicit 429'                   => array( 429, array(), 429 ),
			'exhausted rate-limit allowance' => array( 403, array( 'X-RateLimit-Remaining' => '0' ), 429 ),
			'valid retry-after delay'        => array( 403, array( 'Retry-After' => '120' ), 429 ),
			'valid retry-after date'         => array( 403, array( 'Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT' ), 429 ),
			'ordinary permission denial'     => array( 403, array(), 403 ),
			'malformed rate-limit headers'   => array(
				403,
				array(
					'X-RateLimit-Remaining' => 'header-canary',
					'Retry-After'           => 'header-canary',
				),
				403,
			),
		);
	}

	public function test_discovery_and_credential_validation_do_not_expose_retryable_deployment_failures(): void {
		$transport = new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'http_request_failed' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $transport );
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->browse( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic' ) );
			self::fail( 'A discovery transport failure must be reported normally.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringNotContainsString( 'http_request_failed', $failure->getMessage() );
		}

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $transport );
		$result = ( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile-1' => self::TOKEN ) ) ) )
			->validate_credential( 'profile-1' );

		self::assertFalse( $result->is_valid() );
		self::assertSame( 'unavailable', $result->reason );
	}

	public function test_repository_path_check_distinguishes_directories_from_files_and_missing_paths(): void {
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) )
		);
		$ref     = str_repeat( 'a', 40 );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					array(
						'name' => 'plugin.php',
						'type' => 'file',
					),
				)
			)
		);
		self::assertTrue(
			$browser->path_exists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/My Plugin', 'private-profile', true )
		);
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/private-plugin/contents/packages/My%20Plugin?ref=' . $ref,
			$requests[0]['url']
		);
		self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 1024, $requests[0]['arguments']['limit_response_size'] );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'name' => 'plugin.php',
					'type' => 'file',
				)
			)
		);
		self::assertFalse( $browser->path_exists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/plugin.php', 'private-profile', true ) );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->error_response( 404, array() ) );
		self::assertFalse( $browser->path_exists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/missing', 'private-profile', true ) );
	}

	public function test_repository_path_check_rejects_malformed_or_unexpected_successful_bodies(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );
		$ref     = str_repeat( 'a', 40 );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => " \n[]",
			)
		);
		self::assertTrue( $browser->path_exists( 'RocketsAreNostalgic/example-plugin', $ref, 'packages/example' ) );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => " \n{}",
			)
		);
		self::assertFalse( $browser->path_exists( 'RocketsAreNostalgic/example-plugin', $ref, 'packages/example' ) );

		foreach ( array( '', ' ', 'null', 'true', '"file"', '<html>unavailable</html>' ) as $body ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
				)
			);

			try {
				$browser->path_exists( 'RocketsAreNostalgic/example-plugin', $ref, 'packages/example' );
				self::fail( 'Unexpected successful GitHub path responses must fail closed.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'GitHub could not check the repository path.', $failure->getMessage() );
				self::assertSame( 502, $failure->getCode() );
			}
		}
	}

	public function test_reports_missing_branch_only_after_current_contents_access_is_proven(): void {
		$has_carrier = class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class );
		foreach ( array( false, true ) as $check_identity ) {
			$responses   = $check_identity ? array( $this->repository_identity_response( true ) ) : array();
			$responses[] = $this->error_response( 404, array() );
			$responses[] = $this->response( 200, array() );
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( $responses );
			$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'read-profile' => self::TOKEN ) ) );

			try {
				if ( $check_identity ) {
					$browser->branch_head( 'RocketsAreNostalgic/example-plugin', 'deleted', '987654321', 'read-profile', true );
				} else {
					$browser->current_branch_head( 'RocketsAreNostalgic/example-plugin', 'deleted', 'read-profile', true );
				}
				self::fail( 'A missing branch must never resolve to another commit.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 404, $failure->getCode() );
				if ( $has_carrier ) {
					self::assertInstanceOf( \RAN\RepositoryProvider\ProviderRequestFailed::class, $failure );
					$reason = \RAN\RepositoryProvider\ProviderFailure::carried( $failure );
					self::assertNotNull( $reason );
					self::assertSame( 'ref_not_found', $reason->reason );
					self::assertSame( 404, $reason->http_status );
					self::assertSame( 'provider_reference_unavailable', $reason->deployment_outcome_code() );
				} else {
					self::assertSame( RuntimeException::class, $failure::class );
					self::assertSame( 'GitHub could not find that repository branch, or the selected credential cannot access it.', $failure->getMessage() );
				}
				self::assertStringNotContainsString( self::TOKEN, $failure->getMessage() );
				self::assertStringNotContainsString( 'upstream-response-canary', $failure->getMessage() );
			}
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
			self::assertCount( ( $check_identity ? 2 : 1 ) + ( $has_carrier ? 1 : 0 ), $requests );
			if ( $has_carrier ) {
				$probe = $requests[ count( $requests ) - 1 ];
				self::assertSame( 'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/branches?per_page=1', $probe['url'] );
				self::assertSame( 0, $probe['arguments']['redirection'] );
				self::assertSame( 65536, $probe['arguments']['limit_response_size'] );
				self::assertSame( 'Bearer ' . self::TOKEN, $probe['arguments']['headers']['Authorization'] );
			}
		}
	}

	public function test_gone_branch_carries_reference_failure_without_an_access_probe(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->error_response( 410, array() ) );
		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->current_branch_head( 'owner/example', 'deleted' );
			self::fail( 'A gone branch must not resolve.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 410, $failure->getCode() );
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ) {
				self::assertSame( 'ref_not_found', \RAN\RepositoryProvider\ProviderFailure::carried( $failure )?->reason );
				self::assertSame( 410, \RAN\RepositoryProvider\ProviderFailure::carried( $failure )?->http_status );
			} else {
				self::assertSame( RuntimeException::class, $failure::class );
				self::assertSame( 'The requested GitHub repository branch is no longer available.', $failure->getMessage() );
			}
		}
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_branch_redirect_is_blocked_without_claiming_a_rename_or_following_it(): void {
		foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
				$this->error_response( $status, array( 'Location' => 'https://example.invalid/redirect-canary?access_token=synthetic' ) )
			);
			try {
				( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->current_branch_head( 'owner/example', 'old-name' );
				self::fail( 'A branch redirect must never resolve to another branch.' );
			} catch ( RuntimeException $failure ) {
				if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ) {
					self::assertSame( 'redirect_blocked', \RAN\RepositoryProvider\ProviderFailure::carried( $failure )?->reason );
					self::assertNull( \RAN\RepositoryProvider\ProviderFailure::carried( $failure )?->http_status );
				} else {
					self::assertSame( RuntimeException::class, $failure::class );
					self::assertSame( 502, $failure->getCode() );
					self::assertSame( 'GitHub could not resolve the repository branch.', $failure->getMessage() );
				}
				self::assertStringNotContainsString( 'redirect-canary', $failure->getMessage() );
				self::assertStringNotContainsString( 'upstream-response-canary', $failure->getMessage() );
			}
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
			self::assertCount( 1, $requests );
			self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		}
	}

	#[DataProvider( 'branch_access_probes' )]
	public function test_branch_404_does_not_confuse_metadata_access_or_bad_probes_with_contents_access( int $status, string $body, bool $readable ): void {
		$has_carrier = class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response( true ),
				$this->error_response( 404, array() ),
				array(
					'response' => array( 'code' => $status ),
					'body'     => $body,
				),
			)
		);
		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'read-profile' => self::TOKEN ) ) ) )
				->branch_head( 'RocketsAreNostalgic/example-plugin', 'deleted', '987654321', 'read-profile', true );
			self::fail( 'An inaccessible or missing branch must not resolve.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 404, $failure->getCode() );
			if ( $has_carrier ) {
				$reason = \RAN\RepositoryProvider\ProviderFailure::carried( $failure );
				self::assertNotNull( $reason );
				self::assertSame( $readable ? 'ref_not_found' : 'repository_not_found_or_no_access', $reason->reason );
				self::assertSame( 404, $reason->http_status );
			} else {
				self::assertSame( RuntimeException::class, $failure::class );
			}
			self::assertStringNotContainsString( 'upstream-response-canary', $failure->getMessage() );
		}
		self::assertCount( $has_carrier ? 3 : 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return array<string, array{int, string, bool}> */
	public static function branch_access_probes(): array {
		return array(
			'empty repository'    => array( 200, '[]', true ),
			'readable branch'     => array( 200, '[{"name":"main","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', true ),
			'branch reappeared'   => array( 200, '[{"name":"deleted","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', false ),
			'metadata only'       => array( 404, '{"message":"upstream-response-canary"}', false ),
			'rejected credential' => array( 401, '[]', false ),
			'denied contents'     => array( 403, '[]', false ),
			'rate limited'        => array( 429, '[]', false ),
			'unavailable'         => array( 503, '[]', false ),
			'redirect'            => array( 301, '[]', false ),
			'not modified'        => array( 304, '[]', false ),
			'not a list'          => array( 200, '{}', false ),
			'not JSON'            => array( 200, 'upstream-response-canary', false ),
			'wrong item'          => array( 200, '[null]', false ),
			'missing name'        => array( 200, '[{"commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', false ),
			'invalid branch name' => array( 200, '[{"name":"","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', false ),
			'wrong commit'        => array( 200, '[{"name":"main","commit":{"sha":"upstream-response-canary"}}]', false ),
			'too many items'      => array( 200, '[{},{}]', false ),
		);
	}

	public function test_branch_access_probe_transport_failure_does_not_prove_absence(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->error_response( 404, array() ), new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'transport-canary' ) )
		);
		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->current_branch_head( 'owner/example', 'deleted' );
			self::fail( 'Transport failure must not prove branch absence.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 404, $failure->getCode() );
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ) {
				self::assertSame( 'repository_not_found_or_no_access', \RAN\RepositoryProvider\ProviderFailure::carried( $failure )?->reason );
			} else {
				self::assertSame( RuntimeException::class, $failure::class );
			}
		}
	}

	public function test_branch_success_still_requires_the_requested_name_and_commit(): void {
		foreach ( array( 'main', 'other' ) as $name ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
				$this->response(
					200,
					array(
						'name'   => $name,
						'commit' => array( 'sha' => str_repeat( 'A', 40 ) ),
					)
				)
			);
			try {
				$head = ( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->current_branch_head( 'owner/example', 'main' );
				self::assertSame( 'main', $name );
				self::assertSame( str_repeat( 'a', 40 ), $head );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'other', $name );
				self::assertSame( 502, $failure->getCode() );
			}
			self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		}
	}

	private function provider( RepositoryResolverSecretsStub $secrets ): GitHubProvider {
		$provider = GitHubProvider::create(
			$secrets,
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}

	/**
	 * @return array<array-key,mixed>
	 */
	private function repository_identity_response( bool $is_private = false, string $id = '987654321' ): array {
		return $this->response(
			200,
			array(
				'id'             => $id,
				'full_name'      => 'RocketsAreNostalgic/example-plugin',
				'private'        => $is_private,
				'default_branch' => 'main',
			)
		);
	}

	/**
	 * @param array<array-key,mixed> $body
	 * @return array<array-key,mixed>
	 */
	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}

	/**
	 * @param array<array-key,mixed> $headers
	 * @return array<array-key,mixed>
	 */
	private function error_response( int $status, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '{"message":"upstream-response-canary"}',
		);
	}
}
