<?php

declare(strict_types=1);

if ( ! function_exists( 'wp_cache_delete' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Supply the exact global WordPress cache contract required by namespace ownership tests.
	function wp_cache_delete( string $key, string $group = '' ): bool {
		$GLOBALS['ran_booster_github_provider_test_cache_deletes'][] = array( $key, $group );
		return true;
	}
}
