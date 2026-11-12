<?php


declare(strict_types=1);

if ( ! function_exists( 'get_option' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global signature Core's general preferences call; only the saved-option path is modelled.
	function get_option( string $option, mixed $default_value = false ): mixed {
		$saved = $GLOBALS['ran_booster_test_saved_options'] ?? array();

		return is_array( $saved ) && array_key_exists( $option, $saved ) ? $saved[ $option ] : $default_value;
	}
}
