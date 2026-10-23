<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use RAN\RepositoryProvider\ProviderMetadata;

/**
 * Adds GitHub-owned guidance only for the metadata object this package created.
 */
final class GitHubDocumentation {

	public const ID   = 'ran-booster-documentation-github-releases';
	public const HOOK = 'ran_booster_documentation_sections_after_provider_gh';

	public function __construct( private readonly ProviderMetadata $owner ) {}

	/** Register the callback when this library is loaded inside WordPress. */
	public function register(): bool {
		if ( ! function_exists( 'add_filter' ) ) {
			return false;
		}

		return add_filter( self::HOOK, array( $this, 'for_owner' ), 10, 4 );
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<array<string, mixed>>
	 */
	public function for_owner( array $sections, string $documentation_url, string $scope, ?ProviderMetadata $metadata = null ): array {
		unset( $documentation_url, $scope );

		if ( $metadata !== $this->owner ) {
			return $sections;
		}

		GitHubTranslations::load_current_locale();
		$sections[] = array(
			'id'      => self::ID,
			'summary' => __( 'GitHub releases and webhooks', 'ran-booster-github-provider' ),
			'content' => array( $this, 'render' ),
		);

		return $sections;
	}

	/** Render GitHub-specific guidance inside Core's sanitized disclosure shell. */
	public function render(): void {
		?>
		<p><?php esc_html_e( 'Use GitHub releases for an eligible plugin or theme only after you have reviewed the exact uploaded release ZIP. Booster checks the selected release again before WordPress changes files.', 'ran-booster-github-provider' ); ?></p>
		<h3><?php esc_html_e( 'Publish a GitHub release', 'ran-booster-github-provider' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Upload one eligible installable plugin or theme ZIP as the release asset. Do not use GitHub’s generated source archive.', 'ran-booster-github-provider' ); ?></li>
			<li><?php esc_html_e( 'Keep the release tag and the canonical WordPress Version header aligned. Keep the package Update URI aligned with the selected GitHub repository. Plugins need one eligible top-level plugin header; themes need root style.css. A mismatch is rejected.', 'ran-booster-github-provider' ); ?></li>
			<li><?php esc_html_e( 'GitHub immutable releases are optional supply-chain hardening. Booster does not enable that repository setting through its setup pull request.', 'ran-booster-github-provider' ); ?></li>
		</ul>
		<p><a href="<?php echo esc_url( 'https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About GitHub releases', 'ran-booster-github-provider' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-github-provider' ); ?></span></a> <span aria-hidden="true">·</span> <a href="<?php echo esc_url( 'https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About immutable releases', 'ran-booster-github-provider' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-github-provider' ); ?></span></a></p>
		<h3><?php esc_html_e( 'Use the setup pull request deliberately', 'ran-booster-github-provider' ); ?></h3>
		<p><?php esc_html_e( 'Review the generated setup pull request in the repository. It prepares release workflow files; it does not publish a release, merge changes, enable immutable releases, or replace your repository review policy.', 'ran-booster-github-provider' ); ?></p>
		<h3><?php esc_html_e( 'Check deliveries and remove retired hooks', 'ran-booster-github-provider' ); ?></h3>
		<p><?php esc_html_e( 'GitHub does not automatically redeliver failed deliveries. Test a delivery from GitHub, then compare its delivery identifier with the Provider request ID in Booster Activity. A missing Activity entry is inconclusive for probes, ignored events, and zero-target deliveries.', 'ran-booster-github-provider' ); ?></p>
		<p><?php esc_html_e( 'For a known GitHub hook, use Booster’s verified Remove action before removing a local signing secret. That action removes the remote hook and releases only a repository secret created specifically for it. Confirm that no branch-managed package still needs the repository hook or an owner-shared secret. If ownership or remaining use is uncertain, leave the setup in place.', 'ran-booster-github-provider' ); ?></p>
		<?php
	}
}
