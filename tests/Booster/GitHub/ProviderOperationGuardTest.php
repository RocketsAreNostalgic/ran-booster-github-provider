<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/Support/PreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class ProviderOperationGuardTest extends TestCase {
	#[DataProvider( 'refusal_results' )]
	public function test_unavailable_anonymous_operations_never_send_http( mixed $result ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( array() );
		$provider = $this->provider( static fn (): mixed => $result );
		try {
			$provider->resolve_repository( new RepositoryLookupRequest( 'owner/example' ) );
			self::fail( 'Unavailable selection must refuse anonymous I/O.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'GitHub provider operation is unavailable.', $failure->getMessage() );
		}
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return iterable<string,array{mixed}> */
	public static function refusal_results(): iterable {
		yield 'false' => array( false );
		yield 'truthy-number' => array( 1 );
		yield 'truthy-string' => array( 'true' );
	}

	public function test_guard_exception_is_pathless_and_does_not_disclose_host_message(): void {
		$provider = $this->provider( static fn (): never => throw new RuntimeException( 'synthetic-private-host-failure' ) );
		$this->expectExceptionMessage( 'GitHub provider operation is unavailable.' );
		$provider->resolve_repository( new RepositoryLookupRequest( 'owner/example' ) );
	}

	public function test_selection_loss_during_credential_read_refuses_before_http(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( array() );
		$available = true;
		$store     = $this->createMock( ProviderCredentialStore::class );
		$store->expects( self::once() )->method( 'credential_material' )->willReturnCallback(
			static function () use ( &$available ): array {
				$available = false;
				return array( 'secret' => 'synthetic-private-material' );
			}
		);
		$provider = $this->provider(
			static function () use ( &$available ): bool {
				return $available;
			},
			$store
		);
		try {
			$provider->resolve_repository( new RepositoryLookupRequest( 'owner/example', 'selected' ) );
			self::fail( 'Changed selection must refuse before HTTP.' );
		} catch ( RuntimeException ) {
			self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		}
	}

	public function test_selection_loss_after_http_discards_response(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->repository_response() );
		$provider = $this->provider( static fn (): bool => array() === \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		try {
			$provider->resolve_repository( new RepositoryLookupRequest( 'owner/example' ) );
			self::fail( 'Changed selection must discard the response.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'GitHub provider operation is unavailable.', $failure->getMessage() );
		}
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	#[DataProvider( 'archive_privacy' )]
	public function test_prepared_archive_refuses_late_use_and_cleans_up_after_selection_loss( bool $is_private, bool $historical ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_response( $is_private ),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => str_repeat( 'a', 40 ),
				),
			)
		);
		$available = true;
		$provider  = $this->provider(
			static function () use ( &$available ): bool {
				return $available;
			},
			new RepositoryResolverSecretsStub( array( 'selected' => 'synthetic-private-material' ) )
		);
		$archive   = $provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', $is_private, $is_private ? 'selected' : null ), $historical ? str_repeat( 'a', 40 ) : 'main', selected_commit: $historical ? str_repeat( 'a', 40 ) : null ) );
		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );
		$url       = $archive->get_url();
		$available = false;
		try {
			$archive->verify_current_head();
			self::fail( 'Prepared archive must not disclose credentials after selection loss.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Provider archive operation is unavailable.', $failure->getMessage() );
		}
		try {
			try {
				$archive->guard_request( array(), $url );
				self::fail( 'Late anonymous or credentialed HTTP must refuse.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'Provider archive operation is unavailable.', $failure->getMessage() );
			}
			if ( $is_private ) {
				try {
					$archive->authenticate_request( array(), $url );
					self::fail( 'Late archive credentials must not be released.' );
				} catch ( RuntimeException $failure ) {
					self::assertSame( 'Provider archive operation is unavailable.', $failure->getMessage() );
				}
			}
		} finally {
			$archive->cleanup();
		}
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return iterable<string,array{bool,bool}> */
	public static function archive_privacy(): iterable {
		yield 'anonymous' => array( false, false );
		yield 'private' => array( true, false );
		yield 'historical-anonymous' => array( false, true );
		yield 'historical-private' => array( true, true );
	}

	#[DataProvider( 'default_credential_identities' )]
	public function test_private_archive_reacquires_exact_resolved_default_without_substitution( string $identity, bool $historical ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_response( true ),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => str_repeat( 'a', 40 ),
				),
			)
		);
		$lookups = array();
		$state   = new class() { public bool $changed = false;
		};
		$store   = $this->createMock( ProviderCredentialStore::class );
		$store->method( 'credential_material' )->willReturnCallback(
			static function ( ?string $id ) use ( &$lookups, $state, $identity ): array {
				$lookups[] = $id;
				$resolved  = null === $id && $state->changed ? 'different-default' : ( $id ?? $identity );
				return array(
					'id'     => $resolved,
					'secret' => $resolved === $identity ? 'synthetic-original-material' : 'synthetic-substitute-material',
				);
			}
		);
		$provider = $this->provider( static fn (): bool => true, $store );
		$archive  = $provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', true, null ), $historical ? str_repeat( 'a', 40 ) : 'main', selected_commit: $historical ? str_repeat( 'a', 40 ) : null ) );
		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );
		$state->changed = true;
		if ( $historical ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array(
					$this->repository_response( true ),
					array(
						'response' => array( 'code' => 200 ),
						'body'     => str_repeat( 'a', 40 ),
					),
				)
			);
			$archive->verify_current_head();
			self::assertSame( array( null ), array_values( array_filter( $lookups, static fn ( ?string $id ): bool => $identity !== $id ) ) );
		}
		try {
			$result = $archive->authenticate_request( array(), $archive->get_url() );
			self::assertTrue( 'Bearer synthetic-original-material' === ( $result['headers']['Authorization'] ?? null ) );
			$last = array_key_last( $lookups );
			self::assertNotNull( $last );
			self::assertSame( $identity, $lookups[ $last ] );
		} finally {
			$archive->cleanup();
		}
	}

	/** @return iterable<string,array{string,bool}> */
	public static function default_credential_identities(): iterable {
		yield 'constant' => array( 'constant', false );
		yield 'sole-file' => array( 'saved-profile', false );
		yield 'historical-constant' => array( 'constant', true );
		yield 'historical-sole-file' => array( 'saved-profile', true );
	}

	public function test_release_token_supplier_binds_first_default_identity_without_eager_read(): void {
		$lookups = array();
		$store   = $this->createMock( ProviderCredentialStore::class );
		$store->method( 'credential_material' )->willReturnCallback(
			static function ( ?string $id ) use ( &$lookups ): array {
				$lookups[] = $id;
				return array(
					'id'     => $id ?? ( 1 === count( $lookups ) ? 'original' : 'substitute' ),
					'secret' => 'synthetic-material',
				);
			}
		);
		$provider = $this->provider( static fn (): bool => true, $store );
		$method   = new \ReflectionMethod( GitHubProvider::class, 'release_access_token' );
		$supplier = $method->invoke( $provider, new RepositoryReference( 'owner/example', '42', true, null ) );
		self::assertInstanceOf( \Closure::class, $supplier );
		self::assertSame( array(), $lookups );
		$supplier();
		$supplier();
		self::assertSame( array( null, 'original' ), $lookups );
	}

	public function test_guarded_factory_refuses_old_registrar(): void {
		$this->expectExceptionMessage( 'The guarded release registrar is incompatible.' );
		GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), new \stdClass(), operation_guard: static fn (): bool => true );
	}

	public function test_native_callbacks_are_forwarded_unchanged_to_guarded_registrar(): void {
		$guard     = static fn (): bool => true;
		$begin     = static fn (): string => 'owner-tag';
		$complete  = static function (): void {};
		$registrar = new class() {
			/** @var list<callable> */
			public array $received = array();
			public function with_operation_guard( callable $guard ): object {
				$this->received[] = $guard;
				return $this;
			}
			public function with_native_mutation_callbacks( callable $begin, callable $complete ): object {
				$this->received[] = $begin;
				$this->received[] = $complete;
				return $this;
			}
		};
		GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), $registrar, operation_guard: $guard, native_mutation_begin: $begin, native_mutation_complete: $complete );
		self::assertSame( array( $guard, $begin, $complete ), $registrar->received );
	}

	public function test_historical_commit_is_resolved_exactly_again_before_mutation_without_branch_head(): void {
		$sha      = str_repeat( 'a', 40 );
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $sha,
		);
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->repository_response(), $response, $this->repository_response(), $response ) );
		$provider = $this->provider( static fn (): bool => true );
		self::assertSame( 2, $provider->get_metadata()->archive_api_version );
		self::assertSame( '2', GitHubProvider::compatibility_contract()['compatibility_revision'] );
		$archive = $provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', false, null ), $sha, selected_commit: $sha ) );
		try {
			self::assertSame( $sha, $archive->get_resolved_ref() );
			self::assertStringEndsWith( '/zipball/' . $sha, $archive->get_url() );
			$archive->verify_current_head();
			$urls = array_column( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), 'url' );
			self::assertSame( array( 'https://api.github.com/repos/owner/example', 'https://api.github.com/repos/owner/example/commits/' . $sha, 'https://api.github.com/repos/owner/example', 'https://api.github.com/repos/owner/example/commits/' . $sha ), $urls );
		} finally {
			$archive->cleanup();
		}
	}

	public function test_historical_sha256_refuses_before_credentials_and_http(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( array() );
		$store = $this->createMock( ProviderCredentialStore::class );
		$store->expects( self::never() )->method( 'credential_material' );
		$provider = $this->provider( static fn (): bool => true, $store );
		$sha      = str_repeat( 'a', 64 );
		try {
			$provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', true, 'selected' ), $sha, selected_commit: $sha ) );
			self::fail( 'GitHub SHA-256 selection must refuse.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 400, $failure->getCode() );
		}
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	#[DataProvider( 'historical_refusals' )]
	public function test_historical_fresh_verification_refuses_changed_identity_access_or_commit( string $stage, string $failure ): void {
		$sha               = str_repeat( 'a', 40 );
		$repository        = $this->repository_response();
		$commit            = array(
			'response' => array( 'code' => 200 ),
			'body'     => $sha,
		);
		$failed_repository = $repository;
		$failed_commit     = $commit;
		if ( 'identity' === $failure ) {
			$failed_repository['body'] = '{"id":43,"full_name":"owner/example","private":false,"default_branch":"main"}';
		} elseif ( 'access' === $failure ) {
			$failed_repository = array(
				'response' => array( 'code' => 404 ),
				'body'     => '{}',
			);
		} else {
			$failed_commit['body'] = str_repeat( 'b', 40 );
		}
		$responses = array( $failed_repository, $failed_commit );
		if ( 'final' === $stage ) {
			$responses = array_merge( array( $repository, $commit ), $responses );
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( $responses );
		$provider = $this->provider( static fn (): bool => true );
		$archive  = null;
		try {
			$archive = $provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', false, null ), $sha, selected_commit: $sha ) );
			$archive->verify_current_head();
			self::fail( 'Changed historical authority must refuse.' );
		} catch ( RuntimeException $failure_result ) {
			self::assertNotSame( '', $failure_result->getMessage() );
		} finally {
			$archive?->cleanup();
		}
	}

	/** @return iterable<string,array{string,string}> */
	public static function historical_refusals(): iterable {
		foreach ( array( 'initial', 'final' ) as $stage ) {
			foreach ( array( 'identity', 'access', 'commit' ) as $failure ) {
				yield $stage . '-' . $failure => array( $stage, $failure );
			}
		}
	}

	/** @return array<string,mixed> */
	private function repository_response( bool $is_private = false ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"id":42,"full_name":"owner/example","private":' . ( $is_private ? 'true' : 'false' ) . ',"default_branch":"main"}',
		);
	}

	private function provider( callable $guard, ?ProviderCredentialStore $store = null ): GitHubProvider {
		$registrar = new class() {
			public ?\Closure $guard = null;
			public function with_operation_guard( callable $guard ): object {
				$this->guard = \Closure::fromCallable( $guard );
				return $this;
			}
		};
		$provider  = GitHubProvider::create( $store ?? new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), $registrar, operation_guard: $guard );
		self::assertInstanceOf( GitHubProvider::class, $provider );
		return $provider;
	}
}
