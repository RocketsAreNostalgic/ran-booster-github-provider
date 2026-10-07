<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support;

use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryDescriptor;
use Throwable;

/** Mutable recording browser used only by the diagnostics contract tests. */
final class DiagnosticsBrowserFixture extends RepositoryBrowser {

	public CredentialValidationResult $credential_result;
	public ?Throwable $credential_exception = null;
	public ?Throwable $repository_exception = null;

	/** @var list<array{string, float}> */
	public array $credential_calls = array();

	/** @var list<array{string, string|null, float|int, int|null}> */
	public array $repository_calls = array();

	public function __construct() {
		$this->credential_result = CredentialValidationResult::valid();
	}

	public function validate_credential( string $credential_id, float $timeout = 15.0 ): CredentialValidationResult {
		$this->credential_calls[] = array( $credential_id, $timeout );
		if ( null !== $this->credential_exception ) {
			throw $this->credential_exception;
		}

		return $this->credential_result;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- Test override preserves the repository-client signature while recording only asserted inputs.
	public function repository(
		string $full_name,
		?string $credential_id = null,
		float|int $timeout = 15,
		?int $response_size = null,
		bool $authenticate_default = false
	): RepositoryDescriptor {
		$this->repository_calls[] = array( $full_name, $credential_id, $timeout, $response_size );
		if ( null !== $this->repository_exception ) {
			throw $this->repository_exception;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$full_name,
			'ran-booster',
			'987654321',
			false,
			'main',
			$credential_id
		);
	}
}
