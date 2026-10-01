<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use InvalidArgumentException;

/** Immutable source-ready profile decision and the bounded edits it authorises. */
final readonly class SourceReadyAssessment {
	private const REFUSALS = array(
		'package_ambiguous',
		'release_automation_conflict',
		'release_path_conflict',
		'repository_unsupported',
		'runtime_paths_unknown',
		'version_contract_custom',
		'version_mismatch',
	);

	/**
	 * @param list<string>              $release_files
	 * @param array<string,string>      $modified_files
	 * @param list<array<string,mixed>> $extra_files
	 */
	private function __construct(
		private string $code,
		private string $profile,
		private string $package_slug,
		private string $header_path,
		private string $version,
		private array $release_files,
		private array $modified_files,
		private array $extra_files,
		private string $php_version
	) {
		$ready = 'source_ready' === $code;
		if ( ( ! $ready && ! in_array( $code, self::REFUSALS, true ) )
			|| ( ! $ready && ( '' !== $profile || '' !== $package_slug || '' !== $header_path || '' !== $version
				|| array() !== $release_files || array() !== $modified_files || array() !== $extra_files ) )
			|| ( $ready && ( ! in_array( $profile, array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ), true )
				|| 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $package_slug )
				|| ! StarterOrigin::version( $version )
				|| strlen( $package_slug ) > 100 || strlen( $version ) > 63
				|| ! in_array( $php_version, array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), true )
				|| array() === $release_files || count( $release_files ) > 2000 || count( $modified_files ) > 256
				|| count( $extra_files ) > 2 || ! array_is_list( $release_files ) || ! array_is_list( $extra_files )
				|| count( $release_files ) !== count( array_unique( $release_files ) ) || ! in_array( $header_path, $release_files, true ) ) ) ) {
			throw new InvalidArgumentException( 'Source-ready assessment is incomplete.' );
		}
		if ( ! $ready ) {
			return;
		}
		foreach ( $extra_files as $extra ) {
			if ( ! is_array( $extra ) || ! in_array( $extra['type'] ?? null, array( 'generic' ), true )
				|| ( 'generic' === $extra['type'] && array_keys( $extra ) !== array( 'type', 'path' ) ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains an invalid version source.' );
			}
		}
		foreach ( array_merge( array( $header_path ), $release_files, array_keys( $modified_files ), array_column( $extra_files, 'path' ) ) as $path ) {
			if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 512 || str_starts_with( $path, '/' )
				|| str_contains( $path, '\\' ) || str_contains( $path, "\0" ) || 1 !== preg_match( '//u', $path )
				|| 1 === preg_match( '#(?:\A|/)\.\.?(/|\z)#', $path ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains an invalid path.' );
			}
		}
		foreach ( $modified_files as $content ) {
			if ( ! is_string( $content ) || strlen( $content ) > 262144 || str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains invalid content.' );
			}
		}
	}

	/**
	 * @param list<string>              $release_files
	 * @param array<string,string>      $modified_files
	 * @param list<array<string,mixed>> $extra_files
	 */
	public static function ready(
		string $profile,
		string $package_slug,
		string $header_path,
		string $version,
		array $release_files,
		array $modified_files,
		array $extra_files,
		string $php_version
	): self {
		return new self( 'source_ready', $profile, $package_slug, $header_path, $version, $release_files, $modified_files, $extra_files, $php_version );
	}
	public static function refused( string $code ): self {
		return new self( $code, '', '', '', '', array(), array(), array(), '' );
	}
	public function ready_for_bootstrap(): bool {
		return 'source_ready' === $this->code;
	}
	public function php_version(): string {
		return $this->php_version;
	}
	public function code(): string {
		return $this->code;
	}
	public function profile(): string {
		return $this->profile;
	}
	public function package_slug(): string {
		return $this->package_slug;
	}
	public function header_path(): string {
		return $this->header_path;
	}
	public function version(): string {
		return $this->version;
	}
	/** @return list<string> */
	public function release_files(): array {
		return $this->release_files;
	}
	/** @return array<string,string> */
	public function modified_files(): array {
		return $this->modified_files;
	}
	/** @return list<array<string,mixed>> */
	public function extra_files(): array {
		return $this->extra_files;
	}
}
