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
	 * @param list<string>              $releaseFiles
	 * @param array<string,string>      $modifiedFiles
	 * @param list<array<string,mixed>> $extraFiles
	 */
	private function __construct(
		private string $code,
		private string $profile,
		private string $packageSlug,
		private string $headerPath,
		private string $version,
		private array $releaseFiles,
		private array $modifiedFiles,
		private array $extraFiles,
		private string $phpVersion
	) {
		$ready = 'source_ready' === $code;
		if ( ( ! $ready && ! in_array( $code, self::REFUSALS, true ) )
			|| ( ! $ready && ( '' !== $profile || '' !== $packageSlug || '' !== $headerPath || '' !== $version
				|| array() !== $releaseFiles || array() !== $modifiedFiles || array() !== $extraFiles ) )
			|| ( $ready && ( ! in_array( $profile, array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ), true )
				|| 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $packageSlug )
				|| ! StarterOrigin::version( $version )
				|| strlen( $packageSlug ) > 100 || strlen( $version ) > 63
				|| ! in_array( $phpVersion, array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), true )
				|| array() === $releaseFiles || count( $releaseFiles ) > 2000 || count( $modifiedFiles ) > 256
				|| count( $extraFiles ) > 2 || ! array_is_list( $releaseFiles ) || ! array_is_list( $extraFiles )
				|| count( $releaseFiles ) !== count( array_unique( $releaseFiles ) ) || ! in_array( $headerPath, $releaseFiles, true ) ) ) ) {
			throw new InvalidArgumentException( 'Source-ready assessment is incomplete.' );
		}
		if ( ! $ready ) {
			return;
		}
		foreach ( $extraFiles as $extra ) {
			if ( ! is_array( $extra ) || ! in_array( $extra['type'] ?? null, array( 'generic' ), true )
				|| ( 'generic' === $extra['type'] && array_keys( $extra ) !== array( 'type', 'path' ) ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains an invalid version source.' );
			}
		}
		foreach ( array_merge( array( $headerPath ), $releaseFiles, array_keys( $modifiedFiles ), array_column( $extraFiles, 'path' ) ) as $path ) {
			if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 512 || str_starts_with( $path, '/' )
				|| str_contains( $path, '\\' ) || str_contains( $path, "\0" ) || 1 !== preg_match( '//u', $path )
				|| 1 === preg_match( '#(?:\A|/)\.\.?(/|\z)#', $path ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains an invalid path.' );
			}
		}
		foreach ( $modifiedFiles as $content ) {
			if ( ! is_string( $content ) || strlen( $content ) > 262144 || str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
				throw new InvalidArgumentException( 'Source-ready assessment contains invalid content.' );
			}
		}
	}

	/**
	 * @param list<string>              $releaseFiles
	 * @param array<string,string>      $modifiedFiles
	 * @param list<array<string,mixed>> $extraFiles
	 */
	public static function ready(
		string $profile,
		string $packageSlug,
		string $headerPath,
		string $version,
		array $releaseFiles,
		array $modifiedFiles,
		array $extraFiles,
		string $phpVersion
	): self {
		return new self( 'source_ready', $profile, $packageSlug, $headerPath, $version, $releaseFiles, $modifiedFiles, $extraFiles, $phpVersion );
	}
	public static function refused( string $code ): self {
		return new self( $code, '', '', '', '', array(), array(), array(), '' );
	}
	public function readyForBootstrap(): bool {
		return 'source_ready' === $this->code;
	}
	public function phpVersion(): string {
		return $this->phpVersion;
	}
	public function code(): string {
		return $this->code;
	}
	public function profile(): string {
		return $this->profile;
	}
	public function packageSlug(): string {
		return $this->packageSlug;
	}
	public function headerPath(): string {
		return $this->headerPath;
	}
	public function version(): string {
		return $this->version;
	}
	/** @return list<string> */
	public function releaseFiles(): array {
		return $this->releaseFiles;
	}
	/** @return array<string,string> */
	public function modifiedFiles(): array {
		return $this->modifiedFiles;
	}
	/** @return list<array<string,mixed>> */
	public function extraFiles(): array {
		return $this->extraFiles;
	}
}
