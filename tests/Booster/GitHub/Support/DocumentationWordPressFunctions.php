<?php

declare(strict_types=1);

if ( ! function_exists( 'add_filter' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public hook name.
	function add_filter( string $hook, callable $callback, int $priority = 10, int $arguments = 1 ): bool {
		$GLOBALS['ran_booster_github_provider_documentation_hooks'][ $hook ][ $priority ][] = array( $callback, $arguments ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Process-local WordPress hook fixture.

		return true;
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public localization name.
	function determine_locale(): string {
		return $GLOBALS['ran_booster_github_provider_documentation_locale'] ?? 'en_US'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Process-local WordPress localization fixture.
	}
}

if ( ! function_exists( 'load_textdomain' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public localization name.
	function load_textdomain( string $domain, string $path, string $locale = 'en_US' ): bool {
		$GLOBALS['ran_booster_github_provider_documentation_catalogs'][] = array( $domain, $path, $locale ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Process-local WordPress localization fixture.

		return is_file( $path );
	}
}

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public localization name.
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public escaping name.
	function esc_html_e( string $text, string $domain = 'default' ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- This fixture mirrors the dynamic WordPress translation and escaping boundary.
		echo htmlspecialchars( __( $text, $domain ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated fixture keeps WordPress's public escaping name.
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
