<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\Diagnostics;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;
use Throwable;

final class DiagnosticsTest extends TestCase {

	private const SECRET_CANARY = 'github_pat_diagnostic_canary_secret';

	public function test_missing_selections_return_stable_not_configured_results_without_calling_git_hub(): void {
		$browser = $this->browser();
		$request = new ProviderDiagnosticRequest();

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame(
			array(
				array(
					'status'      => ProviderDiagnosticResult::NOT_CONFIGURED,
					'code'        => 'gh.credential.not_configured',
					'message'     => 'No GitHub credential was selected.',
					'remediation' => 'Select a credential to verify access to private repositories.',
				),
				array(
					'status'      => ProviderDiagnosticResult::NOT_CONFIGURED,
					'code'        => 'gh.repository.not_configured',
					'message'     => 'No GitHub repository was selected for the reachability check.',
					'remediation' => 'Select a repository to verify its visibility and scope.',
				),
			),
			array_map( static fn( ProviderDiagnosticResult $result ): array => $result->to_array(), $results )
		);
		self::assertSame( 0, $request->get_remote_calls() );
		self::assertSame( array(), $browser->credential_calls );
		self::assertSame( array(), $browser->repository_calls );
	}

	/**
	 * @return iterable<string, array{CredentialValidationResult, array{status: string, code: string, message: string, remediation: string}}>
	 */
	public static function credential_results(): iterable {
		yield 'accepted' => array(
			CredentialValidationResult::valid(),
			array(
				'status'      => ProviderDiagnosticResult::PASSED,
				'code'        => 'gh.credential.valid',
				'message'     => 'GitHub accepted the selected credential.',
				'remediation' => 'No action is needed.',
			),
		);
		yield 'rate limited' => array(
			CredentialValidationResult::rate_limited(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.rate_limited',
				'message'     => 'GitHub rate-limited credential validation.',
				'remediation' => 'Try the check again after the rate limit resets.',
			),
		);
		yield 'temporarily unavailable' => array(
			CredentialValidationResult::unavailable(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.unavailable',
				'message'     => 'GitHub credential validation could not be completed.',
				'remediation' => 'Try again and check GitHub service status if the problem continues.',
			),
		);
		yield 'invalid response' => array(
			CredentialValidationResult::invalid_response(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.unavailable',
				'message'     => 'GitHub credential validation could not be completed.',
				'remediation' => 'Try again and check GitHub service status if the problem continues.',
			),
		);
		yield 'rejected' => array(
			CredentialValidationResult::invalid(),
			array(
				'status'      => ProviderDiagnosticResult::FAILED,
				'code'        => 'gh.credential.invalid',
				'message'     => 'GitHub did not accept the selected credential.',
				'remediation' => 'Check the token, repository access, organisation approval, and expiry.',
			),
		);
	}

	#[DataProvider( 'credential_results' )]
	public function test_credential_results_keep_stable_status_code_and_operator_copy(
		CredentialValidationResult $credential_result,
		array $expected
	): void {
		$browser                    = $this->browser();
		$browser->credential_result = $credential_result;
		$request                    = new ProviderDiagnosticRequest(
			'diagnostic-profile',
			clock: static fn(): float => 100.0
		);

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( $expected, $results[0]->to_array() );
		self::assertSame( array( array( 'diagnostic-profile', 10.0 ) ), $browser->credential_calls );
		self::assertSame( 1, $request->get_remote_calls() );
	}

	/**
	 * @return iterable<string, array{int|null, array{status: string, code: string, message: string, remediation: string}}>
	 */
	public static function repository_results(): iterable {
		yield 'reachable' => array(
			null,
			array(
				'status'      => ProviderDiagnosticResult::PASSED,
				'code'        => 'gh.repository.reachable',
				'message'     => 'GitHub returned the selected repository.',
				'remediation' => 'No action is needed.',
			),
		);
		foreach ( array( 401, 403 ) as $code ) {
			yield 'access denied ' . $code => array(
				$code,
				array(
					'status'      => ProviderDiagnosticResult::FAILED,
					'code'        => 'gh.repository.denied',
					'message'     => 'GitHub denied access to the selected repository.',
					'remediation' => 'Check the token permissions and organisation approval.',
				),
			);
		}
		yield 'not found' => array(
			404,
			array(
				'status'      => ProviderDiagnosticResult::FAILED,
				'code'        => 'gh.repository.not_found',
				'message'     => 'GitHub could not find the selected repository with this credential.',
				'remediation' => 'Check the repository name and credential repository access.',
			),
		);
		yield 'rate limited' => array(
			429,
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.repository.rate_limited',
				'message'     => 'GitHub rate-limited the repository check.',
				'remediation' => 'Try the check again after the rate limit resets.',
			),
		);
		foreach ( array( 400, 500, 502 ) as $code ) {
			yield 'unavailable ' . $code => array(
				$code,
				array(
					'status'      => ProviderDiagnosticResult::WARNING,
					'code'        => 'gh.repository.unavailable',
					'message'     => 'GitHub repository access could not be completed.',
					'remediation' => 'Try again and check GitHub service status if the problem continues.',
				),
			);
		}
	}

	#[DataProvider( 'repository_results' )]
	public function test_repository_runtime_results_keep_stable_mapping_without_logging_raw_failure(
		?int $exception_code,
		array $expected
	): void {
		$browser = $this->browser();
		if ( null !== $exception_code ) {
			$browser->repository_exception = new RuntimeException( self::SECRET_CANARY, $exception_code );
		}
		$request = new ProviderDiagnosticRequest(
			null,
			'RocketsAreNostalgic/ran-booster',
			clock: static fn(): float => 100.0
		);

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( $expected, $results[1]->to_array() );
		self::assertSame(
			array( array( 'RocketsAreNostalgic/ran-booster', null, 10.0, 65536 ) ),
			$browser->repository_calls
		);
		self::assertStringNotContainsString( self::SECRET_CANARY, implode( ' ', $results[1]->to_array() ) );
	}

	public function test_remote_call_budget_is_consumed_in_credential_then_repository_order(): void {
		$browser = $this->browser();
		$request = new ProviderDiagnosticRequest( 'diagnostic-profile', 'owner/repository', 1 );

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( 'gh.credential.valid', $results[0]->code );
		self::assertSame(
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.repository.budget_exhausted',
				'message'     => 'This GitHub check was not run because the diagnostic budget was exhausted.',
				'remediation' => 'Run diagnostics again after other provider requests have completed.',
			),
			$results[1]->to_array()
		);
		self::assertSame( 1, $request->get_remote_calls() );
		self::assertSame( 'remote_calls', $request->get_exhaustion_reason() );
		self::assertCount( 1, $browser->credential_calls );
		self::assertSame( array(), $browser->repository_calls );
	}

	public function test_expired_deadline_skips_both_checks_without_calling_git_hub(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			'diagnostic-profile',
			'owner/repository',
			clock: static function () use ( &$now ): float {
				return $now;
			}
		);
		$now     = 111.0;
		$browser = $this->browser();

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( 'gh.credential.budget_exhausted', $results[0]->code );
		self::assertSame( 'gh.repository.budget_exhausted', $results[1]->code );
		self::assertSame( ProviderDiagnosticResult::WARNING, $results[0]->status );
		self::assertSame( ProviderDiagnosticResult::WARNING, $results[1]->status );
		self::assertSame( 0, $request->get_remote_calls() );
		self::assertSame( 'deadline', $request->get_exhaustion_reason() );
		self::assertSame( array(), $browser->credential_calls );
		self::assertSame( array(), $browser->repository_calls );
	}

	private function browser(): RepositoryBrowser {
		return new class() extends RepositoryBrowser {

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
		};
	}
}
