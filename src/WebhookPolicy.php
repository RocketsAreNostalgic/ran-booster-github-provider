<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RuntimeException;

final readonly class WebhookPolicy implements ProviderWebhookPolicy {

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	public function get_retained_headers(): array {
		return array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );
	}

	public function get_signature_header(): string {
		return 'x-hub-signature-256';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		$label        = $this->required_string( $metadata['label'] ?? null, 'Webhook secret label' );
		$scope        = $this->required_string( $metadata['scope'] ?? null, 'Webhook secret scope' );
		$target       = isset( $metadata['target'] ) && is_string( $metadata['target'] )
			? trim( $metadata['target'], " \t\n\r\0\x0B/" )
			: '';
		$secret       = $this->required_secret( $secret );
		$authority_id = isset( $metadata['authority_id'] ) && is_string( $metadata['authority_id'] )
			? trim( $metadata['authority_id'] )
			: '';

		if ( ! in_array( $scope, array( 'owner', 'repository' ), true ) ) {
			throw new RuntimeException( 'Webhook secret scope is not supported by this provider.' );
		}

		if ( 'owner' === $scope && ! $this->is_owner( $target ) ) {
			// Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_TARGET );
		} elseif ( 'repository' === $scope && ! $this->is_repository( $target ) ) {
			// Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_TARGET );
		}
		if ( 'owner' === $scope ) {
			$authority_id = '';
		} elseif ( '' === $authority_id || strlen( $authority_id ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $authority_id ) ) {
			throw new RuntimeException( 'Repository-scoped webhook secrets require a stable repository identity.' );
		}

		return array(
			'label'        => $label,
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authority_id,
			'secret'       => $secret,
		);
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		return null;
	}

	public function authorize_webhook(
		SignedWebhookVerification $verification,
		string $repository_authority_id,
		string $repository
	): bool {
		if ( '' === $repository_authority_id || ! $verification->get_provider()->equals( $this->get_provider() ) ) {
			return false;
		}

		$repository = strtolower( trim( $repository, '/' ) );
		$owner      = explode( '/', $repository, 2 )[0];
		foreach ( $verification->get_profiles() as $profile ) {
			$scope  = strtolower( trim( $profile['scope'] ) );
			$target = strtolower( trim( $profile['target'], " \t\n\r\0\x0B/" ) );
			if ( ( 'owner' === $scope && '' !== $target && $target === $owner )
				|| ( 'repository' === $scope
					&& '' !== $profile['authority_id']
					&& hash_equals( $profile['authority_id'], $repository_authority_id ) )
			) {
				return true;
			}
		}

		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repository_locator, '/' ) );
	}

	private function assert_secret( string $secret ): void {
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 512 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $secret ) ) {
			// Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_SECRET );
		}
	}

	private function required_secret( mixed $secret ): string {
		if ( ! is_string( $secret ) ) {
			// Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_SECRET );
		}

		$this->assert_secret( $secret );

		return $secret;
	}

	private function required_string( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// Provider policy errors are mapped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	private function is_owner( string $owner ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $owner );
	}

	private function is_repository( string $repository ): bool {
		if ( 1 !== substr_count( $repository, '/' ) ) {
			return false;
		}

		list($owner, $name) = explode( '/', $repository, 2 );

		return $this->is_owner( $owner ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $name );
	}
}
