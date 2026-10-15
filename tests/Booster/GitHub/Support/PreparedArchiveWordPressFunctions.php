<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Exercise the exact Core archive namespace without changing production function lookup.
namespace RAN\RepositoryProvider;

// These exact WordPress function identities model the host archive's hook lifetime.

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the WordPress function resolved by the Core archive class in this isolated test namespace.
function add_filter( string $hook, callable $callback, int $priority, int $arguments ): void {
	$GLOBALS['ran_booster_github_provider_archive_hooks'][ $hook ][ $priority ][] = array( $callback, $arguments );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the WordPress function resolved by the Core archive class in this isolated test namespace.
function add_action( string $hook, callable $callback, int $priority, int $arguments ): void {
	add_filter( $hook, $callback, $priority, $arguments );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the WordPress function resolved by the Core archive class in this isolated test namespace.
function remove_filter( string $hook, callable $callback, int $priority ): void {
	foreach ( $GLOBALS['ran_booster_github_provider_archive_hooks'][ $hook ][ $priority ] ?? array() as $key => $record ) {
		if ( $record[0] === $callback ) {
			unset( $GLOBALS['ran_booster_github_provider_archive_hooks'][ $hook ][ $priority ][ $key ] );
		}
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the WordPress function resolved by the Core archive class in this isolated test namespace.
function remove_action( string $hook, callable $callback, int $priority ): void {
	remove_filter( $hook, $callback, $priority );
}
