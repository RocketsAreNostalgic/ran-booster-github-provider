<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\RepositoryWebhookClient;
use RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError;

final class RepositoryWebhookClientTest extends TestCase {

	private const TOKEN  = 'request-token-canary';
	private const SECRET = 'webhook-signing-canary-which-must-not-return';

	/** @return iterable<string, array{string}> */
	public static function fitness_actions(): iterable {
		yield 'setup' => array( 'assess_setup' );
		yield 'check' => array( 'assess_check' );
		yield 'reconfigure' => array( 'assess_reconfigure' );
		yield 'remove' => array( 'assess_remove' );
		yield 'test' => array( 'assess_test' );
	}

	#[DataProvider( 'fitness_actions' )]
	public function test_fitness_uses_one_bounded_read_and_returns_no_credential( string $method ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->response( 200, array( 'id' => 101 ), array( 'x-oauth-scopes' => 'repo, admin:repo_hook' ) ) )
		);
		$result   = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		$evidence = $result->to_array();

		self::assertCount( 1, $requests );
		self::assertSame( 65536, $requests[0]['arguments']['limit_response_size'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertSame( 'classic_scope_broad', $evidence['code'] );
		self::assertSame( 'observed', $evidence['evidence'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $evidence, JSON_THROW_ON_ERROR ) );
	}

	#[DataProvider( 'fitness_actions' )]
	public function test_fitness_failure_preserves_the_requested_action( string $method ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 503, array() ) ) );

		$result = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN );
		$action = strtolower( substr( $method, strlen( 'assess_' ) ) );

		self::assertSame( $action . '_assessment_unavailable', $result->to_array()['code'] );
	}

	public function test_setup_uses_at_most_five_successful_calls_across_three_pages(): void {
		$page = array();
		for ( $id = 1; $id <= 100; ++$id ) {
			$page[] = $this->hook( $id, 'https://other.example/' . $id );
		}
		$created = $this->hook( 999, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $page ),
				$this->response( 200, $page ),
				$this->response( 200, array( $this->hook( 301, 'https://other.example/301' ) ) ),
				$this->response( 201, $created ),
				$this->response( 200, $created ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configured_pending_delivery', $result->code() );
		self::assertCount( 5, $requests );
		self::assertSame( array( 'GET', 'GET', 'GET', 'POST', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertSame( array( 262144, 262144, 262144, 65536, 65536 ), array_column( array_column( $requests, 'arguments' ), 'limit_response_size' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::SECRET, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	/** @return iterable<string, array{int, string}> */
	public static function deterministic_create_failures(): iterable {
		yield 'bad request' => array( 400, 'http_4xx_other' );
		yield 'unauthorized' => array( 401, 'credential_rejected' );
		yield 'forbidden' => array( 403, 'permission_denied' );
		yield 'repository absent' => array( 404, 'repository_not_found_or_no_access' );
		yield 'validation rejected' => array( 422, 'http_4xx_other' );
	}

	#[DataProvider( 'deterministic_create_failures' )]
	public function test_deterministic_create_failure_is_failed_without_a_hook_identity( int $status, string $code ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( $status, array( 'message' => 'request rejected' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( $code, $result->code() );
		self::assertNull( $result->hook_id() );
		self::assertSame( array( 'GET', 'POST' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function test_transient_create_failure_is_ambiguous_without_a_hook_identity(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 503, array( 'message' => 'temporarily unavailable' ) ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'setup_failed_ambiguous', $result->code() );
		self::assertNull( $result->hook_id() );
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_lost_create_response_is_ambiguous_without_a_hook_identity(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				new RepositoryResolverWpError( 'http_request_failed' ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'transport_other', $result->code() );
		self::assertNull( $result->hook_id() );
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_failed_setup_readback_is_compensated_only_after_confirmed_absence(): void {
		$created = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 201, $created ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
				$this->response( 204, array() ),
				$this->response( 404, array() ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'setup_compensated', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( 'absent', $result->to_array()['delivery'] );
		self::assertSame( array( 'GET', 'POST', 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function test_failed_setup_compensation_remains_partial_with_the_known_hook_identity(): void {
		$created = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 201, $created ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
				$this->response( 500, array( 'message' => 'delete failed' ) ),
				$this->response( 200, $created ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_compensation_incomplete', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertFalse( $result->confirms_absence() );
		self::assertSame( array( 'GET', 'POST', 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function test_third_full_discovery_page_stops_before_mutation(): void {
		$page = array();
		for ( $id = 1; $id <= 100; ++$id ) {
			$page[] = $this->hook( $id, 'https://other.example/' . $id );
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $page ), $this->response( 200, $page ), $this->response( 200, $page ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'hook_inventory_incomplete', $result->code() );
		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_setup_does_not_adopt_an_existing_endpoint_with_an_unreadable_secret(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, array( $this->hook( 55, 'https://site.example/hook' ) ) ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'existing_hook_requires_reconfigure', $result->code() );
		self::assertNull( $result->hook_id(), 'An unowned hook ID must not seed a later remove operation.' );
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_remove_requires_absence_readback_within_three_calls(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $hook ), $this->response( 204, array() ), $this->response( 404, array() ) ) );

		$result = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->confirms_absence() );
		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_reconfigure_refuses_a_hook_owned_by_another_endpoint(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://other.example/hook' ) ) ) );

		$result = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'hook_ownership_mismatch', $result->code() );
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_reconfigure_readback_failure_remains_ambiguous_with_the_known_hook_identity(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'reconfigure_readback_unavailable', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( array( 'GET', 'PATCH', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function test_reconfigure_succeeds_only_after_confirmed_readback(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configured_pending_delivery', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( array( 'GET', 'PATCH', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertSame( array( 65536, 65536, 65536 ), array_column( array_column( $requests, 'arguments' ), 'limit_response_size' ) );
		self::assertSame(
			array(
				'name'   => 'web',
				'active' => true,
				'events' => array( 'push' ),
				'config' => array(
					'url'          => 'https://site.example/hook',
					'content_type' => 'json',
					'insecure_ssl' => '0',
					'secret'       => self::SECRET,
				),
			),
			json_decode( $requests[1]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::SECRET, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_remove_absence_readback_failure_remains_ambiguous_with_the_known_hook_identity(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 204, array() ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_readback_unavailable', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( array( 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function test_check_cannot_confirm_an_incomplete_readback(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, array( 'id' => 55 ) ) ) );

		$result = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'hook_readback_invalid', $result->code() );
	}

	public function test_check_succeeds_with_one_bounded_read_of_the_exact_hook(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://site.example/hook' ) ) ) );

		$result   = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configuration_confirmed', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertCount( 1, $requests );
		self::assertSame( 'GET', $requests[0]['arguments']['method'] );
		self::assertSame( 65536, $requests[0]['arguments']['limit_response_size'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_check_and_remove_report_absence_only_when_the_hook_list_is_readable(): void {
		foreach ( array( 'check', 'remove' ) as $operation ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array( $this->response( 404, array() ), $this->response( 200, array( $this->hook( 56, 'https://other.example/hook' ) ) ) )
			);

			$result   = ( new RepositoryWebhookClient() )->{$operation}( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

			self::assertTrue( $result->succeeded(), $operation );
			self::assertSame( 'check' === $operation ? 'hook_absent' : 'absence_confirmed', $result->code(), $operation );
			self::assertTrue( $result->confirms_absence(), $operation );
			self::assertSame( array( 'GET', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ), $operation );
			self::assertStringEndsWith( '/hooks/55', $requests[0]['url'] );
			self::assertStringEndsWith( '/hooks?per_page=1', $requests[1]['url'] );
		}
	}

	/** @return iterable<string, array{mixed, mixed, string, string}> */
	public static function unproven_absence_replies(): iterable {
		yield 'hook list also 404' => array( 404, array(), 'failed', 'repository_not_found_or_no_access' );
		yield 'hook list forbidden' => array( 403, array(), 'failed', 'permission_denied' );
		yield 'hook list rate limited' => array( 429, array(), 'ambiguous', 'rate_limited' );
		yield 'hook list server error' => array( 503, array(), 'ambiguous', '' );
		yield 'hook list transport failure' => array( new RepositoryResolverWpError( 'http_request_failed' ), array(), 'ambiguous', 'transport_other' );
		yield 'hook list is not a list' => array( 200, array( 'message' => 'not a list' ), 'ambiguous', '' );
		yield 'hook list entry lacks an identity' => array( 200, array( array( 'name' => 'web' ) ), 'ambiguous', '' );
		yield 'hook list contradicts the 404' => array( 200, array( array( 'id' => 55 ) ), 'ambiguous', '' );
	}

	#[DataProvider( 'unproven_absence_replies' )]
	public function test_check_and_remove_never_report_success_when_hook_absence_is_unproven( mixed $status_or_error, mixed $body, string $state, string $code ): void {
		foreach ( array( 'check', 'remove' ) as $operation ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array(
					$this->response( 404, array() ),
					is_int( $status_or_error ) ? $this->response( $status_or_error, $body ) : $status_or_error,
				)
			);

			$result   = ( new RepositoryWebhookClient() )->{$operation}( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

			self::assertSame( $state, $result->state(), $operation );
			self::assertSame( '' === $code ? ( 'check' === $operation ? 'hook_readback_unavailable' : 'predelete_read_unavailable' ) : $code, $result->code(), $operation );
			self::assertFalse( $result->succeeded(), $operation );
			self::assertFalse( $result->confirms_absence(), $operation );
			self::assertSame( '55', $result->hook_id(), $operation );
			self::assertSame( array( 'GET', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ), 'No delete is attempted without a readable hook.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
			self::assertStringNotContainsString( self::TOKEN, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
		}
	}

	/** @return iterable<string, array{int|null, array<string,string>, string, string, string}> */
	public static function failure_responses(): iterable {
		yield 'credential rejected' => array( 401, array(), 'failed', 'credential_rejected', 'GitHub rejected the selected credential. Choose a valid credential and try again.' );
		yield 'permission denied' => array( 403, array(), 'failed', 'permission_denied', 'The selected credential cannot manage webhooks on this repository.' );
		yield 'primary rate limit' => array( 403, array( 'x-ratelimit-remaining' => '0' ), 'ambiguous', 'rate_limited', 'GitHub rate limited the request. Wait, then validate; the outcome was not confirmed.' );
		yield 'retry-after seconds' => array( 403, array( 'retry-after' => '60' ), 'ambiguous', 'rate_limited', 'GitHub rate limited the request. Wait, then validate; the outcome was not confirmed.' );
		yield 'retry-after date' => array( 403, array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ), 'ambiguous', 'rate_limited', 'GitHub rate limited the request. Wait, then validate; the outcome was not confirmed.' );
		yield 'too many requests' => array( 429, array(), 'ambiguous', 'rate_limited', 'GitHub rate limited the request. Wait, then validate; the outcome was not confirmed.' );
		yield 'not found or no access' => array( 404, array(), 'failed', 'repository_not_found_or_no_access', 'GitHub could not find the repository, or the credential cannot manage its webhooks.' );
		yield 'bad request' => array( 400, array(), 'failed', 'http_4xx_other', 'GitHub rejected the fixed webhook inputs.' );
		yield 'validation failed' => array( 422, array(), 'failed', 'http_4xx_other', 'GitHub rejected the fixed webhook inputs.' );
		yield 'transport failure' => array( null, array(), 'ambiguous', 'transport_other', 'GitHub could not be reached; the outcome is unknown.' );
		yield 'server error keeps the ambiguous fallback' => array( 503, array(), 'ambiguous', '', 'Inspect the provider state before retrying; automatic retry is disabled.' );
	}

	/** @return array<string, array{string, list<mixed>, string, bool}> */
	private function failure_sites(): array {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		return array(
			'set-up inventory'   => array( 'setup', array(), 'hook_inventory_unavailable', false ),
			'set-up create'      => array( 'setup', array( $this->response( 200, array() ) ), 'setup_failed_ambiguous', false ),
			'check read'         => array( 'check', array(), 'hook_readback_unavailable', true ),
			'test read'          => array( 'test', array(), 'hook_readback_unavailable', true ),
			'test ping'          => array( 'test', array( $this->response( 200, $hook ) ), 'ping_request_failed_ambiguous', true ),
			'reconfigure read'   => array( 'reconfigure', array(), 'preconfiguration_read_unavailable', true ),
			'reconfigure update' => array( 'reconfigure', array( $this->response( 200, $hook ) ), 'reconfigure_failed_ambiguous', true ),
			'remove read'        => array( 'remove', array(), 'predelete_read_unavailable', true ),
			'remove delete'      => array( 'remove', array( $this->response( 200, $hook ) ), 'remove_failed_ambiguous', true ),
		);
	}

	/** @param array<string,string> $headers */
	#[DataProvider( 'failure_responses' )]
	public function test_every_failed_request_reports_what_the_response_proves( ?int $status, array $headers, string $state, string $code, string $remediation ): void {
		foreach ( $this->failure_sites() as $site => [ $operation, $prefix, $fallback, $has_hook ] ) {
			if ( 404 === $status && $has_hook && str_ends_with( $site, 'read' ) ) {
				continue; // A 404 on the hook path itself is the separate absence case.
			}
			$reply = null === $status ? new RepositoryResolverWpError( 'http_request_failed' ) : $this->response( $status, array( 'message' => 'upstream-response-canary' ), $headers );
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( ...$prefix, $reply ) );
			$client = new RepositoryWebhookClient();
			$result = match ( $operation ) {
				'setup'       => $client->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET ),
				'check'       => $client->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN ),
				'test'        => $client->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN ),
				'reconfigure' => $client->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET ),
				'remove'      => $client->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN ),
				default       => throw new \LogicException( 'Unknown failure site.' ),
			};
			$evidence = $result->to_array();

			self::assertSame( $state, $result->state(), $site );
			self::assertSame( '' === $code ? $fallback : $code, $result->code(), $site );
			self::assertSame( $remediation, $evidence['remediation'], $site );
			self::assertSame( $has_hook ? '55' : null, $result->hook_id(), $site );
			self::assertCount( count( $prefix ) + 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), $site . ' makes no further request.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
			$encoded = json_encode( $evidence, JSON_THROW_ON_ERROR );
			self::assertStringNotContainsString( self::TOKEN, $encoded, $site );
			self::assertStringNotContainsString( self::SECRET, $encoded, $site );
			self::assertStringNotContainsString( 'upstream-response-canary', $encoded, $site );
		}
	}

	public function test_a_403_without_rate_limit_signals_is_an_access_refusal_even_with_unrelated_headers(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					403,
					array(),
					array(
						'x-ratelimit-remaining' => '4999',
						'retry-after'           => 'soon',
						'x-github-request-id'   => 'abc',
					)
				),
			)
		);

		$result = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'permission_denied', $result->code() );
	}

	public function test_ping_acceptance_does_not_use_provider_delivery_history_as_signing_proof(): void {
		$hook     = $this->hook( 55, 'https://site.example/hook' );
		$baseline = array(
			array(
				'id'          => 10,
				'event'       => 'push',
				'status_code' => 200,
			),
		);
		$delivery = array(
			array(
				'id'          => 11,
				'event'       => 'ping',
				'status_code' => 204,
			),
			$baseline[0],
		);
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $baseline ),
				$this->response( 204, array() ),
				$this->response( 200, $delivery ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
		self::assertSame( array( 'GET', 'POST' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertStringContainsString( '/hooks/55/pings', $requests[1]['url'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_ping_acceptance_without_a_new_delivery_does_not_claim_verification(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
	}

	public function test_ping_acceptance_remains_unverified_when_provider_history_contains_ping_deliveries(): void {
		$hook                        = $this->hook( 55, 'https://site.example/hook' );
		$old_ping                    = array(
			'id'          => 10,
			'event'       => 'ping',
			'status_code' => 200,
		);
		$new_pending                 = array(
			'id'          => 11,
			'event'       => 'ping',
			'status_code' => null,
		);
		$new_complete                = $new_pending;
		$new_complete['status_code'] = 204;
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array( $old_ping ) ),
				$this->response( 204, array() ),
				$this->response( 200, array( $new_pending, $old_ping ) ),
				$this->response( 200, array( $new_complete, $old_ping ) ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
	}

	public function test_ping_acceptance_remains_unverified_when_provider_history_contains_failed_deliveries(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response(
					200,
					array(
						array(
							'id'          => 11,
							'event'       => 'ping',
							'status_code' => 401,
						),
					)
				),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
	}

	public function test_ping_acceptance_remains_unverified_when_provider_history_contains_redirected_deliveries(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response(
					200,
					array(
						array(
							'id'          => 11,
							'event'       => 'ping',
							'status_code' => 302,
						),
					)
				),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
	}

	public function test_ping_refuses_a_mismatched_recorded_hook_before_any_ping_request(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://other.example/hook' ) ) ) );

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'hook_ownership_mismatch', $result->code() );
		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return array<string,mixed> */
	private function hook( int $id, string $url ): array {
		return array(
			'id'     => $id,
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => $url,
				'content_type' => 'json',
			),
		);
	}

	/**
	 * @param mixed $body
	 * @return array<string,mixed>
	 * @param array<array-key,mixed> $headers
	 */
	private function response( int $status, mixed $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport fixture.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}
