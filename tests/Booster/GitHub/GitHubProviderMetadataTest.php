<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubProviderMetadataTest extends TestCase {

	public function test_git_hub_owns_its_credential_vocabulary(): void {
		$admin = $this->provider()->get_metadata()->admin;

		self::assertNotNull( $admin );
		$classic      = $admin->getCredentialKind( 'classic' );
		$fine_grained = $admin->getCredentialKind( 'fine-grained' );

		self::assertNotNull( $classic );
		self::assertSame( 'Classic personal access token', $classic->label );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Assert the unchanged external Core DTO property contract.
		self::assertSame( 'Classic PAT', $classic->shortLabel );
		self::assertNotNull( $fine_grained );
		self::assertSame( 'Fine-grained personal access token', $fine_grained->label );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Assert the unchanged external Core DTO property contract.
		self::assertSame( 'Fine-grained PAT', $fine_grained->shortLabel );
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
