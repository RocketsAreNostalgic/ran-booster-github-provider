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

	public function test_implementation_parameters_preserve_named_arguments_from_core_contracts(): void {
		foreach ( array( GitHubProvider::class, \RAN\BoosterGitHubProvider\V1\WebhookPolicy::class, \RAN\BoosterGitHubProvider\V1\CredentialPolicy::class, \RAN\BoosterGitHubProvider\V1\GitHubReleaseArtifact::class, GitHubWebhookNormalizer::class, \RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget::class, \RAN\BoosterGitHubProvider\V1\Diagnostics::class ) as $class_name ) {
			$class = new ReflectionClass( $class_name );
			foreach ( $class->getInterfaces() as $interface ) {
				foreach ( $interface->getMethods() as $method ) {
					$implementation = $class->getMethod( $method->getName() );
					foreach ( $method->getParameters() as $index => $parameter ) {
						self::assertSame( $parameter->getName(), $implementation->getParameters()[ $index ]->getName(), $class_name . '::' . $method->getName() );
					}
				}
			}
		}
	}

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

			public function credential_profiles(): array {
				++$this->reads;
				return array();
			}

			public function credential_material( ?string $id = null ): ?array {
				unset( $id );
				++$this->reads;
				return null;
			}

			public function has_webhook_profile(): bool {
				++$this->reads;
				return false;
			}
		};
		$delivery_evidence  = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $reads = 0;

			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
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

		$registry->register_with_credential_store(
			'gh',
			static fn ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ): RepositoryProvider => GitHubProvider::create(
				$store,
				$evidence,
				new \stdClass(),
				static fn (): int => $context->maximum_artifact_bytes()
			)
		);
		$registry->seal();

		$provider = $registry->get( 'gh' );
		$metadata = $provider->get_metadata();
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertTrue( $registry->is_sealed() );
		self::assertSame( array( 'gh' ), $requested_stores );
		self::assertSame( array( 'gh' ), $requested_evidence );
		self::assertSame( 0, $credentials->reads );
		self::assertSame( 0, $delivery_evidence->reads );
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'https://github.com/', $metadata->repository_url_base );
		self::assertSame( 'Owner', $metadata->owner_label );
		self::assertSame( 'git-host', $metadata->admin?->navigation?->group );
		self::assertSame( 100, $metadata->admin?->navigation?->slot );
		self::assertSame( 'gh', $registry->administration_metadata()[0]->code->value );

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
			self::assertSame( $provider, $registry->require_capability( 'gh', $capability ) );
		}
		$release_metadata = $registry->require_capability( 'gh', RepositoryReleaseMetadata::class );
		$repository       = new RepositoryReference( 'owner/repository', '42', false, null );
		self::assertSame( 'https://github.com/owner/repository', $release_metadata->expected_update_uri( $repository ) );
		self::assertSame( 'https://github.com/owner/repository/releases/tag/v1.0.0%2Bbuild', $release_metadata->release_details_url( $repository, 'v1.0.0+build' ) );
		self::assertSame( '', $release_metadata->release_details_url( $repository, '' ) );
		self::assertSame( '', $release_metadata->expected_update_uri( new RepositoryReference( 'owner name/repository', '42', false, null ) ) );

		self::assertSame( $provider->get_credential_policy(), $policies->credential_policy( 'gh' ) );
		self::assertSame( $provider->get_webhook_policy(), $policies->webhook_policy( 'gh' ) );
		self::assertSame( array( 'RAN_BOOSTER_GITHUB_TOKEN' ), $policies->credential_policy( 'gh' )->get_constant_names() );
		self::assertSame( 'x-hub-signature-256', $policies->webhook_policy( 'gh' )->get_signature_header() );

		self::assertFalse( class_exists( 'RAN\\BoosterServiceProvider', false ) );
		self::assertFalse( class_exists( 'RAN\\Internal\\CoreContainer', false ) );
	}
}
