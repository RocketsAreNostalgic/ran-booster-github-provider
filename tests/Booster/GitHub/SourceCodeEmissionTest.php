<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class SourceCodeEmissionTest extends TestCase {
	/** @return iterable<string, array{string, string|int, ?string}> */
	public static function transport_codes(): iterable {
		$codes = array(
			'http_request_failed'              => 'wp_http.http_request_failed',
			'http_request_not_executed'        => 'wp_http.http_request_not_executed',
			'plugin_custom_failure'            => 'wp_http.plugin_custom_failure',
			'token_expired'                    => null,
			'secret_orchid'                    => null,
			'bad_nonce'                        => null,
			'HTTP_request_failed'              => null,
			'http_request_failed '             => null,
			'http_request_failed%00'           => null,
			'http_request_failed.secret'       => null,
			str_repeat( 'z.', 27 ) . 'zk'      => 'wp_http.' . str_repeat( 'z.', 27 ) . 'zk',
			str_repeat( 'z.', 27 ) . 'zk.more' => null,
			''                                 => null,
		);
		foreach ( array( 'repository', 'immutable_ref', 'current_branch_head', 'path_exists', 'browse', 'validate_credential' ) as $operation ) {
			foreach ( $codes as $code => $expected ) {
				yield $operation . ':' . $code => array( $operation, $code, $expected );
			}
			yield $operation . ':integer' => array( $operation, 17, null );
		}
	}

	#[DataProvider( 'transport_codes' )]
	public function test_transport_codes_are_optional_screened_references( string $operation, string|int $code, ?string $expected ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( new RepositoryResolverWpError( $code ) );
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => 'fixture-material' ) ) );
		if ( 'validate_credential' === $operation ) {
			$result = $browser->validate_credential( 'profile' );
			self::assertSame( 'unavailable', $result->reason );
			self::assertFalse( $result->is_valid() );
			$failure = get_object_vars( $result )['failure'] ?? null;
		} else {
			try {
				match ( $operation ) {
					'repository' => $browser->repository( 'owner/example' ),
					'immutable_ref' => $browser->immutable_ref( 'owner/example', 'main', '42' ),
					'current_branch_head' => $browser->current_branch_head( 'owner/example', 'main' ),
					'path_exists' => $browser->path_exists( 'owner/example', str_repeat( 'a', 40 ), 'packages' ),
					'browse' => $browser->browse( RepositoryBrowseRequest::public_owner( 'owner' ) ),
					default => self::fail( 'Unexpected transport fixture operation.' ),
				};
				self::fail( 'The transport failure must still be reported.' );
			} catch ( RuntimeException $exception ) {
				$failure = class_exists( ProviderFailure::class ) ? ProviderFailure::carried( $exception ) : null;
				self::assertSame( null !== $failure || 'browse' === $operation ? 504 : 502, $exception->getCode() );
				self::assertStringNotContainsString( 'wp_http.', $exception->getMessage() );
			}
		}
		if ( class_exists( ProviderFailure::class ) ) {
			self::assertInstanceOf( ProviderFailure::class, $failure );
			self::assertSame( 'http_request_not_executed' === $code ? 'blocked_by_site' : 'transport_other', $failure->reason );
			self::assertNull( $failure->http_status );
			self::assertNull( $failure->retry_after );
			$fields = get_object_vars( $failure );
			if ( property_exists( ProviderFailure::class, 'source_code' ) ) {
				self::assertSame( $expected, $fields['source_code'] );
			} else {
				self::assertArrayNotHasKey( 'source_code', $fields );
			}
		} else {
			self::assertNull( $failure );
		}
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_http_status_and_payload_never_become_source_codes(): void {
		foreach ( array( 401, 403, 422, 429, 500 ) as $status ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
				$this->response( $status )
			);
			$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => 'fixture-material' ) ) );
			try {
				$browser->repository( 'owner/example' );
				self::fail( 'The HTTP failure was not reported.' );
			} catch ( RuntimeException $exception ) {
				$this->assert_no_source_code( $exception );
			}
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->response( $status ) );
			$result  = $browser->validate_credential( 'profile' );
			$failure = get_object_vars( $result )['failure'] ?? null;
			if ( null !== $failure && class_exists( ProviderFailure::class ) && property_exists( ProviderFailure::class, 'source_code' ) ) {
				self::assertNull( get_object_vars( $failure )['source_code'] );
			}
		}
	}

	public function test_local_and_direct_branch_failures_have_no_source_code(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );
		foreach ( array( 404, 410, 302 ) as $status ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array(
					$this->response( $status ),
					$this->response( 200 ),
				)
			);
			try {
				$browser->current_branch_head( 'owner/example', 'main' );
				self::fail( 'The branch failure was not reported.' );
			} catch ( RuntimeException $exception ) {
				$this->assert_no_source_code( $exception );
			}
		}
		$result  = $browser->validate_credential( 'missing' );
		$failure = get_object_vars( $result )['failure'] ?? null;
		if ( null !== $failure && class_exists( ProviderFailure::class ) && property_exists( ProviderFailure::class, 'source_code' ) ) {
			self::assertNull( get_object_vars( $failure )['source_code'] );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function updater_failures(): iterable {
		foreach ( array( 'rate_limited', 'credential_unavailable', 'repository_access_unavailable', 'operation_failed' ) as $code ) {
			yield $code => array( $code );
		}
	}

	#[DataProvider( 'updater_failures' )]
	public function test_only_the_existing_listing_rate_limit_carrier_emits_an_updater_code( string $code ): void {
		NeutralReleaseUpdaterFixtures::reset();
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new class( $code ) {
				public function __construct( private string $code ) {}

				public function releases( mixed ...$arguments ): object {
					unset( $arguments );
					return new class( $this->code ) {
						public function __construct( private string $code ) {}

						/** @return array<string, mixed> */
						public function list(): array {
							return array(
								'ok'             => false,
								'code'           => $this->code,
								'value'          => null,
								'retry_after'    => 'rate_limited' === $this->code ? 30 : null,
								'cleanup_status' => 'not_applicable',
							);
						}
					};
				}
			}
		);
		try {
			$provider->list_release_candidates( 'plugin', new RepositoryReference( 'owner/example', '42', false, null ), 'stable' );
			self::fail( 'The rate limit was not reported.' );
		} catch ( RuntimeException $exception ) {
			$failure = class_exists( ProviderFailure::class ) ? ProviderFailure::carried( $exception ) : null;
			if ( 'rate_limited' === $code && class_exists( ProviderFailure::class ) && property_exists( \RAN\RepositoryProvider\RepositoryReleaseReadUnavailable::class, 'failure' ) ) {
				self::assertInstanceOf( ProviderFailure::class, $failure );
				self::assertSame( 'rate_limited', $failure->reason );
				self::assertSame( 30, $failure->retry_after );
				self::assertNull( $failure->http_status );
				if ( property_exists( ProviderFailure::class, 'source_code' ) ) {
					self::assertSame( 'release_updater.rate_limited', get_object_vars( $failure )['source_code'] );
				}
			} else {
				self::assertNull( $failure );
			}
		}
	}

	private function assert_no_source_code( RuntimeException $exception ): void {
		$failure = class_exists( ProviderFailure::class ) ? ProviderFailure::carried( $exception ) : null;
		if ( null !== $failure && class_exists( ProviderFailure::class ) && property_exists( ProviderFailure::class, 'source_code' ) ) {
			self::assertNull( get_object_vars( $failure )['source_code'] );
		} else {
			self::assertNotSame( '', $exception->getMessage() );
		}
	}

	/** @return array<string, mixed> */
	private function response( int $status ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			'body'     => 200 === $status ? '[]' : '{"code":"abuse_detected","message":"token_expired"}',
		);
	}
}
