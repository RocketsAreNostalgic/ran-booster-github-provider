<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use Throwable;

/** Fixed API 3 source-ready rules for repository-root plugins and themes. */
final class SourceReadyAssessor {
	// The setup writes six files that GitHubRepositoryClient reads as assessment documents:
	// two workflows, the origin record, RP config, and the build and verify scripts.
	private const MAX_SOURCE_INSPECTED_BLOBS = 250;
	// Ten generated files and up to three missing parents (.github, workflows, scripts).
	// Readback uses the GitHub client's 2,000-entry recursive tree limit.
	private const MAX_SOURCE_TREE_ENTRIES = 1987;
	private const GENERATED_PATHS         = array(
		'.github/workflows/release-please.yml',
		'.github/workflows/quality.yml',
		'.ran-booster-release-starter.json',
		'RELEASE-STARTER.md',
		'.ran-booster-release-profile.json',
		'.release-please-manifest.json',
		'release-please-config.json',
		'version.txt',
		'release-contents.txt',
		'scripts/build-release.sh',
		'scripts/verify-release.sh',
		'scripts/upload-release-assets.sh',
	);

	private const DEVELOPMENT_ROOTS = array(
		'.agents',
		'.codex',
		'.github',
		'.wordpress-org',
		'coverage',
		'dist',
		'docs',
		'node_modules',
		'scripts',
		'tests',
	);

	private const DEVELOPMENT_FILES = array(
		'.editorconfig',
		'.gitattributes',
		'.gitignore',
		'.phpcs.xml.dist',
		'.prettierignore',
		'.prettierrc',
		'.prettierrc.json',
		'.stylelintignore',
		'.stylelintrc',
		'.stylelintrc.json',
		'AGENTS.md',
		'CHANGELOG.md',
		'CONTRIBUTING.md',
		'LICENSE',
		'LICENSE.md',
		'Makefile',
		'README.md',
		'RELEASE.md',
		'SECURITY.md',
		'composer.json',
		'composer.lock',
		'package-lock.json',
		'package.json',
		'phpunit.xml',
		'phpunit.xml.dist',
		'pnpm-lock.yaml',
		'yarn.lock',
	);

	private const PLUGIN_RUNTIME_ROOTS = array(
		'assets',
		'blocks',
		'build',
		'fonts',
		'inc',
		'includes',
		'languages',
		'src',
		'templates',
		'vendor',
		'views',
	);

	private const THEME_RUNTIME_ROOTS = array(
		'assets',
		'blocks',
		'build',
		'fonts',
		'inc',
		'includes',
		'languages',
		'parts',
		'patterns',
		'src',
		'styles',
		'templates',
		'vendor',
	);

	/** Only paths which a supported plugin/theme could place in the release allowlist. */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
	public static function potentialRuntimeBlob( string $path ): bool {
		$parts = explode( '/', $path, 2 );
		if ( 2 === count( $parts ) ) {
			return in_array( $parts[0], self::PLUGIN_RUNTIME_ROOTS, true ) || in_array( $parts[0], self::THEME_RUNTIME_ROOTS, true );
		}
		return in_array( $path, array( 'theme.json', 'screenshot.png' ), true );
	}

	public function assess(
		RepositorySnapshot $snapshot,
		string $type,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
		string $packageSlug,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
		string $installedVersion,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
		string $expectedUpdateUri
	): SourceReadyAssessment {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
		return $this->assess_snapshot( $snapshot, $type, $packageSlug, $installedVersion, $expectedUpdateUri );
	}

	private function assess_snapshot(
		RepositorySnapshot $snapshot,
		string $type,
		string $package_slug,
		string $installed_version,
		string $expected_update_uri
	): SourceReadyAssessment {
		$expected_update_uri = rtrim( $expected_update_uri, '/' );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| 'main' !== $snapshot->defaultBranch() || strlen( $package_slug ) > 100 || strlen( $installed_version ) > 63
			|| 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $package_slug )
			|| 1 === preg_match( '/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/iD', $package_slug )
			|| 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $installed_version )
			|| ! hash_equals( 'https://github.com/' . $snapshot->repository(), $expected_update_uri ) ) {
			return SourceReadyAssessment::refused( 'repository_unsupported' );
		}
		if ( $snapshot->inspectedBlobCount() > self::MAX_SOURCE_INSPECTED_BLOBS
			|| count( $snapshot->entries() ) > self::MAX_SOURCE_TREE_ENTRIES ) {
			return SourceReadyAssessment::refused( 'runtime_paths_unknown' );
		}

		if ( $this->hasCompetingReleaseAutomation( $snapshot ) ) {
			return SourceReadyAssessment::refused( 'release_automation_conflict' );
		}

		foreach ( self::GENERATED_PATHS as $path ) {
			if ( $snapshot->has( $path ) ) {
				return SourceReadyAssessment::refused( 'release_path_conflict' );
			}
		}
		$header = $this->header( $snapshot, $type, $installed_version, $expected_update_uri );
		if ( is_string( $header ) ) {
			return SourceReadyAssessment::refused( $header );
		}

		$version_sources = $this->version_sources( $snapshot, $installed_version, $header['path'] );
		if ( is_string( $version_sources ) ) {
			return SourceReadyAssessment::refused( $version_sources );
		}

		$release_files = $this->release_files( $snapshot, $type, $header['path'] );
		if ( null === $release_files ) {
			return SourceReadyAssessment::refused( 'runtime_paths_unknown' );
		}

		$modified = array();
		if ( $header['content'] !== $snapshot->document( $header['path'] ) ) {
			$modified[ $header['path'] ] = $header['content'];
		}
		foreach ( $version_sources['modified'] as $path => $content ) {
			$modified[ $path ] = $content;
		}

		$extra_files   = array_merge(
			array(
				array(
					'type' => 'generic',
					'path' => $header['path'],
				),
			),
			$version_sources['extra']
		);
		$release_files = array_values( array_unique( array_merge( $release_files, array_keys( $version_sources['runtime'] ) ) ) );
		sort( $release_files, SORT_STRING );

		return SourceReadyAssessment::ready(
			'source-ready-wordpress-' . $type . '/3',
			$package_slug,
			$header['path'],
			$installed_version,
			$release_files,
			$modified,
			$extra_files,
			$header['php_version']
		);
	}

	/** @return array{path:string,content:string}|string */
	private function header( RepositorySnapshot $snapshot, string $type, string $version, string $update_uri ): array|string {
		$candidates = array();
		if ( 'theme' === $type ) {
			$candidates = array( 'style.css' );
		} else {
			foreach ( $snapshot->documentPaths() as $path ) {
				if ( ! str_contains( $path, '/' ) && str_ends_with( strtolower( $path ), '.php' ) ) {
					$document = $snapshot->document( $path );
					if ( is_string( $document ) && 1 === preg_match( '/^[ \t]*\*[ \t]*Plugin Name:[ \t]*\S/m', $document ) ) {
						$candidates[] = $path;
					}
				}
			}
		}

		if ( 1 !== count( $candidates ) ) {
			return 'package_ambiguous';
		}
		$path     = $candidates[0];
		$document = $snapshot->document( $path );
		if ( ! is_string( $document ) ) {
			return 'repository_unsupported';
		}

		$label = 'theme' === $type ? 'Theme Name' : 'Plugin Name';
		// The fixed theme verifier accepts the ordinary unstarred style.css header.
		if ( 'theme' === $type && 1 !== preg_match( '/^[ \t]*Theme Name:[ \t]*\S/m', $document ) ) {
			return 'package_ambiguous';
		}
		if ( 1 !== preg_match_all( '/^[ \t]*(?:\*[ \t]*)?' . preg_quote( $label, '/' ) . ':[ \t]*\S/m', $document ) ) {
			return 'package_ambiguous';
		}
		if ( 1 !== preg_match_all( '/^[ \t]*(?:\*[ \t]*)?Update URI:[ \t]*(\S+)[ \t]*$/m', $document, $uri_match )
			|| ! hash_equals( $update_uri, $uri_match[1][0] ) ) {
			return 'repository_unsupported';
		}
		if ( strlen( $path ) > 255 || 1 !== preg_match( '/\A[A-Za-z0-9._-]+\z/D', $path )
			|| 1 !== preg_match_all( '/^[ \t]*(?:\*[ \t]*)?Requires PHP:[ \t]*(\S+)[ \t]*$/m', $document, $php )
			|| ! in_array( $php[1][0], array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), true )
			|| 1 !== preg_match_all( '/^[ \t]*(?:\*[ \t]*)?Requires at least:[ \t]*[0-9]+\.[0-9]+(?:\.[0-9]+)?[ \t]*$/m', $document )
			|| ( 'theme' === $type && ! $snapshot->has( 'index.php' ) && ! $snapshot->has( 'templates/index.html' ) ) ) {
			return 'repository_unsupported';
		}
		$annotated = $this->annotate_version_line( $document, 'Version', $version );
		return null === $annotated ? 'version_mismatch' : array(
			'path'        => $path,
			'content'     => $annotated,
			'php_version' => $php[1][0],
		);
	}

	/**
	 * @return array{modified:array<string,string>,extra:list<array<string,mixed>>,runtime:array<string,true>}|string
	 */
	private function version_sources( RepositorySnapshot $snapshot, string $version, string $header_path ): array|string {
		$modified               = array();
		$extra                  = array();
		$runtime                = array();
		$header                 = $snapshot->document( $header_path ) ?? '';
		$without_header_version = preg_replace( '/^[ \t]*(?:\*[ \t]*)?Version:[^\r\n]*\R?/mi', '', $header, 1 ) ?? $header;
		if ( 1 === preg_match( '/define\s*\(\s*[\'\"][^\'\"]*VERSION[^\'\"]*[\'\"]\s*,\s*[\'\"]' . preg_quote( $version, '/' ) . '[\'\"]/i', $without_header_version ) ) {
			return 'version_contract_custom';
		}

		foreach ( array( 'package.json', 'composer.json' ) as $manifest ) {
			$bytes = $snapshot->document( $manifest );
			if ( null !== $bytes ) {
				try {
					$data = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
				} catch ( Throwable ) {
					return 'version_contract_custom';
				}
				if ( ! is_array( $data ) || isset( $data['version'] ) ) {
					return 'version_contract_custom';
				}
			}
		}

		$readme = $snapshot->document( 'readme.txt' );
		if ( is_string( $readme ) ) {
			$annotated = $this->annotate_version_line( $readme, 'Stable tag', $version );
			if ( null === $annotated ) {
				return 'version_contract_custom';
			}
			if ( $annotated !== $readme ) {
				$modified['readme.txt'] = $annotated;
			}
			$runtime['readme.txt'] = true;
			$extra[]               = array(
				'type' => 'generic',
				'path' => 'readme.txt',
			);
		}

		foreach ( $snapshot->documentPaths() as $path ) {
			if ( str_ends_with( $path, 'block.json' ) ) {
				$document = $snapshot->document( $path );
				try {
					$data = is_string( $document ) ? json_decode( $document, true, 32, JSON_THROW_ON_ERROR ) : null;
				} catch ( Throwable ) {
					return 'version_contract_custom';
				}
				if ( ! is_array( $data ) || isset( $data['version'] ) ) {
					return 'version_contract_custom';
				}
			}
			if ( str_ends_with( strtolower( $path ), '.pot' ) ) {
				return 'version_contract_custom';
			}
		}

		return array(
			'modified' => $modified,
			'extra'    => $extra,
			'runtime'  => $runtime,
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
	public function hasCompetingReleaseAutomation( RepositorySnapshot $snapshot ): bool {
		foreach ( array_keys( $snapshot->entries() ) as $path ) {
			if ( ! in_array( $path, self::GENERATED_PATHS, true )
				&& in_array( basename( $path ), array( '.release-please-manifest.json', 'release-please-config.json' ), true ) ) {
				return true;
			}
		}

		foreach ( $snapshot->documentPaths() as $path ) {
			$workflow = str_starts_with( $path, '.github/workflows/' ) && 1 === preg_match( '/\.ya?ml\z/i', $path );
			$script   = ( str_starts_with( $path, 'scripts/' ) || str_starts_with( $path, '.github/scripts/' ) || str_starts_with( $path, '.ci/' ) )
				&& str_ends_with( strtolower( $path ), '.sh' );
			$config   = in_array( $path, array( 'package.json', 'composer.json', 'Makefile' ), true );
			if ( ! $workflow && ! $script && ! $config ) {
				continue;
			}

			$content = $snapshot->document( $path ) ?? '';
			if ( $workflow ) {
				return true;
			}

			$commands = $content;
			if ( in_array( $path, array( 'package.json', 'composer.json' ), true ) ) {
				try {
					$data = json_decode( $content, true, 32, JSON_THROW_ON_ERROR );
				} catch ( Throwable ) {
					$data = array();
				}
				if ( 'package.json' === $path && is_array( $data ) ) {
					foreach ( array( 'dependencies', 'devDependencies', 'optionalDependencies' ) as $group ) {
						foreach ( array_keys( is_array( $data[ $group ] ?? null ) ? $data[ $group ] : array() ) as $dependency ) {
							if ( in_array( $dependency, array( 'release-it', 'release-please', 'semantic-release' ), true )
								|| str_starts_with( $dependency, '@semantic-release/' ) ) {
								return true;
							}
						}
					}
				}
				$script_values = array();
				if ( is_array( $data['scripts'] ?? null ) ) {
					array_walk_recursive(
						$data['scripts'],
						static function ( mixed $value ) use ( &$script_values ): void {
							if ( is_string( $value ) ) {
								$script_values[] = $value;
							}
						}
					);
				}
				$commands = implode( "\n", $script_values );
			}

			if ( $this->contains_release_command( $commands ) || $this->mutates_release_api( $commands ) ) {
				return true;
			}
		}
		return false;
	}

	private function contains_release_command( string $content ): bool {
		$prefix = '(?:\A|[\r\n;&|(){}])[ \t]*(?:-[ \t]*)?(?:run:[ \t]*)?@?(?:(?:if|then|do|command|cross-env|env|exec|sudo)[ \t]+|![ \t]*)?(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t\r\n]+[ \t]+)*';
		return 1 === preg_match( '/' . $prefix . 'gh[ \t]+release[ \t]+(?:create|upload|edit|delete)(?:[ \t\r\n]|\\\\|\z)/i', $content )
			|| 1 === preg_match( '/' . $prefix . '(?:(?:npx|yarn|pnpm[ \t]+exec|npm[ \t]+exec)[ \t]+)?(?:release-please|semantic-release|release-it)(?:[ \t\r\n]|\\\\|\z)/i', $content )
			|| 1 === preg_match( '/' . $prefix . 'goreleaser[ \t]+release(?:[ \t\r\n]|\\\\|\z)/i', $content );
	}

	private function mutates_release_api( string $content ): bool {
		$content  = preg_replace( '/\\\\\r?\n/', ' ', $content ) ?? $content;
		$commands = preg_split( '/(?:\r?\n|&&|\|\||;)/', $content );
		if ( ! is_array( $commands ) ) {
			return false;
		}
		foreach ( $commands as $command ) {
			$command       = trim( $command );
			$lower_command = strtolower( $command );
			if ( str_starts_with( $command, '#' ) || ! str_contains( $lower_command, '/releases' )
				|| 1 !== preg_match( '/\A(?:-[ \t]*)?(?:run:[ \t]*)?@?(?:(?:command|env|exec)[ \t]+)?(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t]+[ \t]+)*(?:gh[ \t]+api|curl)(?:[ \t]|\z)/i', $command ) ) {
				continue;
			}
			if ( 1 === preg_match( '/(?:(?:--method|--request)(?:=|[ \t])*|-X(?:=|[ \t])*)(?i:post|patch|delete)(?:[ \t]|\\\\|\z)/', $command )
				|| ( str_contains( $lower_command, 'gh api' ) && ( 1 === preg_match( '/(?:--field|--raw-field|--input)(?:=|[ \t])/', $command )
					|| 1 === preg_match( '/(?:\A|[ \t])-[fF](?:[^ \t]*=|[ \t])/', $command ) ) )
				|| ( str_contains( $lower_command, 'curl ' ) && ( 1 === preg_match( '/(?:--data(?:-ascii|-binary|-raw|-urlencode)?|--form(?:-string)?|--json)(?:=|[ \t])/', $command )
					|| 1 === preg_match( '/(?:\A|[ \t])(?:-d(?:[^ \t]|[ \t])|-F(?:[^ \t]*=|[ \t]))/', $command ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string>|null */
	private function release_files( RepositorySnapshot $snapshot, string $type, string $header_path ): ?array {
		$runtime_roots = 'plugin' === $type ? self::PLUGIN_RUNTIME_ROOTS : self::THEME_RUNTIME_ROOTS;
		$files         = array();
		$normalized    = array();
		$directories   = array();
		// Reserve 4 MiB for ZIP records, slug/path names and the bounded version annotations.
		// Staying below 46 MiB uncompressed is conservative even for stored/incompressible ZIPs.
		$remaining = 46 * 1024 * 1024;
		foreach ( $snapshot->entries() as $path => $entry ) {
			if ( 'blob' !== $entry['type'] ) {
				continue;
			}
			$parts = explode( '/', $path, 2 );
			$root  = $parts[0];
			if ( in_array( $root, self::DEVELOPMENT_ROOTS, true ) || in_array( $path, self::DEVELOPMENT_FILES, true )
				|| str_starts_with( $root, '.' ) ) {
				continue;
			}
			$runtime = in_array( $root, $runtime_roots, true )
				|| ( ! str_contains( $path, '/' ) && $this->runtime_root_file( $path, $type, $header_path ) );
			if ( ! $runtime || '100644' !== $entry['mode']
				|| 1 !== preg_match( '~\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z~D', $path ) || str_contains( $path, '..' ) ) {
				return null;
			}
			foreach ( explode( '/', $path ) as $segment ) {
				if ( strlen( $segment ) > 255 || str_ends_with( $segment, '.' )
					|| 1 === preg_match( '/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/i', $segment ) ) {
					return null; }
			}
			$logical = strtolower( $path );
			if ( isset( $normalized[ $logical ] ) || $entry['size'] > $remaining ) {
				return null;
			}
			$parent = dirname( $path );
			while ( '.' !== $parent ) {
				$folded = strtolower( $parent );
				if ( isset( $directories[ $folded ] ) && $directories[ $folded ] !== $parent ) {
					return null;
				}
				$directories[ $folded ] = $parent;
				$parent                 = dirname( $parent );
			}
			$normalized[ $logical ] = true;
			$remaining             -= $entry['size'];
			$prefix                 = $snapshot->blobPrefix( $path );
			if ( ( $entry['size'] >= 42 && null === $prefix )
				|| ( null !== $prefix && str_starts_with( $prefix, 'version https://git-lfs.github.com/spec/v1' ) ) ) {
				return null;
			}
			$files[] = $path;
		}
		foreach ( array_keys( $normalized ) as $logical ) {
			$parent = dirname( $logical );
			while ( '.' !== $parent ) {
				if ( isset( $normalized[ $parent ] ) ) {
					return null;
				}
				$parent = dirname( $parent );
			}
		}
		return in_array( $header_path, $files, true ) ? $files : null;
	}

	private function runtime_root_file( string $path, string $type, string $header_path ): bool {
		if ( hash_equals( $header_path, $path ) || 'readme.txt' === $path ) {
			return true;
		}
		if ( 'plugin' === $type ) {
			return str_ends_with( strtolower( $path ), '.php' );
		}
		return in_array( $path, array( 'index.php', 'functions.php', 'style.css', 'theme.json', 'screenshot.png' ), true );
	}

	private function annotate_version_line( string $document, string $label, string $expected_version ): ?string {
		$pattern = '/^[ \t]*(?:\*[ \t]*)?' . preg_quote( $label, '/' ) . ':[ \t]*([^\s]+)[ \t]*$/' . ( 'Version' === $label ? 'm' : 'mi' );
		if ( 1 !== preg_match_all( $pattern, $document, $matches, PREG_OFFSET_CAPTURE )
			|| ! hash_equals( $expected_version, $matches[1][0][0] ) ) {
			return null;
		}
		$line   = $matches[0][0][0];
		$offset = $matches[0][0][1];
		$start  = preg_match_all( '/^[ \t]*(?:\*[ \t]*)?x-release-please-start-version[ \t]*$/mi', $document );
		$end    = preg_match_all( '/^[ \t]*(?:\*[ \t]*)?x-release-please-end[ \t]*$/mi', $document );
		if ( 0 !== $start || 0 !== $end ) {
			$owned_pattern = '/^[ \t]*(?:\*[ \t]*)?x-release-please-start-version[ \t]*\R'
				. preg_quote( $line, '/' ) . '\R^[ \t]*(?:\*[ \t]*)?x-release-please-end[ \t]*$/mi';
			return 1 === $start && 1 === $end && 1 === preg_match( $owned_pattern, $document ) ? $document : null;
		}

		$prefix = '';
		preg_match( '/\A([ \t]*(?:\*[ \t]*)?)/', $line, $prefix_match );
		$prefix  = $prefix_match[1] ?? '';
		$newline = str_contains( $document, "\r\n" ) ? "\r\n" : "\n";
		$block   = $prefix . 'x-release-please-start-version' . $newline
			. $line . $newline . $prefix . 'x-release-please-end';
		return substr_replace( $document, $block, $offset, strlen( $line ) );
	}
}
