<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/Support/RepositoryResolverSecretsStub.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class CredentialExpiryValidationTest extends TestCase {

	private const TOKEN = 'github-expiry-validation-canary';

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function valid_expiry_headers(): iterable {
		yield 'UTC' => array( '2026-08-31 14:25:30 UTC', '2026-08-31T14:25:30Z' );
		yield 'positive offset' => array( '2026-08-31 14:25:30 +0100', '2026-08-31T13:25:30Z' );
		yield 'negative offset' => array( '2026-08-31 14:25:30 -0530', '2026-08-31T19:55:30Z' );
		yield 'past date' => array( '2020-01-02 03:04:05 UTC', '2020-01-02T03:04:05Z' );
	}

	#[DataProvider( 'valid_expiry_headers' )]
	public function test_validation_returns_strictly_parsed_provider_expiry( string $header, string $expected ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->response( $header ) );

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validate_credential( 'expiry-profile' );

		self::assertTrue( $result->is_valid() );
		self::assertNotNull( $result->expiry );
		self::assertTrue( $result->expiry->is_known() );
		self::assertSame( $expected, $result->expiry->expires_at );
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function unknown_expiry_headers(): iterable {
		yield 'missing' => array( null );
		yield 'invalid calendar date' => array( '2026-02-30 14:25:30 UTC' );
		yield 'unsupported timezone abbreviation' => array( '2026-08-31 14:25:30 BST' );
		yield 'invalid offset' => array( '2026-08-31 14:25:30 +2500' );
		yield 'surrounding whitespace' => array( ' 2026-08-31 14:25:30 UTC ' );
		yield 'control character' => array( "2026-08-31 14:25:30 UTC\n" );
		yield 'oversized' => array( str_repeat( 'x', 65 ) );
	}

	#[DataProvider( 'unknown_expiry_headers' )]
	public function test_missing_or_malformed_expiry_metadata_is_unknown( ?string $header ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset( $this->response( $header ) );

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validate_credential( 'expiry-profile' );

		self::assertTrue( $result->is_valid() );
		self::assertNotNull( $result->expiry );
		self::assertFalse( $result->expiry->is_known() );
		self::assertNull( $result->expiry->expires_at );
	}

	public function test_failed_validation_never_returns_expiry_metadata_or_leaks_header(): void {
		$header = '2026-08-31 14:25:30 UTC';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 401 ),
				'headers'  => array( 'GitHub-Authentication-Token-Expiration' => $header ),
				'body'     => '{"message":"Bad credentials"}',
			)
		);

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validate_credential( 'expiry-profile' );

		self::assertFalse( $result->is_valid() );
		self::assertNull( $result->expiry );
		self::assertStringNotContainsString( $header, (string) $result->get_display_message() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function response( ?string $expiry_header ): array {
		$headers = null === $expiry_header
			? array()
			: array( 'GitHub-Authentication-Token-Expiration' => $expiry_header );

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => $headers,
			'body'     => '{"login":"expiry-user"}',
		);
	}
}
