<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
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

	/** @return iterable<string, array{string, string, string, string}> */
	public static function classic_scope_headers(): iterable {
		yield 'admin:repo_hook alone' => array( 'admin:repo_hook', 'suitable', 'appropriate', 'classic_scope_sufficient' );
		yield 'admin:repo_hook with unrelated scopes' => array( 'admin:repo_hook, gist', 'suitable', 'appropriate', 'classic_scope_sufficient' );
		yield 'repo alone' => array( 'repo', 'suitable', 'overscoped', 'classic_scope_broad' );
		yield 'repo with unrelated scopes' => array( 'repo, workflow', 'suitable', 'overscoped', 'classic_scope_broad' );
		yield 'repo and admin:repo_hook' => array( 'admin:repo_hook, repo', 'suitable', 'overscoped', 'classic_scope_broad' );
		yield 'scope names are case-insensitive' => array( 'Repo', 'suitable', 'overscoped', 'classic_scope_broad' );
		yield 'write:repo_hook cannot remove a hook' => array( 'write:repo_hook', 'insufficient', 'unknown', 'classic_scope_insufficient' );
		yield 'read:repo_hook' => array( 'read:repo_hook', 'insufficient', 'unknown', 'classic_scope_insufficient' );
		yield 'public_repo is not repo' => array( 'public_repo', 'insufficient', 'unknown', 'classic_scope_insufficient' );
		yield 'unrelated scopes' => array( 'gist, workflow', 'insufficient', 'unknown', 'classic_scope_insufficient' );
	}

	#[DataProvider( 'classic_scope_headers' )]
	public function test_classic_scopes_are_judged_for_the_whole_webhook_lifecycle( string $scopes, string $suitability, string $least_privilege, string $code ): void {
		foreach ( array( 'assess_setup', 'assess_check', 'assess_reconfigure', 'assess_remove', 'assess_test' ) as $method ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, array( 'id' => 101 ), array( 'x-oauth-scopes' => $scopes ) ) ) );

			$evidence = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN )->to_array();

			self::assertSame( $suitability, $evidence['suitability'], $method );
			self::assertSame( $least_privilege, $evidence['least_privilege'], $method );
			self::assertSame( $code, $evidence['code'], $method );
			self::assertSame( 'observed', $evidence['evidence'], $method );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only scope-containment assertion.
			$encoded = json_encode( $evidence, JSON_THROW_ON_ERROR );
			self::assertStringNotContainsString( 'workflow', $encoded, 'Scope values are never echoed.' );
			self::assertStringNotContainsString( 'gist', $encoded, 'Scope values are never echoed.' );
			if ( 'classic_scope_broad' === $code ) {
				self::assertStringContainsString( 'Prefer a fine-grained token', $evidence['remediation'] );
			} else {
				self::assertStringNotContainsString( 'Prefer a fine-grained token', $evidence['remediation'], 'Only repo is labelled over-scoped.' );
			}
		}
	}

	#[DataProvider( 'fitness_actions' )]
	public function test_a_fine_grained_token_is_unknown_and_the_note_names_the_permission( string $method ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, array( 'id' => 101 ) ) ) );

		$evidence = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN )->to_array();

		self::assertSame( 'unknown', $evidence['suitability'] );
		self::assertSame( 'fine_grained_authority_unknown', $evidence['code'] );
		self::assertSame( 'unknown_by_design', $evidence['evidence'] );
		self::assertStringContainsString( 'Webhooks: Read and write', $evidence['remediation'] );
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

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'hook_inventory_incomplete', $result->code() );
		self::assertNull( $result->hook_id() );
		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	/** @return iterable<string, array{string}> */
	public static function invalid_hook_lists(): iterable {
		yield 'hook list is an object' => array( '{"message":"not a list"}' );
		yield 'hook list is an empty object' => array( '{}' );
		yield 'hook list is a sequential numeric-key object' => array( '{"0":{"id":99}}' );
		yield 'hook list entry lacks an identity' => array( '[{"name":"web"}]' );
	}

	#[DataProvider( 'invalid_hook_lists' )]
	public function test_an_invalid_hook_list_stops_set_up_before_any_create_request( string $body ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->raw_response( 200, $body ), $this->response( 503, array() ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'hook_inventory_invalid', $result->code() );
		self::assertSame( 'unknown', $result->to_array()['delivery'] );
		self::assertNull( $result->hook_id() );
		self::assertSame( array( 'GET' ), array_column( array_column( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), 'arguments' ), 'method' ) );
	}

	public function test_several_matching_hooks_stop_set_up_before_any_create_request(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->response( 200, array( $this->hook( 55, 'https://site.example/hook' ), $this->hook( 56, 'https://site.example/hook' ) ) ) )
		);

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'matching_hooks_ambiguous', $result->code() );
		self::assertNull( $result->hook_id() );
		self::assertSame( array( 'GET' ), array_column( array_column( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), 'arguments' ), 'method' ) );
	}

	public function test_a_failed_later_inventory_page_is_a_plain_failure_before_any_create_request(): void {
		$page = array();
		for ( $id = 1; $id <= 100; ++$id ) {
			$page[] = $this->hook( $id, 'https://other.example/' . $id );
		}
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, $page ), new RepositoryResolverWpError( 'http_request_failed' ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'transport_other', $result->code() );
		self::assertSame( 'GitHub could not be reached. Nothing was changed on GitHub.', $result->to_array()['remediation'] );
		self::assertSame( array( 'GET', 'GET' ), array_column( array_column( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), 'arguments' ), 'method' ) );
	}

	public function test_setup_does_not_adopt_an_existing_endpoint_with_an_unreadable_secret(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 200, array( $this->hook( 55, 'https://site.example/hook' ) ) ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'existing_hook_requires_reconfigure', $result->code() );
		self::assertSame( 'A webhook for this site already exists on GitHub. Remove it there, then set up again.', $result->to_array()['remediation'] );
		self::assertStringNotContainsString( 'reconfiguration', $result->to_array()['remediation'] );
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

	/** @return iterable<string, array{string}> */
	public static function absence_hook_lists(): iterable {
		yield 'empty array' => array( '[]' );
		yield 'array with a different hook' => array( '[{"id":56}]' );
	}

	#[DataProvider( 'absence_hook_lists' )]
	public function test_hook_operations_report_absence_when_the_hook_list_proves_it( string $body ): void {
		foreach ( array( 'check', 'test', 'reconfigure', 'remove' ) as $operation ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array( $this->response( 404, array() ), $this->raw_response( 200, $body ) )
			);

			$client   = new RepositoryWebhookClient();
			$result   = 'reconfigure' === $operation
				? $client->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET )
				: $client->{$operation}( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

			$succeeds = in_array( $operation, array( 'check', 'remove' ), true );
			self::assertSame( $succeeds ? 'succeeded' : 'failed', $result->state(), $operation );
			self::assertSame( 'remove' === $operation ? 'absence_confirmed' : 'hook_absent', $result->code(), $operation );
			self::assertSame( 'absent', $result->to_array()['delivery'], $operation );
			self::assertSame( $succeeds, $result->confirms_absence(), $operation );
			self::assertSame( array( 'GET', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ), $operation );
			self::assertStringEndsWith( '/hooks/55', $requests[0]['url'] );
			self::assertStringEndsWith( '/hooks?per_page=1', $requests[1]['url'] );
		}
	}

	/** @return iterable<string, array{int|RepositoryResolverWpError, string, string, string, array<string,string>}> */
	public static function unproven_absence_replies(): iterable {
		yield 'hook list bad request' => array( 400, '{}', 'failed', 'http_4xx_other', array() );
		yield 'hook list unauthorized' => array( 401, '{}', 'failed', 'credential_rejected', array() );
		yield 'hook list also 404' => array( 404, '[]', 'failed', 'repository_not_found_or_no_access', array() );
		yield 'hook list forbidden' => array( 403, '[]', 'failed', 'permission_denied', array() );
		yield 'hook list 403 primary rate limit' => array( 403, '{}', 'ambiguous', 'rate_limited', array( 'x-ratelimit-remaining' => '0' ) );
		yield 'hook list 403 retry-after rate limit' => array( 403, '{}', 'ambiguous', 'rate_limited', array( 'retry-after' => '60' ) );
		yield 'hook list validation failed' => array( 422, '{}', 'failed', 'http_4xx_other', array() );
		yield 'hook list rate limited' => array( 429, '[]', 'ambiguous', 'rate_limited', array() );
		yield 'hook list server error' => array( 503, '[]', 'ambiguous', '', array() );
		yield 'hook list transport failure' => array( new RepositoryResolverWpError( 'http_request_failed' ), '', 'ambiguous', 'transport_other', array() );
		foreach ( self::invalid_hook_lists() as $label => [ $body ] ) {
			yield $label => array( 200, $body, 'ambiguous', '', array() );
		}
		yield 'hook list contradicts the 404' => array( 200, '[{"id":55}]', 'ambiguous', '', array() );
	}

	/** @param array<string,string> $headers */
	#[DataProvider( 'unproven_absence_replies' )]
	public function test_hook_operations_do_not_report_unproven_absence( int|RepositoryResolverWpError $status_or_error, string $body, string $state, string $code, array $headers ): void {
		$fallback_codes = array(
			'test'        => 'hook_readback_unavailable',
			'reconfigure' => 'preconfiguration_read_unavailable',
			'check'       => 'hook_readback_unavailable',
			'remove'      => 'predelete_read_unavailable',
		);
		foreach ( $fallback_codes as $operation => $fallback ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array(
					$this->response( 404, array() ),
					is_int( $status_or_error ) ? $this->raw_response( $status_or_error, $body, $headers ) : $status_or_error,
				)
			);

			$client   = new RepositoryWebhookClient();
			$result   = 'reconfigure' === $operation
				? $client->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET )
				: $client->{$operation}( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
			$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

			self::assertSame( $state, $result->state(), $operation );
			self::assertSame( '' === $code ? $fallback : $code, $result->code(), $operation );
			self::assertFalse( $result->succeeded(), $operation );
			self::assertFalse( $result->confirms_absence(), $operation );
			self::assertSame( 'unknown', $result->to_array()['delivery'], $operation );
			self::assertSame( '55', $result->hook_id(), $operation );
			$remediation = $result->to_array()['remediation'];
			if ( 'remove' === $operation && in_array( $code, array( 'credential_rejected', 'permission_denied', 'repository_not_found_or_no_access' ), true ) ) {
				self::assertStringEndsWith( ' Or remove the webhook by hand on GitHub.', $remediation );
			} else {
				self::assertStringNotContainsString( 'by hand', $remediation, $operation . ' offers no manual removal.' );
			}
			self::assertSame( array( 'GET', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ), $operation );
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

			// A read made before any create request cannot have changed anything, so it is a plain failure.
			$expected_state = 'set-up inventory' === $site && 'ambiguous' === $state ? 'failed' : $state;
			$expected_text  = $remediation;
			if ( 'set-up inventory' === $site ) {
				$expected_text = match ( $code ) {
					'rate_limited'    => 'GitHub rate limited the request. Nothing was changed on GitHub.',
					'transport_other' => 'GitHub could not be reached. Nothing was changed on GitHub.',
					default           => $remediation,
				};
			}
			self::assertSame( $expected_state, $result->state(), $site );
			self::assertSame( '' === $code ? $fallback : $code, $result->code(), $site );
			$by_hand = str_starts_with( $site, 'remove' ) && in_array( $code, array( 'credential_rejected', 'permission_denied', 'repository_not_found_or_no_access' ), true );
			self::assertSame( $expected_text . ( $by_hand ? ' Or remove the webhook by hand on GitHub.' : '' ), $evidence['remediation'], $site );
			self::assertLessThanOrEqual( 255, strlen( $evidence['remediation'] ), $site . ' stays within what Core will show.' );
			self::assertSame( $has_hook ? '55' : null, $result->hook_id(), $site );
			self::assertCount( count( $prefix ) + 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), $site . ' makes no further request.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
			$encoded = json_encode( $evidence, JSON_THROW_ON_ERROR );
			self::assertStringNotContainsString( self::TOKEN, $encoded, $site );
			self::assertStringNotContainsString( self::SECRET, $encoded, $site );
			self::assertStringNotContainsString( 'upstream-response-canary', $encoded, $site );
		}
	}

	/** @return iterable<string, array{int, string}> */
	public static function refused_removals(): iterable {
		yield 'credential rejected' => array( 401, 'credential_rejected' );
		yield 'permission denied' => array( 403, 'permission_denied' );
		yield 'not found or no access' => array( 404, 'repository_not_found_or_no_access' );
	}

	#[DataProvider( 'refused_removals' )]
	public function test_a_refused_delete_tells_the_operator_the_webhook_can_be_removed_by_hand( int $status, string $code ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->response( 200, $this->hook( 55, 'https://site.example/hook' ) ), $this->response( $status, array() ) )
		);

		$result = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( $code, $result->code() );
		self::assertStringEndsWith( ' Or remove the webhook by hand on GitHub.', $result->to_array()['remediation'] );
		self::assertLessThanOrEqual( 255, strlen( $result->to_array()['remediation'] ) );
		self::assertFalse( $result->confirms_absence() );
	}

	/** @return iterable<string, array{int, string}> */
	public static function refused_pre_delete_reads(): iterable {
		// A hook-path 404 is the absence case; its refused proof read is covered by the unproven-absence test.
		yield 'credential rejected' => array( 401, 'credential_rejected' );
		yield 'permission denied' => array( 403, 'permission_denied' );
	}

	#[DataProvider( 'refused_pre_delete_reads' )]
	public function test_a_refused_pre_delete_read_tells_the_operator_the_webhook_can_be_removed_by_hand( int $status, string $code ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( $status, array() ) ) );

		$result   = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( $code, $result->code() );
		self::assertStringEndsWith( ' Or remove the webhook by hand on GitHub.', $result->to_array()['remediation'] );
		self::assertCount( 1, $requests, 'No delete is attempted.' );
	}

	public function test_only_removal_offers_manual_removal(): void {
		foreach ( array( 'check', 'test', 'reconfigure' ) as $operation ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $this->response( 403, array() ) ) );
			$client = new RepositoryWebhookClient();
			$result = 'reconfigure' === $operation
				? $client->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET )
				: $client->{$operation}( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

			self::assertSame( 'permission_denied', $result->code(), $operation );
			self::assertStringNotContainsString( 'by hand', $result->to_array()['remediation'], $operation );
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

	/** @return iterable<string, array{int, array<string,string>, bool, string, string}> */
	public static function browser_rate_limit_parity_cases(): iterable {
		yield '403 padded zero remaining' => array( 403, array( 'x-ratelimit-remaining' => ' 0 ' ), true, 'ambiguous', 'rate_limited' );
		yield '403 retry-after zero' => array( 403, array( 'retry-after' => '0' ), true, 'ambiguous', 'rate_limited' );
		yield '403 invalid http date stays permission denied' => array( 403, array( 'retry-after' => 'Wed, 32 Oct 2026 07:28:00 GMT' ), false, 'failed', 'permission_denied' );
		yield '400 with rate-limit headers stays input rejection' => array(
			400,
			array(
				'x-ratelimit-remaining' => '0',
				'retry-after'           => '60',
			),
			false,
			'failed',
			'http_4xx_other',
		);
		yield '401 with rate-limit headers stays credential rejection' => array(
			401,
			array(
				'x-ratelimit-remaining' => '0',
				'retry-after'           => '60',
			),
			false,
			'failed',
			'credential_rejected',
		);
		yield '422 with rate-limit headers stays input rejection' => array(
			422,
			array(
				'x-ratelimit-remaining' => '0',
				'retry-after'           => '60',
			),
			false,
			'failed',
			'http_4xx_other',
		);
	}

	/** @param array<string,string> $headers */
	#[DataProvider( 'browser_rate_limit_parity_cases' )]
	public function test_webhook_rate_limit_classification_reuses_browser_predicate( int $status, array $headers, bool $rate_limited, string $state, string $code ): void {
		$response = $this->response( $status, array(), $headers );
		self::assertSame( $rate_limited, RepositoryBrowser::is_rate_limited_response( $response, $status ) );

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array( $response ) );
		$result = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( $state, $result->state() );
		self::assertSame( $code, $result->code() );
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport fixture.
		return $this->raw_response( $status, json_encode( $body, JSON_THROW_ON_ERROR ), $headers );
	}

	/**
	 * @param array<array-key,mixed> $headers
	 * @return array<string,mixed>
	 */
	private function raw_response( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}
}
