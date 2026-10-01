<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use InvalidArgumentException;

/**
 * Bounded, immutable Git tree and selected text blobs read at one exact SHA.
 */
final readonly class RepositorySnapshot {
	private const MAX_ENTRIES   = 2000;
	private const MAX_DOCUMENTS = 256;
	private const MAX_DOCUMENT  = 262144;

	/**
	 * @param array<string, array{type:string,mode:string,sha:string,size:int}> $entries
	 * @param array<string, string>                                            $documents
	 * @param array<string, string>                                            $blob_prefixes
	 */
	public function __construct(
		private string $repository_id,
		private string $repository,
		private string $default_branch,
		private string $sha,
		private array $entries,
		private array $documents,
		private array $blob_prefixes = array()
	) {
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $repository_id )
			|| 1 !== preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $repository )
			|| ! self::valid_branch( $default_branch )
			|| 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $sha )
			|| count( $entries ) > self::MAX_ENTRIES
			|| count( $documents ) + count( $blob_prefixes ) > self::MAX_DOCUMENTS ) {
			throw new InvalidArgumentException( 'Repository snapshot identity or bounds are invalid.' );
		}

		foreach ( $entries as $path => $entry ) {
			if ( ! self::valid_path( $path ) || ! is_array( $entry )
				|| ! in_array( $entry['type'] ?? null, array( 'blob', 'tree' ), true )
				|| ! in_array( $entry['mode'] ?? null, array( '100644', '100755', '040000' ), true )
				|| ( 'tree' === ( $entry['type'] ?? null ) ) !== ( '040000' === ( $entry['mode'] ?? null ) )
				|| 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', (string) ( $entry['sha'] ?? '' ) )
				|| ! is_int( $entry['size'] ?? null ) || $entry['size'] < 0 ) {
				throw new InvalidArgumentException( 'Repository snapshot contains an unsupported tree entry.' );
			}
		}

		foreach ( $documents as $path => $document ) {
			if ( ! isset( $entries[ $path ] ) || 'blob' !== $entries[ $path ]['type'] || ! is_string( $document ) || strlen( $document ) > self::MAX_DOCUMENT
				|| strlen( $document ) !== $entries[ $path ]['size'] || 1 !== preg_match( '//u', $document )
				|| str_contains( $document, "\0" ) ) {
				throw new InvalidArgumentException( 'Repository snapshot contains an invalid document.' );
			}
		}
		foreach ( $blob_prefixes as $path => $prefix ) {
			if ( ! isset( $entries[ $path ] ) || 'blob' !== $entries[ $path ]['type'] || ! is_string( $prefix )
				|| strlen( $prefix ) !== min( 43, $entries[ $path ]['size'] ) ) {
				throw new InvalidArgumentException( 'Repository snapshot contains an invalid blob prefix.' );
			}
		}
	}
	/** Binary-safe, bounded prefix from the exact tree blob; not an assessment document. */
	public function blob_prefix( string $path ): ?string {
		return isset( $this->documents[ $path ] ) ? substr( $this->documents[ $path ], 0, 43 ) : ( $this->blob_prefixes[ $path ] ?? null );
	}
	public function repository_id(): string {
		return $this->repository_id;
	}
	public function repository(): string {
		return $this->repository;
	}
	public function default_branch(): string {
		return $this->default_branch;
	}
	public function sha(): string {
		return $this->sha;
	}
	/** @return array<string, array{type:string,mode:string,sha:string,size:int}> */
	public function entries(): array {
		return $this->entries;
	}
	public function has( string $path ): bool {
		return isset( $this->entries[ $path ] );
	}
	public function document( string $path ): ?string {
		return $this->documents[ $path ] ?? null;
	}
	/** @return list<string> */
	public function document_paths(): array {
		$paths = array_keys( $this->documents );
		sort( $paths, SORT_STRING );
		return $paths;
	}
	/** Number of inspected blobs consumed by the GitHub client readback budget. */
	public function inspected_blob_count(): int {
		return count( $this->documents ) + count( $this->blob_prefixes );
	}
	private static function valid_path( string $path ): bool {
		return '' !== $path && strlen( $path ) <= 512 && ! str_starts_with( $path, '/' )
			&& ! str_contains( $path, "\0" ) && ! str_contains( $path, '\\' )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(/|\z)#', $path )
			&& 1 === preg_match( '//u', $path );
	}
	private static function valid_branch( string $branch ): bool {
		return strlen( $branch ) <= 191
			&& 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $branch )
			&& ! str_contains( $branch, '..' ) && ! str_contains( $branch, '//' )
			&& ! str_contains( $branch, '@{' ) && ! str_ends_with( $branch, '.lock' );
	}
}
