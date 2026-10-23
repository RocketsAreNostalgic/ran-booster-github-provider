<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

require_once __DIR__ . '/Support/DocumentationWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubDocumentation;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\GitHubTranslations;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubDocumentationTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle method name is fixed by its parent contract.
	protected function setUp(): void {
		$GLOBALS['ran_booster_github_provider_documentation_hooks']    = array();
		$GLOBALS['ran_booster_github_provider_documentation_catalogs'] = array();
		$GLOBALS['ran_booster_github_provider_documentation_locale']   = 'en_US';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle method name is fixed by its parent contract.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_github_provider_documentation_hooks'],
			$GLOBALS['ran_booster_github_provider_documentation_catalogs'],
			$GLOBALS['ran_booster_github_provider_documentation_locale']
		);
	}

	public function test_factory_attaches_a_four_argument_callback_for_its_metadata_owner(): void {
		$provider = $this->provider();
		$records  = $GLOBALS['ran_booster_github_provider_documentation_hooks'][ GitHubDocumentation::HOOK ][10] ?? array();

		self::assertCount( 1, $records );
		self::assertSame( 4, $records[0][1] );
		self::assertInstanceOf( GitHubDocumentation::class, $records[0][0][0] );

		$sections = ( $records[0][0] )( array(), 'https://example.test/documentation', 'site', $provider->get_metadata() );

		self::assertSame( array( GitHubDocumentation::ID ), array_column( $sections, 'id' ) );
		self::assertSame( 'GitHub releases and webhooks', $sections[0]['summary'] );
		self::assertIsCallable( $sections[0]['content'] );
	}

	public function test_callback_requires_the_exact_readonly_metadata_object(): void {
		$provider      = $this->provider();
		$documentation = new GitHubDocumentation( $provider->get_metadata() );

		self::assertSame( array(), $documentation->for_owner( array(), 'https://example.test/documentation', 'site' ) );
		self::assertSame( array(), $documentation->for_owner( array(), 'https://example.test/documentation', 'site', clone $provider->get_metadata() ) );
		self::assertSame(
			array( GitHubDocumentation::ID ),
			array_column( $documentation->for_owner( array(), 'https://example.test/documentation', 'site', $provider->get_metadata() ), 'id' )
		);
	}

	public function test_guide_uses_provider_owned_links_and_preserves_shared_safeguards(): void {
		$documentation = new GitHubDocumentation( $this->provider()->get_metadata() );
		$sections      = $documentation->for_owner( array(), 'https://example.test/documentation', 'site', $this->provider()->get_metadata() );

		self::assertSame( array(), $sections, 'A guide from one aggregate must not render for another metadata owner.' );

		$provider = $this->provider();
		$sections = $documentation->for_owner( array(), 'https://example.test/documentation', 'site', $provider->get_metadata() );
		self::assertSame( array(), $sections, 'A callback retains only the metadata object supplied at construction.' );

		$documentation = new GitHubDocumentation( $provider->get_metadata() );
		$sections      = $documentation->for_owner( array(), 'https://example.test/documentation', 'site', $provider->get_metadata() );
		ob_start();
		( $sections[0]['content'] )();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases', $html );
		self::assertStringContainsString( 'GitHub does not automatically redeliver failed deliveries', $html );
		self::assertStringContainsString( 'verified Remove action', $html );
		self::assertStringContainsString( 'does not enable that repository setting through its setup pull request', $html );
		self::assertStringNotContainsString( 'Bitbucket', $html );
	}

	public function test_loader_resolves_the_package_catalog_and_missing_catalog_is_safe(): void {
		self::assertFalse( GitHubTranslations::load( 'de_DE' ) );
		self::assertSame(
			array( GitHubTranslations::DOMAIN, dirname( __DIR__, 3 ) . '/languages/' . GitHubTranslations::DOMAIN . '-de_DE.mo', 'de_DE' ),
			$GLOBALS['ran_booster_github_provider_documentation_catalogs'][0]
		);

		self::assertFalse( GitHubTranslations::load( 'fr_FR' ) );
		self::assertCount( 2, $GLOBALS['ran_booster_github_provider_documentation_catalogs'] );
	}

	public function test_shipped_catalogs_and_source_are_present_for_runtime_packaging(): void {
		$root = dirname( __DIR__, 3 );

		foreach ( array(
			$root . '/src/GitHubDocumentation.php',
			$root . '/src/GitHubTranslations.php',
			$root . '/languages/' . GitHubTranslations::DOMAIN . '.pot',
			$root . '/tests/fixtures/documentation-i18n/' . GitHubTranslations::DOMAIN . '-fr_FR.po',
			$root . '/tests/fixtures/documentation-i18n/' . GitHubTranslations::DOMAIN . '-es_ES.po',
		) as $path ) {
			self::assertFileExists( $path );
		}

		self::assertFileDoesNotExist( $root . '/languages/' . GitHubTranslations::DOMAIN . '-fr_FR.mo' );
		self::assertFileDoesNotExist( $root . '/languages/' . GitHubTranslations::DOMAIN . '-es_ES.mo' );
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
