<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubProviderMetadataTest extends TestCase {

	public function test_git_hub_owns_its_credential_vocabulary(): void {
		$admin = $this->provider()->get_metadata()->admin;

		self::assertNotNull( $admin );
		$classic      = $admin->get_credential_kind( 'classic' );
		$fine_grained = $admin->get_credential_kind( 'fine-grained' );

		self::assertNotNull( $classic );
		self::assertSame( 'Classic personal access token', $classic->label );
		self::assertSame( 'Classic PAT', $classic->short_label );
		self::assertNotNull( $fine_grained );
		self::assertSame( 'Fine-grained personal access token', $fine_grained->label );
		self::assertSame( 'Fine-grained PAT', $fine_grained->short_label );
	}

	public function test_passive_compatibility_baseline_matches_actual_aggregate_facets(): void {
		$contract = GitHubProvider::compatibility_contract();
		$provider = $this->provider();
		$actual   = array_values( array_diff( class_implements( $provider ), array( \RAN\RepositoryProvider\RepositoryProvider::class, \RAN\Provider\ProviderCapability::class ) ) );
		$declared = $contract['facets'];
		sort( $actual, SORT_STRING );
		sort( $declared, SORT_STRING );
		self::assertSame( $actual, $declared );
		self::assertSame( 'ran/booster-github-provider', $contract['implementation_id'] );
		self::assertSame( '1', $contract['compatibility_revision'] );
		self::assertSame( 15, $contract['provider_api_version'] );
		self::assertSame( array( 'github-classic-pat-v1', 'github-fine-grained-pat-v1' ), $contract['api_credential_formats'] );
		self::assertSame( 'github-hmac-sha256-v1', $contract['webhook_signing_format'] );
		self::assertSame( array( 'implementation_id', 'compatibility_revision', 'provider_api_version', 'api_credential_formats', 'webhook_signing_format', 'facets' ), array_keys( $contract ) );
	}

	private function provider(): GitHubProvider {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);

		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}
}
