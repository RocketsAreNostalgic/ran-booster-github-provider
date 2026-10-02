<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RuntimeException;

final readonly class Diagnostics implements ProviderDiagnostics {

	public function __construct( private RepositoryBrowser $browser ) {
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array(
			$this->credential_result( $request ),
			$this->repository_result( $request ),
		);
	}

	private function credential_result( ProviderDiagnosticRequest $request ): ProviderDiagnosticResult {
		$credential_id = $request->get_credential_id();
		if ( null === $credential_id ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'gh.credential.not_configured',
				'No GitHub credential was selected.',
				'Select a credential to verify access to private repositories.'
			);
		}

		try {
			$result = $this->browser->validate_credential( $credential_id, $request->claim_remote_call() );
		} catch ( ProviderDiagnosticBudgetExceeded ) {
			return $this->budget_result( 'gh.credential.budget_exhausted' );
		} catch ( \Throwable $exception ) {
			return $this->unavailable_result( 'gh.credential.unavailable', 'GitHub credential validation could not be completed.', $exception );
		}

		if ( $result->is_valid() ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'gh.credential.valid',
				'GitHub accepted the selected credential.',
				'No action is needed.'
			);
		}

		if ( CredentialValidationResult::RATE_LIMITED === $result->reason ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'gh.credential.rate_limited',
				'GitHub rate-limited credential validation.',
				'Try the check again after the rate limit resets.'
			);
		}

		if ( in_array( $result->reason, array( CredentialValidationResult::UNAVAILABLE, CredentialValidationResult::INVALID_RESPONSE ), true ) ) {
			return $this->unavailable_result( 'gh.credential.unavailable', 'GitHub credential validation could not be completed.' );
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'gh.credential.invalid',
			'GitHub did not accept the selected credential.',
			'Check the token, repository access, organisation approval, and expiry.'
		);
	}

	private function repository_result( ProviderDiagnosticRequest $request ): ProviderDiagnosticResult {
		$repository = $request->get_repository();
		if ( null === $repository ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'gh.repository.not_configured',
				'No GitHub repository was selected for the reachability check.',
				'Select a repository to verify its visibility and scope.'
			);
		}

		try {
			$this->browser->repository( $repository, $request->get_credential_id(), $request->claim_remote_call(), 65536 );
		} catch ( ProviderDiagnosticBudgetExceeded ) {
			return $this->budget_result( 'gh.repository.budget_exhausted' );
		} catch ( RuntimeException $exception ) {
			return $this->repository_failure( $exception );
		} catch ( \Throwable $exception ) {
			return $this->unavailable_result( 'gh.repository.unavailable', 'GitHub repository access could not be completed.', $exception );
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'gh.repository.reachable',
			'GitHub returned the selected repository.',
			'No action is needed.'
		);
	}

	private function repository_failure( RuntimeException $exception ): ProviderDiagnosticResult {
		return match ( $exception->getCode() ) {
			401, 403 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'gh.repository.denied',
				'GitHub denied access to the selected repository.',
				'Check the token permissions and organisation approval.'
			),
			404 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'gh.repository.not_found',
				'GitHub could not find the selected repository with this credential.',
				'Check the repository name and credential repository access.'
			),
			429 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'gh.repository.rate_limited',
				'GitHub rate-limited the repository check.',
				'Try the check again after the rate limit resets.'
			),
			default => $this->unavailable_result( 'gh.repository.unavailable', 'GitHub repository access could not be completed.' ),
		};
	}

	private function budget_result( string $code ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			$code,
			'This GitHub check was not run because the diagnostic budget was exhausted.',
			'Run diagnostics again after other provider requests have completed.'
		);
	}

	private function unavailable_result( string $code, string $message, ?\Throwable $failure = null ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			$code,
			$message,
			'Try again and check GitHub service status if the problem continues.',
			$failure
		);
	}
}
