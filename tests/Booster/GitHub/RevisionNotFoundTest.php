<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class RevisionNotFoundTest extends TestCase {

	/** @return iterable<string, array{string, int, string, bool}> */
	public static function revision_probes(): iterable {
		$probes = array(
			'empty repository'    => array( 200, '[]', true ),
			'readable repository' => array( 200, '[{"name":"main","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', true ),
			'denied'              => array( 403, '[]', false ),
			'not found'           => array( 404, '[]', false ),
			'redirect'            => array( 301, '[]', false ),
			'not modified'        => array( 304, '[]', false ),
			'rate limit'          => array( 429, '[]', false ),
			'unavailable'         => array( 503, '[]', false ),
			'malformed'           => array( 200, '[', false ),
			'empty body'          => array( 200, '', false ),
			'empty string'        => array( 200, '""', false ),
			'empty object'        => array( 200, '{}', false ),
			'null'                => array( 200, 'null', false ),
			'bad entry'           => array( 200, '[null]', false ),
			'bad branch'          => array( 200, '[{"name":"../main","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', false ),
			'bad commit'          => array( 200, '[{"name":"main","commit":{"sha":"bad"}}]', false ),
		);
		foreach ( array(
			'branch' => 'missing',
			'tag'    => 'v9.9.9',
			'commit' => str_repeat( 'b', 40 ),
		) as $kind => $ref ) {
			foreach ( $probes as $label => $probe ) {
				yield $kind . ':' . $label => array( $ref, ...$probe );
			}
			yield $kind . ':ref reappeared' => array( $ref, 200, '[{"name":"' . $ref . '","commit":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}]', false );
		}
	}

	#[DataProvider( 'revision_probes' )]
	public function test_revision_404_uses_only_bounded_contents_evidence( string $ref, int $status, string $body, bool $readable ): void {
		$this->queue( $this->response( 404 ), $this->response( $status, $body ) );
		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => 'fixture-material' ) ) ) )
				->immutable_ref( 'owner/example', $ref, '42', 'profile', true );
			self::fail( 'An unavailable revision must not resolve.' );
		} catch ( RuntimeException $failure ) {
			$this->assert_failure( $failure, $readable );
		}
		$this->assert_requests( $ref );
	}

	public function test_transport_failure_of_probe_does_not_prove_revision_absence(): void {
		$this->queue( $this->response( 404 ), new RepositoryResolverWpError( 'http_request_failed' ) );
		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->immutable_ref( 'owner/example', 'missing', '42' );
			self::fail( 'A failed probe must not resolve a revision.' );
		} catch ( RuntimeException $failure ) {
			$this->assert_failure( $failure, false );
		}
		self::assertCount( $this->has_carrier() ? 3 : 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_manual_archive_request_without_expected_branch_reports_missing_ref(): void {
		$this->queue( $this->response( 404 ), $this->response( 200, '[]' ) );
		$provider = GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), new \stdClass() );
		try {
			$provider->prepare_archive( new ArchiveRequest( new RepositoryReference( 'owner/example', '42', false, null ), 'missing' ) );
			self::fail( 'A manual branch check must not prepare an unavailable revision.' );
		} catch ( RuntimeException $failure ) {
			$this->assert_failure( $failure, true );
		}
		self::assertCount( $this->has_carrier() ? 3 : 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return iterable<string, array{int}> */
	public static function other_statuses(): iterable {
		foreach ( array( 200, 301, 302, 303, 307, 308, 400, 401, 403, 410, 422, 429, 500, 503, 504 ) as $status ) {
			yield (string) $status => array( $status );
		}
	}

	#[DataProvider( 'other_statuses' )]
	public function test_non_404_commit_response_never_sends_a_probe( int $status ): void {
		$this->queue( $this->response( $status, 200 === $status ? str_repeat( 'a', 40 ) : '' ), $this->response( 200, '[]' ) );
		try {
			$resolved = ( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->immutable_ref( 'owner/example', 'main', '42' );
			self::assertSame( 200, $status );
			self::assertSame( str_repeat( 'a', 40 ), $resolved );
		} catch ( RuntimeException ) {
			self::assertNotSame( 200, $status );
		}
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return iterable<string, array{string}> */
	public static function revision_refs(): iterable {
		yield 'branch' => array( 'missing' );
		yield 'tag' => array( 'v9.9.9' );
		yield 'commit' => array( str_repeat( 'b', 40 ) );
	}

	#[DataProvider( 'revision_refs' )]
	public function test_revision_422_reports_ref_not_found_without_sending_an_access_probe( string $ref ): void {
		$this->queue(
			$this->response( 422, '{"message":"No commit found for SHA: upstream-canary"}' ),
			$this->response( 200, '[]' )
		);

		try {
			( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->immutable_ref( 'owner/example', $ref, '42' );
			self::fail( 'A 422 commit lookup must not resolve.' );
		} catch ( RuntimeException $failure ) {
			if ( class_exists( ProviderFailure::class ) && class_exists( ProviderRequestFailed::class ) ) {
				self::assertInstanceOf( ProviderRequestFailed::class, $failure );
				self::assertSame( 422, $failure->getCode() );
				$carried = ProviderFailure::carried( $failure );
				self::assertNotNull( $carried );
				self::assertSame( 'ref_not_found', $carried->reason );
				self::assertSame( 422, $carried->http_status );
				self::assertSame( 'provider_reference_unavailable', $carried->deployment_outcome_code() );
				self::assertNull( $carried->retry_after );
				if ( property_exists( ProviderFailure::class, 'source_code' ) ) {
					self::assertNull( get_object_vars( $carried )['source_code'] );
				}
			} else {
				self::assertSame( RuntimeException::class, $failure::class );
				self::assertSame( 404, $failure->getCode() );
				self::assertSame( 'GitHub could not find that repository revision.', $failure->getMessage() );
			}
			self::assertStringNotContainsString( 'upstream-canary', $failure->getMessage() );
		}

		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	private function has_carrier(): bool {
		return class_exists( ProviderFailure::class ) && class_exists( ProviderRequestFailed::class );
	}

	private function assert_failure( RuntimeException $exception, bool $readable ): void {
		self::assertSame( 404, $exception->getCode() );
		if ( class_exists( ProviderFailure::class ) && class_exists( ProviderRequestFailed::class ) ) {
			self::assertInstanceOf( ProviderRequestFailed::class, $exception );
			$failure = ProviderFailure::carried( $exception );
			self::assertNotNull( $failure );
			self::assertSame( $readable ? 'ref_not_found' : 'repository_not_found_or_no_access', $failure->reason );
			self::assertSame( 404, $failure->http_status );
			self::assertNull( $failure->retry_after );
			if ( property_exists( ProviderFailure::class, 'source_code' ) ) {
				self::assertNull( get_object_vars( $failure )['source_code'] );
			}
		} else {
			self::assertSame( RuntimeException::class, $exception::class );
			self::assertSame( 'GitHub could not find that repository revision, or the selected credential cannot access it.', $exception->getMessage() );
		}
	}

	private function assert_requests( string $ref ): void {
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertCount( $this->has_carrier() ? 3 : 2, $requests );
		self::assertSame( 'https://api.github.com/repos/owner/example/commits/' . rawurlencode( $ref ), $requests[1]['url'] );
		self::assertSame( 'application/vnd.github.sha', $requests[1]['arguments']['headers']['Accept'] );
		if ( $this->has_carrier() ) {
			self::assertSame( 'https://api.github.com/repos/owner/example/branches?per_page=1', $requests[2]['url'] );
			self::assertSame(
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => 65536,
					'reject_unsafe_urls'  => true,
					'headers'             => array(
						'Accept'               => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => RepositoryBrowser::API_VERSION,
						'User-Agent'           => 'RAN-Booster',
						'Authorization'        => 'Bearer fixture-material',
					),
				),
				$requests[2]['arguments']
			);
		}
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed>|RepositoryResolverWpError $probe
	 */
	private function queue( array $response, array|RepositoryResolverWpError $probe ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, '{"id":42,"full_name":"owner/example","private":false,"default_branch":"main"}' ),
				$response,
				$probe,
			)
		);
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body = '' ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			'body'     => $body,
		);
	}
}
