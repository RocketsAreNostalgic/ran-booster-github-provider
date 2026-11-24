<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

/**
 * Canonical validator and encoder for GitHub owners, repositories and repository URLs.
 *
 * @internal
 */
final class GitHubRepositoryIdentity {

	private const GITHUB_URL_PREFIX = 'https://github.com/';

	public static function is_valid_owner( mixed $owner ): bool {
		return is_string( $owner )
			&& '' !== $owner
			&& strlen( $owner ) <= 39
			&& 1 === preg_match( '/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/D', $owner );
	}

	public static function is_valid_repository_name( mixed $name ): bool {
		return is_string( $name )
			&& '' !== $name
			&& strlen( $name ) <= 100
			&& '.' !== $name
			&& '..' !== $name
			&& 1 === preg_match( '/\A[A-Za-z0-9._-]+\z/D', $name );
	}

	/**
	 * Validate a canonical GitHub owner/repository locator.
	 *
	 * @phpstan-assert-if-true =string $repository
	 */
	public static function is_valid_repository( mixed $repository ): bool {
		if ( ! is_string( $repository ) || 1 !== substr_count( $repository, '/' ) ) {
			return false;
		}

		list( $owner, $name ) = explode( '/', $repository, 2 );

		return self::is_valid_owner( $owner ) && self::is_valid_repository_name( $name );
	}

	public static function encode_repository( string $repository ): ?string {
		if ( ! self::is_valid_repository( $repository ) ) {
			return null;
		}

		list( $owner, $name ) = explode( '/', $repository, 2 );

		return rawurlencode( $owner ) . '/' . rawurlencode( $name );
	}

	public static function parse_github_url( mixed $url, bool $allow_trailing_slash = false ): ?string {
		if ( ! is_string( $url ) || ! str_starts_with( $url, self::GITHUB_URL_PREFIX ) ) {
			return null;
		}

		$repository = substr( $url, strlen( self::GITHUB_URL_PREFIX ) );
		if ( $allow_trailing_slash && str_ends_with( $repository, '/' ) ) {
			$repository = substr( $repository, 0, -1 );
		}

		return self::is_valid_repository( $repository ) ? $repository : null;
	}

	public static function is_valid_github_url( mixed $url, bool $allow_trailing_slash = false ): bool {
		return null !== self::parse_github_url( $url, $allow_trailing_slash );
	}
}
