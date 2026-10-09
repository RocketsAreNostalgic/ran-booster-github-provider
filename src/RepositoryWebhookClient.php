<?php
declare(strict_types=1);
namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RuntimeException;
/** Fixed-origin GitHub client for repository-webhook-management/3. */
final class RepositoryWebhookClient {
	private const ORIGIN          = 'https://api.github.com';
	private const CALL_TIMEOUT    = 8.0;
	private const TOTAL_TIMEOUT   = 25.0;
	private const LIST_PAGE_BYTES = 262144;
	private const READ_BYTES      = 65536;
	public function assess_setup( string $repository_id, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repository_id, $repository, $token, 'setup' );
	}
	public function assess_check( string $repository_id, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repository_id, $repository, $token, 'check' );
	}
	public function assess_reconfigure( string $repository_id, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repository_id, $repository, $token, 'reconfigure' );
	}
	public function assess_remove( string $repository_id, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repository_id, $repository, $token, 'remove' );
	}
	public function assess_test( string $repository_id, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repository_id, $repository, $token, 'test' );
	}
	public function setup( string $repository, string $callback_url, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret ): RepositoryWebhookOperationResult {
		$deadline = microtime( true ) + self::TOTAL_TIMEOUT;
		$matches  = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$response = $this->request( 'GET', $this->hooks_path( $repository ) . '?per_page=100&page=' . $page, $token, null, self::LIST_PAGE_BYTES, $deadline );
			if ( 200 !== $response['status'] ) {
				return $this->uncertain( 'hook_inventory_unavailable' );
			}
			$hooks = $this->decode_list( $response['body'] );
			if ( null === $hooks ) {
				return $this->uncertain( 'hook_inventory_invalid' );
			}
			foreach ( $hooks as $hook ) {
				$configuration = $this->configuration( $hook, $callback_url );
				if ( 'matched' === $configuration['endpoint'] ) {
					$matches[] = array( $hook, $configuration );
				}
			}
			if ( count( $hooks ) < 100 ) {
				break;
			}
			if ( 3 === $page ) {
				return $this->uncertain( 'hook_inventory_incomplete' );
			}
		}
		if ( 1 < count( $matches ) ) {
			return $this->uncertain( 'matching_hooks_ambiguous' );
		}
		if ( 1 === count( $matches ) ) {
			return $this->result( 'ambiguous', 'existing_hook_requires_reconfigure', null, $matches[0][1], 'unknown', 'An existing endpoint cannot prove the stored signing secret; inspect it and use explicit reconfiguration.' );
		}
		$created = $this->request( 'POST', $this->hooks_path( $repository ), $token, $this->payload( $callback_url, $secret ), self::READ_BYTES, $deadline );
		if ( 201 !== $created['status'] ) {
			return $this->mutation_failure( $created['status'], 'setup_failed' );
		}
		$created_hook = $this->decode_hook( $created['body'] );
		$hook_id      = $this->hook_id( $created_hook );
		if ( null === $hook_id ) {
			return $this->uncertain( 'setup_response_invalid' );
		}
		$readback = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		$hook     = 200 === $readback['status'] ? $this->decode_hook( $readback['body'] ) : null;
		if ( null !== $hook && hash_equals( $hook_id, (string) $this->hook_id( $hook ) ) ) {
			return $this->configured_result( $hook, $this->configuration( $hook, $callback_url ) );
		}
		$deleted = $this->request( 'DELETE', $this->hook_path( $repository, $hook_id ), $token, null, 0, $deadline );
		$absent  = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		if ( 204 === $deleted['status'] && 404 === $absent['status'] ) {
			return $this->result( 'failed', 'setup_compensated', $hook_id, $this->unknown_configuration(), 'absent', 'The unusable remote hook was removed; setup may be tried again.' );
		}
		return $this->result( 'partial', 'setup_compensation_incomplete', $hook_id, $this->unknown_configuration(), 'unknown', 'Inspect the identified remote hook before retrying.' );
	}
	public function check( string $repository, string $hook_id, string $callback_url, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$response = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, microtime( true ) + self::TOTAL_TIMEOUT );
		if ( 404 === $response['status'] ) {
			return $this->result( 'succeeded', 'hook_absent', $hook_id, $this->unknown_configuration(), 'absent', 'Set up a replacement only after reviewing the retained local record.' );
		}
		$hook = 200 === $response['status'] ? $this->decode_hook( $response['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hook_id, (string) $this->hook_id( $hook ) ) ) {
			return $this->uncertain( 'hook_readback_unavailable', $hook_id );
		}
		$configuration = $this->configuration( $hook, $callback_url );
		if ( in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'hook_readback_invalid', $hook_id );
		}
		return $this->result(
			'succeeded',
			in_array( 'mismatched', $configuration, true ) ? 'configuration_drift' : 'configuration_confirmed',
			$hook_id,
			$configuration,
			'unknown',
			in_array( 'mismatched', $configuration, true ) ? 'Reconfigure the identified hook before relying on it.' : 'A correctly signed inbound delivery is still required for verification.'
		);
	}
	public function test( string $repository, string $hook_id, string $callback_url, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$deadline  = microtime( true ) + self::TOTAL_TIMEOUT;
		$hook_read = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		$hook      = 200 === $hook_read['status'] ? $this->decode_hook( $hook_read['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hook_id, (string) $this->hook_id( $hook ) ) ) {
			return 404 === $hook_read['status']
				? $this->result( 'failed', 'hook_absent', $hook_id, $this->unknown_configuration(), 'absent', 'The recorded hook no longer exists.' )
				: $this->uncertain( 'hook_readback_unavailable', $hook_id );
		}
		$configuration = $this->configuration( $hook, $callback_url );
		if ( in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'hook_readback_invalid', $hook_id );
		}
		if ( in_array( 'mismatched', $configuration, true ) ) {
			return $this->result( 'failed', 'hook_ownership_mismatch', $hook_id, $configuration, 'unknown', 'The recorded hook does not match this site. Reconfigure it before testing.' );
		}
		$ping = $this->request( 'POST', $this->hook_path( $repository, $hook_id ) . '/pings', $token, array(), 0, $deadline );
		if ( 200 > $ping['status'] || 300 <= $ping['status'] ) {
			return $this->mutation_failure( $ping['status'], 'ping_request_failed', $hook_id );
		}

		return $this->result( 'succeeded', 'ping_requested', $hook_id, $configuration, 'unknown', 'GitHub accepted the ping request. Only an authenticated inbound delivery can verify the signing secret.' );
	}
	public function reconfigure( string $repository, string $hook_id, string $callback_url, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret ): RepositoryWebhookOperationResult {
		$deadline    = microtime( true ) + self::TOTAL_TIMEOUT;
		$before      = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		$before_hook = 200 === $before['status'] ? $this->decode_hook( $before['body'] ) : null;
		if ( null === $before_hook || ! hash_equals( $hook_id, (string) $this->hook_id( $before_hook ) ) ) {
			return 404 === $before['status']
				? $this->result( 'failed', 'hook_absent', $hook_id, $this->unknown_configuration(), 'absent', 'The recorded hook no longer exists.' )
				: $this->uncertain( 'preconfiguration_read_unavailable', $hook_id );
		}
		if ( 'matched' !== $this->configuration( $before_hook, $callback_url )['endpoint'] ) {
			return 'unknown' === $this->configuration( $before_hook, $callback_url )['endpoint'] ? $this->uncertain( 'hook_ownership_unavailable', $hook_id ) : $this->result( 'failed', 'hook_ownership_mismatch', $hook_id, $this->configuration( $before_hook, $callback_url ), 'unknown', 'Inspect the remote hook; its callback does not match this site.' );
		}
		$updated = $this->request( 'PATCH', $this->hook_path( $repository, $hook_id ), $token, $this->payload( $callback_url, $secret ), self::READ_BYTES, $deadline );
		if ( 200 !== $updated['status'] ) {
			return $this->mutation_failure( $updated['status'], 'reconfigure_failed', $hook_id );
		}
		$readback = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		$hook     = 200 === $readback['status'] ? $this->decode_hook( $readback['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hook_id, (string) $this->hook_id( $hook ) ) ) {
			return $this->uncertain( 'reconfigure_readback_unavailable', $hook_id );
		}
		return $this->configured_result( $hook, $this->configuration( $hook, $callback_url ) );
	}
	public function remove( string $repository, string $hook_id, string $callback_url, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$deadline = microtime( true ) + self::TOTAL_TIMEOUT;
		$before   = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		if ( 404 === $before['status'] ) {
			return $this->result( 'succeeded', 'absence_confirmed', $hook_id, $this->unknown_configuration(), 'absent', 'The remote hook is absent.' );
		}
		$hook = 200 === $before['status'] ? $this->decode_hook( $before['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hook_id, (string) $this->hook_id( $hook ) ) ) {
			return $this->uncertain( 'predelete_read_unavailable', $hook_id );
		}
		if ( 'matched' !== $this->configuration( $hook, $callback_url )['endpoint'] ) {
			return 'unknown' === $this->configuration( $hook, $callback_url )['endpoint'] ? $this->uncertain( 'hook_ownership_unavailable', $hook_id ) : $this->result( 'failed', 'hook_ownership_mismatch', $hook_id, $this->configuration( $hook, $callback_url ), 'unknown', 'Inspect the remote hook; its callback does not match this site.' );
		}
		$deleted = $this->request( 'DELETE', $this->hook_path( $repository, $hook_id ), $token, null, 0, $deadline );
		if ( 204 !== $deleted['status'] ) {
			return $this->mutation_failure( $deleted['status'], 'remove_failed', $hook_id );
		}
		$absent = $this->request( 'GET', $this->hook_path( $repository, $hook_id ), $token, null, self::READ_BYTES, $deadline );
		return 404 === $absent['status']
			? $this->result( 'succeeded', 'absence_confirmed', $hook_id, $this->unknown_configuration(), 'absent', 'The remote hook is absent.' )
			: $this->uncertain( 'remove_readback_unavailable', $hook_id );
	}
	private function assess( string $repository_id, string $repository, string $token, string $action ): RepositoryWebhookFitnessResult {
		$response = $this->request( 'GET', $this->repository_path( $repository ), $token, null, self::READ_BYTES, microtime( true ) + self::TOTAL_TIMEOUT );
		$now      = $this->now();
		if ( 200 !== $response['status'] ) {
			return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'assessment_unavailable', $action . '_assessment_unavailable', $now, 'Confirm repository access and provider policy, then assess again.' );
		}
		$data = json_decode( $response['body'], true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! hash_equals( $repository_id, trim( (string) $data['id'] ) ) ) {
			return new RepositoryWebhookFitnessResult( 'supported', 'insufficient', 'unknown', 'observed', 'repository_identity_mismatch', $now, 'Select the credential for the exact managed repository.' );
		}
		$scopes = wp_remote_retrieve_header( $response['response'], 'x-oauth-scopes' );
		if ( is_string( $scopes ) && '' !== trim( $scopes ) ) {
			$grants = array_map( 'trim', explode( ',', strtolower( $scopes ) ) );
			if ( ! in_array( 'admin:repo_hook', $grants, true ) ) {
				return new RepositoryWebhookFitnessResult( 'supported', 'insufficient', 'unknown', 'observed', 'classic_scope_insufficient', $now, 'Use a credential with repository webhook management permission.' );
			}
			return new RepositoryWebhookFitnessResult( 'supported', 'suitable', 'overscoped', 'observed', 'classic_scope_broad', $now, 'Prefer a fine-grained token restricted to the selected repository.' );
		}
		return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'unknown_by_design', 'fine_grained_authority_unknown', $now, 'Confirm the selected repository and Webhooks write permission before continuing.' );
	}
	/**
	 * @return array{status:int,body:string,response:mixed}
	 * @param array<string,mixed>|null $body
	 */
	private function request( string $method, string $path, string $token, ?array $body, int $limit, float $deadline ): array {
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return array(
				'status'   => 599,
				'body'     => '',
				'response' => array(),
			);
		}
		$arguments = array(
			'method'              => $method,
			'timeout'             => min( self::CALL_TIMEOUT, $remaining ),
			'redirection'         => 0,
			'limit_response_size' => $limit,
			'reject_unsafe_urls'  => true,
			'headers'             => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $token,
				'X-GitHub-Api-Version' => RepositoryBrowser::API_VERSION,
				'User-Agent'           => 'RAN-Booster',
			),
		);
		if ( null !== $body ) {
			$arguments['headers']['Content-Type'] = 'application/json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed bounded provider payload; JSON exceptions fail closed.
			$arguments['body'] = json_encode( $body, JSON_THROW_ON_ERROR );
		}
		$response = wp_remote_request( self::ORIGIN . $path, $arguments );
		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 599,
				'body'     => '',
				'response' => $response,
			);
		}
		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'response' => $response,
		);
	}
	private function hooks_path( string $repository ): string {
		return $this->repository_path( $repository ) . '/hooks';
	}
	private function hook_path( string $repository, string $hook_id ): string {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $hook_id ) ) {
			throw new RuntimeException( 'The GitHub hook identity is invalid.', 400 );
		}
		return $this->hooks_path( $repository ) . '/' . rawurlencode( $hook_id );
	}
	private function repository_path( string $repository ): string {
		$parts = explode( '/', trim( $repository ) );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\z/D', $parts[0] ) || 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\z/D', $parts[1] ) ) {
			throw new RuntimeException( 'The GitHub repository identity is invalid.', 400 );
		}
		return '/repos/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}
	/** @return array<string,mixed>|null */
	private function decode_hook( string $body ): ?array {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		return is_array( $data ) && null !== $this->hook_id( $data ) ? $data : null;
	}
	/** @return list<array<string,mixed>>|null */
	private function decode_list( string $body ): ?array {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return null;
		}
		foreach ( $data as $hook ) {
			if ( ! is_array( $hook ) || null === $this->hook_id( $hook ) ) {
				return null;
			}
		}
		return $data;
	}
	/** @param array<string,mixed>|null $hook */
	private function hook_id( ?array $hook ): ?string {
		$id = $hook['id'] ?? null;
		$id = is_int( $id ) || is_string( $id ) ? trim( (string) $id ) : '';
		return 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) ? $id : null;
	}
	/**
	 * @param array<string,mixed> $hook
	 * @return array{endpoint:string,events:string,content_type:string,active:string}
	 */
	private function configuration( array $hook, string $callback_url ): array {
		$config = is_array( $hook['config'] ?? null ) ? $hook['config'] : array();
		$events = is_array( $hook['events'] ?? null ) ? $hook['events'] : null;
		return array(
			'endpoint'     => is_string( $config['url'] ?? null ) ? ( hash_equals( $callback_url, $config['url'] ) ? 'matched' : 'mismatched' ) : 'unknown',
			'events'       => is_array( $events ) ? ( array( 'push' ) === $events ? 'matched' : 'mismatched' ) : 'unknown',
			'content_type' => is_string( $config['content_type'] ?? null ) ? ( 'json' === $config['content_type'] ? 'matched' : 'mismatched' ) : 'unknown',
			'active'       => is_bool( $hook['active'] ?? null ) ? ( $hook['active'] ? 'matched' : 'mismatched' ) : 'unknown',
		);
	}
	/** @return array<string,mixed> */
	private function payload( string $callback_url, string $secret ): array {
		return array(
			'name'   => 'web',
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => $callback_url,
				'content_type' => 'json',
				'insecure_ssl' => '0',
				'secret'       => $secret,
			),
		);
	}
	/**
	 * @param array<string,mixed> $hook
	 * @param array{endpoint:string,events:string,content_type:string,active:string} $configuration
	 */
	private function configured_result( array $hook, array $configuration ): RepositoryWebhookOperationResult {
		$hook_id = $this->hook_id( $hook );
		if ( null === $hook_id || in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'configuration_readback_invalid', $hook_id );
		}
		if ( in_array( 'mismatched', $configuration, true ) ) {
			return $this->result( 'partial', 'configuration_readback_mismatch', $hook_id, $configuration, 'unknown', 'Inspect the identified remote hook before retrying.' );
		}
		return $this->result( 'succeeded', 'configured_pending_delivery', $hook_id, $configuration, 'configured_pending_delivery', 'Send a correctly signed push delivery before treating the hook as verified.' );
	}
	private function mutation_failure( int $status, string $code, ?string $hook_id = null ): RepositoryWebhookOperationResult {
		return in_array( $status, array( 400, 401, 403, 404, 422 ), true )
			? $this->result( 'failed', $code, $hook_id, $this->unknown_configuration(), 'unknown', 'Review repository access and the fixed operation inputs.' )
			: $this->uncertain( $code . '_ambiguous', $hook_id );
	}
	private function uncertain( string $code, ?string $hook_id = null ): RepositoryWebhookOperationResult {
		return $this->result( 'ambiguous', $code, $hook_id, $this->unknown_configuration(), 'unknown', 'Inspect the provider state before retrying; automatic retry is disabled.' );
	}
	/** @param array{endpoint:string,events:string,content_type:string,active:string} $configuration */
	private function result( string $state, string $code, ?string $hook_id, array $configuration, string $delivery, string $remediation ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult( $state, $code, $this->now(), $hook_id, $configuration, $delivery, $remediation );
	}
	/** @return array{endpoint:string,events:string,content_type:string,active:string} */
	private function unknown_configuration(): array {
		return array(
			'endpoint'     => 'unknown',
			'events'       => 'unknown',
			'content_type' => 'unknown',
			'active'       => 'unknown',
		);
	}
	private function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
