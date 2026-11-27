<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use LogicException;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;
use RuntimeException;

/**
 * Explicit typed boundary for optional Booster Core contract fields and factory arguments.
 *
 * Certified Core revisions predate some optional carrier and diagnostic parameters while
 * newer Core revisions accept them. Feature checks use class_exists/property_exists and
 * native PHP variadic invocation instead of runtime Reflection shims.
 *
 * @internal
 */
final class CoreContractBridge {

	public static function supports_failure_carrier(): bool {
		return class_exists( ProviderFailure::class ) && class_exists( ProviderRequestFailed::class );
	}

	public static function request_failed(
		string $reason,
		?int $http_status = null,
		?int $retry_after = null,
		?int $reset_at = null,
		?string $credential_expires_at = null,
		#[\SensitiveParameter] ?string $source_code = null
	): ?RuntimeException {
		if ( ! class_exists( ProviderFailure::class ) || ! class_exists( ProviderRequestFailed::class ) ) {
			return null;
		}

		$failure = self::build_provider_failure(
			$reason,
			$http_status,
			$retry_after,
			$reset_at,
			$credential_expires_at,
			$source_code
		);

		if ( null === $failure ) {
			return null;
		}

		$exception = self::instantiate_object( ProviderRequestFailed::class, array( $failure ) );

		return $exception instanceof RuntimeException ? $exception : null;
	}

	/**
	 * @param 'invalid'|'unavailable'|'rate_limited'|'invalid_response' $factory
	 */
	public static function credential_validation_failure(
		string $factory,
		string $reason,
		?int $http_status = null,
		?int $retry_after = null,
		?int $reset_at = null,
		?string $credential_expires_at = null,
		#[\SensitiveParameter] ?string $source_code = null
	): CredentialValidationResult {
		if ( ! class_exists( ProviderFailure::class ) || ! class_exists( ProviderRequestFailed::class ) ) {
			return CredentialValidationResult::$factory();
		}

		$failure = self::build_provider_failure(
			$reason,
			$http_status,
			$retry_after,
			$reset_at,
			$credential_expires_at,
			$source_code
		);
		if ( null === $failure ) {
			return CredentialValidationResult::$factory();
		}

		$result = self::call_static( CredentialValidationResult::class, $factory, array( $failure ) );
		if ( ! $result instanceof CredentialValidationResult ) {
			throw new LogicException( 'The credential-validation factory is incompatible.' );
		}

		return $result;
	}

	/**
	 * @param class-string            $dto
	 * @param array<array-key, mixed> $facts
	 * @return list<string>
	 */
	public static function artifact_arguments( string $dto, array $facts ): array {
		if ( ! property_exists( $dto, 'artifact_identity' ) || ! property_exists( $dto, 'artifact_sha256' ) ) {
			return array();
		}

		$identity = $facts['artifact_identity'] ?? '';
		$sha256   = $facts['artifact_sha256'] ?? '';
		if ( ! is_string( $identity ) || ! is_string( $sha256 )
			|| ( '' !== $sha256 && 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) ) ) {
			throw new RuntimeException( 'GitHub returned invalid release artifact evidence.', 502 );
		}

		return array( $identity, $sha256 );
	}

	/**
	 * @param list<RepositoryReleaseCandidate> $candidates
	 */
	public static function release_candidate_list(
		array $candidates,
		bool $has_observations,
		mixed $search_exhausted = null,
		mixed $diagnostics = null
	): RepositoryReleaseCandidateList {
		if ( $has_observations
			&& property_exists( RepositoryReleaseCandidateList::class, 'search_exhausted' )
			&& property_exists( RepositoryReleaseCandidateList::class, 'diagnostics' )
		) {
			return self::instantiate(
				RepositoryReleaseCandidateList::class,
				array(
					'candidates'       => $candidates,
					'search_exhausted' => $search_exhausted,
					'diagnostics'      => $diagnostics,
				)
			);
		}

		return new RepositoryReleaseCandidateList( $candidates );
	}

	public static function release_read_unavailable(
		string $message,
		int $code,
		?string $reason = null,
		?int $retry_after = null,
		#[\SensitiveParameter] ?string $source_code = null
	): RepositoryReleaseReadUnavailable {
		if ( null !== $reason
			&& class_exists( ProviderFailure::class )
			&& property_exists( RepositoryReleaseReadUnavailable::class, 'failure' )
		) {
			$failure = self::build_provider_failure(
				$reason,
				retry_after: $retry_after,
				source_code: $source_code
			);
			if ( null !== $failure ) {
				return self::instantiate(
					RepositoryReleaseReadUnavailable::class,
					array( $message, $code, null, $failure )
				);
			}
		}

		return new RepositoryReleaseReadUnavailable( $message, $code );
	}

	public static function filesystem_unsupported( string $message ): RuntimeException {
		if ( class_exists( ProviderFailure::class ) && property_exists( RepositoryReleaseReadUnavailable::class, 'failure' ) ) {
			return self::release_read_unavailable( $message, 501, 'site_filesystem_unsupported' );
		}

		return new RuntimeException( $message, 501 );
	}

	public static function webhook_probe( ?string $repository_id ): WebhookEnvelope {
		$probe = self::call_static( WebhookEnvelope::class, 'probe', array( $repository_id ) );

		return $probe instanceof WebhookEnvelope ? $probe : WebhookEnvelope::probe();
	}

	public static function webhook_ignored( ?string $reason ): WebhookEnvelope {
		$envelope = self::call_static( WebhookEnvelope::class, 'ignored', array( $reason ) );

		return $envelope instanceof WebhookEnvelope ? $envelope : WebhookEnvelope::ignored();
	}

	public static function webhook_rejected( int $status_code, string $message, ?string $reason = null ): WebhookRejected {
		if ( null === $reason ) {
			return new WebhookRejected( $status_code, $message );
		}

		return self::instantiate(
			WebhookRejected::class,
			array( $status_code, $message, $reason )
		);
	}

	private static function build_provider_failure(
		string $reason,
		?int $http_status = null,
		?int $retry_after = null,
		?int $reset_at = null,
		?string $credential_expires_at = null,
		#[\SensitiveParameter] ?string $source_code = null
	): ?object {
		if ( ! class_exists( ProviderFailure::class ) ) {
			return null;
		}

		if ( property_exists( ProviderFailure::class, 'source_code' ) ) {
			return self::instantiate(
				ProviderFailure::class,
				array(
					'reason'                => $reason,
					'http_status'           => $http_status,
					'retry_after'           => $retry_after,
					'reset_at'              => $reset_at,
					'credential_expires_at' => $credential_expires_at,
					'source_code'           => $source_code,
				)
			);
		}

		return new ProviderFailure( $reason, $http_status, $retry_after, $reset_at, $credential_expires_at );
	}

	/**
	 * @template T of object
	 * @param class-string<T>         $class_name
	 * @param array<array-key, mixed> $arguments
	 * @return T
	 */
	private static function instantiate( string $class_name, array $arguments ): object {
		return new $class_name( ...$arguments );
	}

	/**
	 * @param class-string            $class_name
	 * @param array<array-key, mixed> $arguments
	 */
	private static function instantiate_object( string $class_name, array $arguments ): object {
		return new $class_name( ...$arguments );
	}

	/**
	 * @param class-string $class_name
	 * @param list<mixed>  $arguments
	 */
	private static function call_static( string $class_name, string $method, array $arguments ): mixed {
		/** @var callable $callable */
		$callable = array( $class_name, $method );

		return $callable( ...$arguments );
	}
}
