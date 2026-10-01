<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RuntimeException;

final readonly class WebhookPolicy implements ProviderWebhookPolicy {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function getRetainedHeaders(): array {
		return array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function getSignatureHeader(): string {
		return 'x-hub-signature-256';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function normalizeWebhook( array $metadata, mixed $secret ): array {
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_TARGET );
		} elseif ( 'repository' === $scope && ! $this->is_repository( $target ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function getConstantNames(): array {
		return array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function webhookFromConstants( array $constants ): ?array {
		return null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function authorizeWebhook(
		SignedWebhookVerification $verification,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $repositoryAuthorityId,
		string $repository
	): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( '' === $repositoryAuthorityId || ! $verification->getProvider()->equals( $this->getProvider() ) ) {
			return false;
		}

		$repository = strtolower( trim( $repository, '/' ) );
		$owner      = explode( '/', $repository, 2 )[0];
		foreach ( $verification->getProfiles() as $profile ) {
			$scope  = strtolower( trim( $profile['scope'] ) );
			$target = strtolower( trim( $profile['target'], " \t\n\r\0\x0B/" ) );
			if ( ( 'owner' === $scope && '' !== $target && $target === $owner )
				|| ( 'repository' === $scope
					&& '' !== $profile['authority_id']
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
					&& hash_equals( $profile['authority_id'], $repositoryAuthorityId ) )
			) {
				return true;
			}
		}

		return false;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort. Preserve public named-parameter compatibility pending the contract cohort.
	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repositoryLocator, '/' ) );
	}

	private function assert_secret( string $secret ): void {
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 512 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $secret ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_SECRET );
		}
	}

	private function required_secret( mixed $secret ): string {
		if ( ! is_string( $secret ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
			throw new InvalidWebhookInput( InvalidWebhookInput::INVALID_SECRET );
		}

		$this->assert_secret( $secret );

		return $secret;
	}

	private function required_string( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Provider policy errors are mapped at the admin boundary.
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
