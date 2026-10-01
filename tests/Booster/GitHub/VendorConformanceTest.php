<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookNormalizer;
use ReflectionClass;
use ReflectionMethod;

final class VendorConformanceTest extends TestCase {

	public function test_module_composition_uses_only_documented_provider_inputs(): void {
		$browser_parameter   = ( new ReflectionClass( GitHubRepositoryBrowser::class ) )
			->getConstructor()?->getParameters()[0] ?? null;
		$provider_reflection = new ReflectionClass( GitHubProvider::class );
		$composition_method  = $provider_reflection->getMethod( 'create' );
		$provider_parameters = $composition_method->getParameters();
		$webhook_parameter   = ( new ReflectionClass( GitHubWebhookNormalizer::class ) )
			->getConstructor()?->getParameters()[0] ?? null;

		self::assertNotNull( $browser_parameter );
		self::assertCount( 4, $provider_parameters );
		self::assertNotNull( $webhook_parameter );
		self::assertSame( ProviderCredentialStore::class, (string) $browser_parameter->getType() );
		self::assertSame( ProviderCredentialStore::class, (string) $provider_parameters[0]->getType() );
		self::assertSame( AuthenticatedWebhookDeliveryEvidenceReader::class, (string) $provider_parameters[1]->getType() );
		self::assertSame( 'object', (string) $provider_parameters[2]->getType() );
		self::assertSame( '?callable', (string) $provider_parameters[3]->getType() );
		self::assertTrue( $provider_parameters[3]->isOptional() );
		self::assertNull( $provider_parameters[3]->getDefaultValue() );
		self::assertSame( RepositoryProvider::class, (string) $composition_method->getReturnType() );
		self::assertTrue( $composition_method->isPublic() );
		self::assertTrue( $composition_method->isStatic() );
		self::assertTrue( $provider_reflection->getConstructor()?->isPrivate() );
		self::assertSame(
			array( 'create' ),
			array_values(
				array_map(
					static fn ( ReflectionMethod $method ): string => $method->getName(),
					array_filter(
						$provider_reflection->getMethods( ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC ),
						static fn ( ReflectionMethod $method ): bool => $method->isPublic()
							&& $method->isStatic()
							&& GitHubProvider::class === $method->getDeclaringClass()->getName()
					)
				)
			)
		);
		self::assertSame( ProviderWebhookProfileReader::class, (string) $webhook_parameter->getType() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ordinary_provider_api_registration_publishes_the_complete_git_hub_contract_without_core_composition(): void {
		self::assertFalse( class_exists( 'RAN\\BoosterServiceProvider', false ) );
		self::assertFalse( class_exists( 'RAN\\Internal\\CoreContainer', false ) );

		$credentials        = new class() implements ProviderCredentialStore {
			public int $reads = 0;

			// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Test double preserves the Core or production override contract pending coordinated naming.
			public function credentialProfiles(): array {
				++$this->reads;
				return array();
			}

			// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Test double preserves the Core or production override contract pending coordinated naming.
			public function credentialMaterial( ?string $id = null ): ?array {
				unset( $id );
				++$this->reads;
				return null;
			}

			// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Test double preserves the Core or production override contract pending coordinated naming.
			public function hasWebhookProfile(): bool {
				++$this->reads;
				return false;
			}
		};
		$delivery_evidence  = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $reads = 0;

			// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Test double preserves the Core or production override contract pending coordinated naming.
			public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
				++$this->reads;
				return null;
			}
		};
		$policies           = new ProviderSecretPolicyCatalog();
		$requested_stores   = array();
		$requested_evidence = array();
		$registry           = new ProviderRegistry(
			array(),
			$policies,
			static function ( ProviderCode $code ) use ( $credentials, &$requested_stores ): ProviderCredentialStore {
				$requested_stores[] = $code->value;
				return $credentials;
			},
			static function ( ProviderCode $code ) use ( $delivery_evidence, &$requested_evidence ): AuthenticatedWebhookDeliveryEvidenceReader {
				$requested_evidence[] = $code->value;
				return $delivery_evidence;
			},
			new ProviderRegistrationContext( static fn (): int => 52_428_800 )
		);

		$registry->registerWithCredentialStore(
			'gh',
			static fn ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ): RepositoryProvider => GitHubProvider::create(
				$store,
				$evidence,
				new \stdClass(),
				static fn (): int => $context->maximumArtifactBytes()
			)
		);
		$registry->seal();

		$provider = $registry->get( 'gh' );
		$metadata = $provider->getMetadata();
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertTrue( $registry->isSealed() );
		self::assertSame( array( 'gh' ), $requested_stores );
		self::assertSame( array( 'gh' ), $requested_evidence );
		self::assertSame( 0, $credentials->reads );
		self::assertSame( 0, $delivery_evidence->reads );
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Assert the unchanged external Core DTO property contract.
		self::assertSame( 'https://github.com/', $metadata->repositoryUrlBase );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Assert the unchanged external Core DTO property contract.
		self::assertSame( 'Owner', $metadata->ownerLabel );
		self::assertSame( 'git-host', $metadata->admin?->navigation?->group );
		self::assertSame( 100, $metadata->admin?->navigation?->slot );
		self::assertSame( 'gh', $registry->administrationMetadata()[0]->code->value );

		foreach (
			array(
				CredentialValidator::class,
				RepositoryBrowser::class,
				CredentialedPublicRepositoryBrowser::class,
				ProviderCredentialPolicySupplier::class,
				WebhookNormalizer::class,
				RepositoryWebhookSettingsLink::class,
				RepositoryWebhookFitness::class,
				RepositoryWebhookManagement::class,
				RepositoryReleaseAcquirer::class,
				RepositoryReleaseCandidateListing::class,
				RepositoryReleaseInspector::class,
				RepositoryReleaseMetadata::class,
				RepositoryReleaseNativeTargets::class,
			) as $capability
		) {
			self::assertTrue( is_a( $capability, ProviderCapability::class, true ) );
			self::assertSame( $provider, $registry->requireCapability( 'gh', $capability ) );
		}
		$release_metadata = $registry->requireCapability( 'gh', RepositoryReleaseMetadata::class );
		$repository       = new RepositoryReference( 'owner/repository', '42', false, null );
		self::assertSame( 'https://github.com/owner/repository', $release_metadata->expectedUpdateUri( $repository ) );
		self::assertSame( 'https://github.com/owner/repository/releases/tag/v1.0.0%2Bbuild', $release_metadata->releaseDetailsUrl( $repository, 'v1.0.0+build' ) );
		self::assertSame( '', $release_metadata->releaseDetailsUrl( $repository, '' ) );
		self::assertSame( '', $release_metadata->expectedUpdateUri( new RepositoryReference( 'owner name/repository', '42', false, null ) ) );

		self::assertSame( $provider->getCredentialPolicy(), $policies->credentialPolicy( 'gh' ) );
		self::assertSame( $provider->getWebhookPolicy(), $policies->webhookPolicy( 'gh' ) );
		self::assertSame( array( 'RAN_BOOSTER_GITHUB_TOKEN' ), $policies->credentialPolicy( 'gh' )->getConstantNames() );
		self::assertSame( 'x-hub-signature-256', $policies->webhookPolicy( 'gh' )->getSignatureHeader() );

		self::assertFalse( class_exists( 'RAN\\BoosterServiceProvider', false ) );
		self::assertFalse( class_exists( 'RAN\\Internal\\CoreContainer', false ) );
	}
}
