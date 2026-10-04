<?php

declare(strict_types=1);

$root          = dirname( __DIR__ );
$composer_path = $root . '/composer.json';
$composer_json = file_get_contents( $composer_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package contract fixture.
if ( ! is_string( $composer_json ) ) {
	throw new RuntimeException( 'Unable to read composer.json.' );
}

/** @var array<string, mixed> $composer */
$composer = json_decode( $composer_json, true, 512, JSON_THROW_ON_ERROR );

$required = array(
	'name'    => 'ran/booster-github-provider',
	'type'    => 'library',
	'license' => 'GPL-2.0-or-later',
);
foreach ( $required as $key => $expected ) {
	if ( ( $composer[ $key ] ?? null ) !== $expected ) {
		// Dependency-free CLI contract failure only.
		throw new RuntimeException( "Unexpected Composer {$key}." );
	}
}

$require = $composer['require'] ?? null;
if ( ! is_array( $require ) || '^8.2' !== ( $require['php'] ?? null ) ) {
	throw new RuntimeException( 'The package must retain the PHP 8.2 floor.' );
}
if ( array_key_exists( 'ran/booster', $require ) ) {
	throw new RuntimeException( 'The provider package must not depend on the whole Booster plugin in production.' );
}
if ( '^1.0.0-beta.4' !== ( $require['ran/updater-support'] ?? null ) ) {
	throw new RuntimeException( 'The shared repository-path dependency must retain the compatible Support beta.4 baseline.' );
}

$autoload = $composer['autoload']['psr-4'] ?? null;
if ( ! is_array( $autoload ) || 'src/' !== ( $autoload['RAN\\BoosterGitHubProvider\\V1\\'] ?? null ) ) {
	throw new RuntimeException( 'The package-owned PSR-4 namespace is invalid.' );
}

$required_dev_dependencies = array(
	'php-stubs/wordpress-stubs',
	'phpcompatibility/php-compatibility',
	'phpcompatibility/phpcompatibility-paragonie',
	'phpcompatibility/phpcompatibility-wp',
	'phpstan/phpstan',
	'ran/coding-standards',
	'szepeviktor/phpstan-wordpress',
);
$require_dev               = $composer['require-dev'] ?? null;
if ( ! is_array( $require_dev ) ) {
	throw new RuntimeException( 'The development quality dependency set is missing.' );
}
foreach ( $required_dev_dependencies as $dependency ) {
	if ( ! array_key_exists( $dependency, $require_dev ) ) {
		// Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required quality dependency is missing: {$dependency}." );
	}
}

$scripts = $composer['scripts'] ?? null;
if ( ! is_array( $scripts ) ) {
	throw new RuntimeException( 'The Composer quality command contract is missing.' );
}
foreach ( array( 'check', 'standards', 'standards:fix', 'lint:syntax', 'analyze', 'test', 'test:foundation', 'test:host-contract', 'check:host' ) as $script ) {
	if ( ! array_key_exists( $script, $scripts ) ) {
		// Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required Composer script is missing: {$script}." );
	}
}

foreach ( array( '.editorconfig', '.phpcs.xml', 'phpstan.neon', '.github/workflows/ci.yml', 'composer.lock' ) as $required_path ) {
	if ( ! is_file( $root . '/' . $required_path ) ) {
		// Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required package-foundation file is missing: {$required_path}." );
	}
}
