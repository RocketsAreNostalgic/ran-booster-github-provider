<?php

declare(strict_types=1);

$root         = dirname( __DIR__ );
$composerPath = $root . '/composer.json';
$composerJson = file_get_contents( $composerPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package contract fixture.
$legacyRuntimeIdentifiers = array(
	'legacyAssistedHooksAddOnIsActive',
	'registerLegacyAssistedHooksAddOnNotice',
	'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION',
	'RAN\\AssistedHooks\\Plugin',
	'pre-retirement RAN Booster Assisted Hooks',
);
$sourceIterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
);
foreach ( $sourceIterator as $sourceFile ) {
	if ( ! $sourceFile instanceof SplFileInfo || 'php' !== strtolower( $sourceFile->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $sourceFile->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package contract fixture.
	if ( ! is_string( $source ) ) {
		throw new RuntimeException( 'Unable to read provider production source.' );
	}
	foreach ( $legacyRuntimeIdentifiers as $identifier ) {
		if ( str_contains( $source, $identifier ) ) {
			throw new RuntimeException( 'Provider production source still contains obsolete Assisted Hooks runtime compatibility.' );
		}
	}
}

if ( ! is_string( $composerJson ) ) {
	throw new RuntimeException( 'Unable to read composer.json.' );
}

/** @var array<string, mixed> $composer */
$composer = json_decode( $composerJson, true, 512, JSON_THROW_ON_ERROR );

$required = array(
	'name'    => 'ran/booster-github-provider',
	'type'    => 'library',
	'license' => 'GPL-2.0-or-later',
);
foreach ( $required as $key => $expected ) {
	if ( ( $composer[ $key ] ?? null ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CLI contract failure only.
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
if ( '0.1.0-beta.3' !== ( $require['ran/updater-support'] ?? null ) ) {
	throw new RuntimeException( 'The shared repository-path dependency must remain explicit and immutable.' );
}

$autoload = $composer['autoload']['psr-4'] ?? null;
if ( ! is_array( $autoload ) || 'src/' !== ( $autoload['RAN\\BoosterGitHubProvider\\V1\\'] ?? null ) ) {
	throw new RuntimeException( 'The package-owned PSR-4 namespace is invalid.' );
}

$requiredDevDependencies = array(
	'php-stubs/wordpress-stubs',
	'phpcompatibility/php-compatibility',
	'phpcompatibility/phpcompatibility-paragonie',
	'phpcompatibility/phpcompatibility-wp',
	'phpstan/phpstan',
	'ran/coding-standards',
	'szepeviktor/phpstan-wordpress',
);
$requireDev              = $composer['require-dev'] ?? null;
if ( ! is_array( $requireDev ) ) {
	throw new RuntimeException( 'The development quality dependency set is missing.' );
}
foreach ( $requiredDevDependencies as $dependency ) {
	if ( ! array_key_exists( $dependency, $requireDev ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required quality dependency is missing: {$dependency}." );
	}
}

$scripts = $composer['scripts'] ?? null;
if ( ! is_array( $scripts ) ) {
	throw new RuntimeException( 'The Composer quality command contract is missing.' );
}
foreach ( array( 'check', 'format', 'format:php', 'lint:php', 'lint:syntax', 'analyze', 'test:foundation' ) as $script ) {
	if ( ! array_key_exists( $script, $scripts ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required Composer script is missing: {$script}." );
	}
}

foreach ( array( '.editorconfig', '.phpcs.xml', 'phpstan.neon', '.github/workflows/ci.yml', 'composer.lock' ) as $requiredPath ) {
	if ( ! is_file( $root . '/' . $requiredPath ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Dependency-free CLI contract failure only.
		throw new RuntimeException( "Required package-foundation file is missing: {$requiredPath}." );
	}
}
