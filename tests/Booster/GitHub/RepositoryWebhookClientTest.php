<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

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

	/** @return iterable<string, array{int}> */
	public static function deterministic_create_failures(): iterable {
		yield 'bad request' => array( 400 );
		yield 'unauthorized' => array( 401 );
		yield 'forbidden' => array( 403 );
		yield 'repository absent' => array( 404 );
		yield 'validation rejected' => array( 422 );
	}

	#[DataProvider( 'deterministic_create_failures' )]
	public function test_deterministic_create_failure_is_failed_without_a_hook_identity( int $status ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( $status, array( 'message' => 'request rejected' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'setup_failed', $result->code() );
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
		self::assertSame( 'setup_failed_ambiguous', $result->code() );
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

	/** @param mixed $body @return array<string,mixed> */
	private function response( int $status, mixed $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport fixture.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}
