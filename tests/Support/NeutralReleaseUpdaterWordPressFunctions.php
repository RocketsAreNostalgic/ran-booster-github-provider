<?php


declare(strict_types=1);

require_once __DIR__ . '/WPError.php';

if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The fixture replaces this exact WordPress class name without loading WordPress.
	class WP_Filesystem_Direct {}
}

if ( ! function_exists( 'get_filesystem_method' ) ) {
	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test. This exact WordPress function and adjacent filesystem or database double form one isolated host fixture.
	function get_filesystem_method(): string {
		return $GLOBALS['ran_booster_release_filesystem_method'] ?? 'direct';
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test. WordPress owns the exact WP_Filesystem function identity required by the filesystem fixture.
	function WP_Filesystem(): bool {
		if ( 'direct' !== get_filesystem_method() ) {
			return false;
		}
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only direct filesystem initialization fixture.

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress signature is preserved; this fixture only models the behavior asserted by callers. The WordPress stand-in must retain the exact global function name called by the code under test.
	function add_action( string $hook, callable $callback, int $priority = 10, int $arguments = 1 ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function add_filter( string $hook, callable $callback, int $priority = 10, int $arguments = 1 ): void {}
}

if ( ! function_exists( 'doing_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress signature is preserved; this fixture only models the behavior asserted by callers. The WordPress stand-in must retain the exact global function name called by the code under test.
	function doing_action( string $hook ): bool {
		return false; }
}

if ( ! function_exists( 'did_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress signature is preserved; this fixture only models the behavior asserted by callers. The WordPress stand-in must retain the exact global function name called by the code under test.
	function did_action( string $hook ): int {
		return 0; }
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function wp_safe_remote_get( string $url, array $arguments ): array|WP_Error {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_requests'][] = array( $url, $arguments );
		$response                                  = array_shift( $GLOBALS['ran_booster_release_responses'] );
		if ( $response instanceof WP_Error ) {
			return $response;
		}
		if ( ! is_array( $response ) ) {
			throw new RuntimeException( 'Unexpected neutral updater HTTP request.' );
		}
		if ( isset( $arguments['filename'], $response['file'] ) ) {
			file_put_contents( $arguments['filename'], $response['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only deterministic download.
			chmod( $arguments['filename'], 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
		}

		return $response;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function wp_remote_retrieve_response_code( array $response ): int|string {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function wp_remote_retrieve_header( array $response, string $name ): mixed {
		return $response['headers'][ strtolower( $name ) ] ?? null;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function wp_remote_retrieve_body( array $response ): string {
		return is_string( $response['body'] ?? null ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test. @phpstan-ignore return.unusedType (Success-only fixture retains the locked WordPress string|false return contract.)
	function wp_http_validate_url( string $url ): string|false {
		return $url;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test.
	function wp_tempnam( string $filename ): string|false {
		unset( $filename );
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-neutral-release-' );
		if ( is_string( $path ) ) {
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve the extracted temporary-path fixture identity.
			$GLOBALS['ran_booster_release_temp_paths'][] = $path;
		}

		return $path;
	}
}
