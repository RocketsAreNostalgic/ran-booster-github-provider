<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$root = dirname( __DIR__ );
require $root . '/vendor/autoload.php';
// The candidate fixture can provide its own root while retaining the real locked tool.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$root = $argv[1] ?? $root;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$development = in_array( '--development', $argv ?? array(), true );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$configuration = $development ? 'phpstan-development.neon' : 'phpstan.neon';
// @phpstan-ignore phpstanApi.constructor, phpstanApi.method (Locked NeonAdapter reads the exact configuration consumed by this coverage contract.)
$config = ( new PHPStan\DependencyInjection\NeonAdapter( array() ) )->load( $root . '/' . $configuration ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$exemptions = $development ? array( 'vendor', 'node_modules', '.git', '.phpstan', '.phpunit.cache' ) : array( 'tests/Booster', 'tests/Support', 'tests/fixtures', 'scripts', 'vendor', 'node_modules', '.git', '.phpstan', '.phpunit.cache' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$excluded_files = $development ? array() : array( 'tests/host-contract.php', 'tests/analysis-coverage.php' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$expected_exclusions = array_merge( array_map( static fn( string $path ): string => $path . '/*', $exemptions ), $excluded_files );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$expected_paths = array( 'analyseAndScan' => $expected_exclusions );
if ( $development ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	$expected_paths['analyse'] = array( 'src/*' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	$exemptions[] = 'src';
}
ksort( $expected_paths );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$actual_paths = $config['parameters']['excludePaths'] ?? array();
ksort( $actual_paths );
if ( 5 !== ( $config['parameters']['level'] ?? null )
	|| array( '.' ) !== ( $config['parameters']['paths'] ?? null )
	|| $expected_paths !== $actual_paths
	|| array( 'vendor/szepeviktor/phpstan-wordpress/extension.neon' ) !== ( $config['includes'] ?? array() )
	|| isset( $config['parameters']['fileExtensions'] )
	|| isset( $config['parameters']['ignoreErrors'] )
	|| ( $development && ( isset( $config['parameters']['bootstrapFiles'] ) || array( '%env.RAN_BOOSTER_CORE_PATH%/RAN' ) !== ( $config['parameters']['scanDirectories'] ?? null ) ) ) ) {
	throw new RuntimeException( 'Review inclusive analysis scope and its explicit role exemptions.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local canonical command, never runtime or remote state. Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$composer = json_decode( file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( array( '@analyze:production', '@analyze:development' ) !== $composer['scripts']['analyze']
	|| 'phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=512M' !== $composer['scripts']['analyze:production']
	|| 'phpstan analyse --configuration=phpstan-development.neon --no-progress --memory-limit=512M' !== $composer['scripts']['analyze:development'] ) {
	throw new RuntimeException( 'Review analysis command overrides.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$iterator = new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	static function ( SplFileInfo $entry ) use ( $root, $exemptions ): bool {
		return ! $entry->isDir() || ! in_array( substr( $entry->getPathname(), strlen( $root ) + 1 ), $exemptions, true );
	}
);
// Only these three locked-tool API stability notices have reviewed local scope.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$tool_annotations = array(
	'// @phpstan-ignore phpstanApi.constructor, phpstanApi.method (Locked NeonAdapter reads the exact configuration consumed by this coverage contract.)' => '$config=(newPHPStan\\DependencyInjection\\NeonAdapter(array()))->load($root.\'/\'.$configuration);//phpcs:ignoreWordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound--Retainthisprocess-localCLIgatebinding;thisscriptneverenterstheWordPressruntime.',
	'// @phpstan-ignore phpstanApi.constructor (Locked FileExcluder mirrors the actual CLI post-finder stub removal.)' => '$stub_excluder=newPHPStan\\File\\FileExcluder($container->getByType(PHPStan\\File\\FileHelper::class),$container->getParameter(\'stubFiles\'));//phpcs:ignoreWordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound--Retainthisprocess-localCLIgatebinding;thisscriptneverenterstheWordPressruntime.',
	'// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)' => '$actual=array_filter($actual,staticfn(string$file):bool=>!$stub_excluder->isExcludedFromAnalysing($file));//phpcs:ignoreWordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound--Retainthisprocess-localCLIgatebinding;thisscriptneverenterstheWordPressruntime.',
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$found_annotations = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$foreign_annotation = '// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The WordPress stand-in must retain the exact global function name called by the code under test. @phpstan-ignore return.unusedType (Success-only fixture retains the locked WordPress string|false return contract.)';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$foreign_count = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$expected = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
foreach ( new RecursiveIteratorIterator( $iterator ) as $entry ) {
	if ( ! $entry->isFile() || in_array( substr( $entry->getPathname(), strlen( $root ) + 1 ), $excluded_files, true ) ) {
		continue;
	}
	if ( 0 === strcasecmp( $entry->getExtension(), 'php' ) ) {
		if ( 'php' !== $entry->getExtension() ) {
			throw new RuntimeException( 'Unsupported PHP extension must not evade analysis.' );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
		$expected[] = $entry->getPathname();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect maintained comments without executing the source. Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
		$source = file_get_contents( $entry->getPathname() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) || ! preg_match( '/@phpstan-ignore/i', $token[1] ) ) {
				continue;
			}
			if ( $foreign_annotation === $token[1] && $root . '/tests/Support/NeutralReleaseUpdaterWordPressFunctions.php' === $entry->getPathname() ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
				$line = explode( "\n", $source )[ $token[2] ];
				if ( 'functionwp_http_validate_url(string$url):string|false{' !== preg_replace( '/\s+/', '', $line ) ) {
					throw new RuntimeException( 'Review moved foreign fixture signature exemption.' );
				}
				++$foreign_count;
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
			$next = preg_replace( '/\s+/', '', explode( "\n", $source )[ $token[2] ] ?? '' );
			if ( $root . '/tests/analysis-coverage.php' !== $entry->getPathname() || ! isset( $tool_annotations[ $token[1] ] ) || $tool_annotations[ $token[1] ] !== $next ) {
				throw new RuntimeException( 'Review new or changed analysis exemptions.' );
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
			$found_annotations[] = $token[1];
		}
	} else {
		// Unknown suffixes may contain PHP includes after HTML or long preambles.
		// Documentation/data and Node/Bash fixture examples retain header inspection.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect local nonstandard-extension contents without executing them. Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
		$header = file_get_contents( $entry->getPathname() );
		if ( false === $header ) {
			throw new RuntimeException( 'Cannot inspect maintained source for PHP coverage.' );
		}
		if ( 'phtml' === strtolower( $entry->getExtension() ) || preg_match(
			in_array( strtolower( $entry->getExtension() ), array( 'md', 'json', 'mjs' ), true )
				|| ( 'sh' === strtolower( $entry->getExtension() ) && ( str_starts_with( $header, "#!/usr/bin/env bash\n" ) || str_starts_with( $header, "#!/bin/bash\n" ) ) )
				? '/^(?:\xEF\xBB\xBF)?(?:#![^\n]*\n)?\s*<\?/'
				: '/<\?/',
			// Strip only a genuine leading XML declaration; later opening tags remain visible.
			preg_replace(
				'~\A(?:\xEF\xBB\xBF)?<\?xml[ \t\r\n]+version[ \t\r\n]*=[ \t\r\n]*(?:"1\.[01]"|\'1\.[01]\')(?:[ \t\r\n]+encoding[ \t\r\n]*=[ \t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|\'[A-Za-z][A-Za-z0-9._-]*\'))?(?:[ \t\r\n]+standalone[ \t\r\n]*=[ \t\r\n]*(?:"(?:yes|no)"|\'(?:yes|no)\'))?[ \t\r\n]*\?>~',
				'',
				$header
			) ?? $header
		) ) {
			throw new RuntimeException( 'Nonstandard-extension PHP needs an explicit reviewed analysis decision.' );
		}
	}
}
if ( $development && ( array_keys( $tool_annotations ) !== $found_annotations || 1 !== $foreign_count ) ) {
	throw new RuntimeException( 'Review the exact locked-tool analysis exemption inventory.' );
}
if ( array() === $expected ) {
	throw new RuntimeException( 'No PHP discovered for this analysis profile.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
$temp = sys_get_temp_dir() . '/ran-provider-analysis-' . bin2hex( random_bytes( 12 ) );
try {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	$container = ( new PHPStan\DependencyInjection\ContainerFactory( $root ) )->create( $temp, array( $root . '/' . $configuration ), array() );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	$actual = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
	// Match the locked CLI's post-discovery removal of stub files.
	// @phpstan-ignore phpstanApi.constructor (Locked FileExcluder mirrors the actual CLI post-finder stub removal.)
	$stub_excluder = new PHPStan\File\FileExcluder( $container->getByType( PHPStan\File\FileHelper::class ), $container->getParameter( 'stubFiles' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)
	$actual = array_filter( $actual, static fn( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
	if ( array() !== array_diff( $expected, $actual ) || array() !== array_diff( $actual, $expected ) ) {
		throw new RuntimeException( 'Effective PHPStan selection differs from independently discovered PHP.' );
	}
} finally {
	if ( is_dir( $temp ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Retain this process-local CLI gate binding; this script never enters the WordPress runtime.
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private PHPStan container cache created above.
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the unique private PHPStan container cache created above.
		rmdir( $temp );
	}
}
printf( "Effective %s analysis covers %d independently discovered PHP files.\n", $development ? 'development' : 'production', count( $expected ) );
