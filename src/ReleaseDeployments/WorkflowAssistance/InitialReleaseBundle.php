<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use RuntimeException;
use Throwable;

/** One deterministic, previewable source-ready bootstrap tree. */
final readonly class InitialReleaseBundle {
	public const ORIGIN_PATH   = '.ran-booster-release-starter.json';
	public const WORKFLOW_PATH = '.github/workflows/release-please.yml';
	/** @var array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string}> */
	private array $files;
	private string $hash;
	private string $changed_path_hash;
	private string $allowlist_hash;

	/**
	 * @param array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string}> $files
	 * @param array<string,mixed> $pack_identity
	 */
	private function __construct(
		private string $profile,
		private string $pack_version,
		private array $pack_identity,
		private string $manifest_hash,
		array $files,
		string $allowlist
	) {
		ksort( $files, SORT_STRING );
		$this->files             = $files;
		$this->changed_path_hash = hash( 'sha256', implode( "\n", array_keys( $files ) ) . "\n" );
		$this->allowlist_hash    = hash( 'sha256', $allowlist );
		$this->hash              = hash(
			'sha256',
			self::json(
				array(
					'profile'           => $profile,
					'pack_version'      => $pack_version,
					'pack_identity'     => $pack_identity,
					'manifest_hash'     => $manifest_hash,
					'changed_path_hash' => $this->changed_path_hash,
					'allowlist_hash'    => $this->allowlist_hash,
					'files'             => array_map(
						static fn ( array $file ): array => array(
							'path'      => $file['path'],
							'mode'      => $file['mode'],
							'operation' => $file['operation'],
							'sha256'    => $file['sha256'],
							'git_sha'   => $file['git_sha'],
						),
						$files
					),
				)
			)
		);
	}

	/** @return array{code:string,bundle?:self} */
	public static function bootstrap(
		TemplatePack $pack,
		SourceReadyAssessment $assessment,
		RepositorySnapshot $snapshot,
		string $update_uri
	): array {
		if ( ! $assessment->ready_for_bootstrap() || ! in_array( $assessment->profile(), $pack->profiles(), true )
			|| ! hash_equals( 'https://github.com/' . $snapshot->repository(), rtrim( $update_uri, '/' ) ) ) {
			return array( 'code' => 'invalid_bundle' );
		}

		try {
			$extra_files = self::json( $assessment->extra_files(), false );
			$rendered    = array(
				self::WORKFLOW_PATH             => self::render(
					$pack,
					$assessment->profile(),
					'release-workflow',
					array(
						'PACKAGE_SLUG' => $assessment->package_slug(),
					)
				),
				'release-please-config.json'    => self::render(
					$pack,
					$assessment->profile(),
					'release-please-config',
					array(
						'BASE_SHA'         => $snapshot->sha(),
						'EXTRA_FILES_JSON' => $extra_files,
						'PACKAGE_SLUG'     => $assessment->package_slug(),
					)
				),
				'scripts/build-release.sh'      => self::render(
					$pack,
					$assessment->profile(),
					'build-release-script',
					array(
						'HEADER_PATH'  => $assessment->header_path(),
						'PACKAGE_SLUG' => $assessment->package_slug(),
						'PACKAGE_TYPE' => self::package_type( $assessment->profile() ),
					)
				),
				'scripts/verify-release.sh'     => self::render(
					$pack,
					$assessment->profile(),
					'verify-release-script',
					array(
						'HEADER_PATH'  => $assessment->header_path(),
						'PACKAGE_SLUG' => $assessment->package_slug(),
						'PACKAGE_TYPE' => self::package_type( $assessment->profile() ),
						'UPDATE_URI'   => rtrim( $update_uri, '/' ),
					)
				),
				'.github/workflows/quality.yml' => self::render(
					$pack,
					$assessment->profile(),
					'quality-workflow',
					array(
						'PACKAGE_SLUG' => $assessment->package_slug(),
						'PHP_VERSION'  => $assessment->php_version(),
					)
				),
			);
			if ( in_array( null, $rendered, true ) ) {
				return array( 'code' => 'invalid_bundle' );
			}

			$allowlist = implode( "\n", $assessment->release_files() ) . "\n";
			$generated = array_merge(
				$rendered,
				array(
					'.release-please-manifest.json' => self::json( array( '.' => $assessment->version() ) ),
					'version.txt'                   => $assessment->version() . "\n",
					'release-contents.txt'          => $allowlist,
					self::ORIGIN_PATH               => StarterOrigin::encode( $pack, $assessment->profile() ),
					'RELEASE-STARTER.md'            => StarterGuidance::render( $pack, $assessment->profile() ),
				)
			);

			$files = array();
			foreach ( $generated as $path => $content ) {
				if ( ! is_string( $content ) || $snapshot->has( $path ) ) {
					return array( 'code' => 'invalid_bundle' );
				}
				$files[ $path ] = self::file( $path, $content, '100644', 'added' );
			}
			foreach ( $assessment->modified_files() as $path => $content ) {
				if ( $snapshot->has( $path ) && $snapshot->document( $path ) === $content ) {
					continue;
				}
				$files[ $path ] = self::file( $path, $content, '100644', $snapshot->has( $path ) ? 'modified' : 'added' );
			}

			return array(
				'code'   => 'ok',
				'bundle' => new self( $assessment->profile(), $pack->pack_version(), $pack->identity(), $pack->manifest_hash(), $files, $allowlist ),
			);
		} catch ( Throwable ) {
			return array( 'code' => 'invalid_bundle' );
		}
	}

	public function profile(): string {
		return $this->profile;
	}

	public function pack_version(): string {
		return $this->pack_version;
	}

	/** @return array<string,mixed> */
	public function pack_identity(): array {
		return $this->pack_identity;
	}

	public function manifest_hash(): string {
		return $this->manifest_hash;
	}

	public function hash(): string {
		return $this->hash;
	}

	public function changed_path_hash(): string {
		return $this->changed_path_hash;
	}

	public function allowlist_hash(): string {
		return $this->allowlist_hash;
	}

	/** @return array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string}> */
	public function files(): array {
		return $this->files;
	}

	/** @return list<array{path:string,status:string,sha:string}> */
	public function expected_pull_files(): array {
		return array_values(
			array_map(
				static fn ( array $file ): array => array(
					'path'   => $file['path'],
					'status' => $file['operation'],
					'sha'    => $file['git_sha'],
				),
				$this->files
			)
		);
	}

	/** @return array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string} */
	private static function file( string $path, string $content, string $mode, string $operation ): array {
		if ( '' === $path || strlen( $content ) > 262144 || ! in_array( $mode, array( '100644' ), true )
			|| ! in_array( $operation, array( 'added', 'modified' ), true ) ) {
			throw new RuntimeException( 'Initial release bundle file is invalid.' );
		}
		return array(
			'path'      => $path,
			'mode'      => $mode,
			'operation' => $operation,
			'content'   => $content,
			'sha256'    => hash( 'sha256', $content ),
			'git_sha'   => sha1( 'blob ' . strlen( $content ) . "\0" . $content ),
		);
	}

	/** @param array<string,mixed> $values */
	private static function render( TemplatePack $pack, string $profile, string $logical_id, array $values ): ?string {
		$result = $pack->render( $profile, $logical_id, $values );
		return 'ok' === $result['code'] && is_string( $result['content'] ?? null ) ? $result['content'] : null;
	}

	private static function package_type( string $profile ): string {
		return str_contains( $profile, '-theme/' ) ? 'theme' : 'plugin';
	}

	private static function json( mixed $value, bool $pretty = true ): string {
		$options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		if ( $pretty ) {
			$options |= JSON_PRETTY_PRINT;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Throwing deterministic JSON is required for the reviewed bundle hash.
		return json_encode( $value, $options ) . ( $pretty ? "\n" : '' );
	}
}
