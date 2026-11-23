<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\RepositoryWebhookClient;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class InputValidationHardeningTest extends TestCase {

	private const VALID_SHA = '0123456789abcdef0123456789abcdef01234567';
	private const SECRET    = 'github-webhook-policy-secret-value-0001';

	public function test_webhook_and_credential_policies_reject_newlines_and_dot_segments(): void {
		$webhook_policy = new WebhookPolicy();
		foreach ( array(
			"octocat\n/hello-world",
			"octocat/hello-world\n",
			"octocat/hello-world\r",
			'octocat/.',
			'octocat/..',
			'./hello-world',
			'../hello-world',
		) as $invalid_repository ) {
			$caught = null;
			try {
				$webhook_policy->normalize_webhook(
					array(
						'label'        => 'Webhook',
						'scope'        => 'repository',
						'target'       => $invalid_repository,
						'authority_id' => '101',
					),
					self::SECRET
				);
			} catch ( InvalidWebhookInput $exception ) {
				$caught = $exception;
			}
			self::assertInstanceOf( InvalidWebhookInput::class, $caught, 'Expected InvalidWebhookInput for: ' . $invalid_repository );
			self::assertSame( InvalidWebhookInput::INVALID_TARGET, $caught->reason );
		}

		$credential_policy = new CredentialPolicy();
		foreach ( array( "octocat\n", "octo\ncat", '.', '..' ) as $invalid_owner ) {
			$caught = null;
			try {
				$credential_policy->normalize_credential(
					array(
						'label'         => 'Fine-grained',
						'kind'          => 'fine-grained',
						'configuration' => array( 'owner' => $invalid_owner ),
					),
					'github_pat_' . str_repeat( 'a', 40 )
				);
			} catch ( InvalidCredentialInput $exception ) {
				$caught = $exception;
			}
			self::assertInstanceOf( InvalidCredentialInput::class, $caught, 'Expected InvalidCredentialInput for owner: ' . $invalid_owner );
			self::assertSame( InvalidCredentialInput::INVALID_CONFIGURATION, $caught->reason );
		}
	}

	public function test_expected_update_uri_settings_url_and_webhook_client_reject_dot_segments_and_newlines(): void {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		foreach ( array(
			'./repo',
			'../repo',
			'octocat/.',
			'octocat/..',
		) as $invalid_locator ) {
			self::assertSame(
				'',
				$provider->expected_update_uri( new RepositoryReference( $invalid_locator, '101', false, null ) ),
				$invalid_locator
			);
			$settings_error = null;
			try {
				$provider->repository_webhook_settings_url( $invalid_locator );
			} catch ( RuntimeException $exception ) {
				$settings_error = $exception;
			}
			self::assertInstanceOf( RuntimeException::class, $settings_error, $invalid_locator );
			self::assertSame( 'GitHub repository names must use the owner/repository form.', $settings_error->getMessage() );
		}
		foreach ( array( "octocat\n/repo", "octocat/repo\n" ) as $newline_locator ) {
			$reference = ( new \ReflectionClass( RepositoryReference::class ) )->newInstanceWithoutConstructor();
			$property  = new \ReflectionProperty( RepositoryReference::class, 'locator' );
			$property->setValue( $reference, $newline_locator );
			self::assertSame( '', $provider->expected_update_uri( $reference ), $newline_locator );

			$settings_error = null;
			try {
				$provider->repository_webhook_settings_url( $newline_locator );
			} catch ( RuntimeException $exception ) {
				$settings_error = $exception;
			}
			self::assertInstanceOf( RuntimeException::class, $settings_error, $newline_locator );
			self::assertSame( 'GitHub repository names must use the owner/repository form.', $settings_error->getMessage() );
		}

		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array() );
		$client = new RepositoryWebhookClient();
		foreach ( array( './repo', '../repo', 'octocat/.', 'octocat/..', "octocat\n/repo", "octocat/repo\n" ) as $invalid_repository ) {
			$client_error = null;
			try {
				$client->assess_setup( '101', $invalid_repository, 'token-value' );
			} catch ( RuntimeException $exception ) {
				$client_error = $exception;
			}
			self::assertInstanceOf( RuntimeException::class, $client_error, 'Expected invalid webhook client repository to fail: ' . $invalid_repository );
			self::assertSame( 'The GitHub repository identity is invalid.', $client_error->getMessage() );
			self::assertSame( 400, $client_error->getCode() );
		}
		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_commit_sha_validators_reject_trailing_newline(): void {
		$sha_with_newline = self::VALID_SHA . "\n";
		$provider         = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		try {
			$provider->prepare_archive(
				new ArchiveRequest(
					new RepositoryReference( 'octocat/hello-world', '101', false, null ),
					$sha_with_newline,
					'main'
				)
			);
			self::fail( 'Expected trailing newline in commit SHA to be rejected by prepare_archive().' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The GitHub deployment event does not contain a valid commit.', $exception->getMessage() );
		}

		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );
		try {
			$browser->path_exists( 'octocat/hello-world', $sha_with_newline, 'src' );
			self::fail( 'Expected trailing newline in commit SHA to be rejected by path_exists().' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The GitHub repository path check is invalid.', $exception->getMessage() );
		}
	}
}
