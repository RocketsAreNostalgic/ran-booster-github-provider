<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use Throwable;

/** Passive provenance only: never grants ownership or write authority. */
final class StarterOrigin {
	public const PACK_REPOSITORY   = 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates';
	public const SHARED_REPOSITORY = 'RocketsAreNostalgic/.github';
	public const SHARED_COMMIT     = '63c4a4b192bbb4cf203dab281b75a0907e85c3a9';

	/** @internal The bundle supplies the workflow bytes rendered from this verified pack. */
	public static function encode( TemplatePack $pack, string $profile, string $actual_workflow ): string {
		$identity = $pack->identity();
		$pin      = self::workflow_pin( $actual_workflow );
		if ( null === $pin ) {
			throw new \RuntimeException( 'Shared workflow provenance is unavailable.' );
		}
		$origin = array(
			'schema'           => 'ran-release-starter-origin',
			'schema_version'   => 1,
			'pack'             => array(
				'repository'    => $identity['repository_name'],
				'repository_id' => $identity['repository_id'],
				'version'       => $pack->pack_version(),
				'tag'           => $identity['release_tag'],
				'commit'        => $identity['release_commit'],
				'zip_sha256'    => $identity['asset_sha256'],
				'profile'       => $profile,
			),
			'shared_profile_b' => array(
				'repository' => self::SHARED_REPOSITORY,
				'commit'     => $pin,
			),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact deterministic passive metadata.
		return json_encode( $origin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	}

	/** Accept only the one reusable-workflow call at jobs.release.uses. */
	private static function workflow_pin( string $workflow ): ?string {
		$lines = preg_split( '/\r?\n/', $workflow );
		if ( ! is_array( $lines ) ) {
			return null;
		}
		$jobs         = false;
		$release      = false;
		$jobs_seen    = false;
		$release_seen = false;
		$pin          = null;
		$root_keys    = array();
		$pattern      = '~^uses[ \t]*:[ \t]*(["\x27]?)RocketsAreNostalgic/\.github/\.github/workflows/release-profile-b\.yml@([a-f0-9]{40})\1[ \t]*(?:\#[^\r\n]*)?$~';
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}
			$indent = strspn( $line, ' ' );
			if ( "\t" === ( $line[ $indent ] ?? '' ) ) {
				return null;
			}
			$key = substr( $line, $indent );
			if ( 0 === $indent ) {
				if ( 1 !== preg_match( '/\A(name|on|permissions|jobs):(?:\s|\z)/', $key, $root_match ) || isset( $root_keys[ $root_match[1] ] ) ) {
					return null;
				}
				$root_keys[ $root_match[1] ] = true;
				$jobs                        = 'jobs' === $root_match[1];
				if ( $jobs && 1 !== preg_match( '/\Ajobs:(?:[ \t]+#[^\r\n]*|[ \t]*)\z/', $key ) ) {
					return null;
				}
				$release = false;
				if ( $jobs ) {
					if ( $jobs_seen ) {
						return null;
					}
					$jobs_seen = true;
				}
			} elseif ( $jobs && 2 === $indent ) {
				if ( 1 !== preg_match( '/\Arelease:(?:[ \t]+#[^\r\n]*|[ \t]*)\z/', $key ) || $release_seen ) {
					return null;
				}
				$release_seen = true;
				$release      = true;
			} elseif ( $jobs && ( 1 === $indent || 3 === $indent ) ) {
				return null;
			} elseif ( $jobs && $release && 4 === $indent && 1 === preg_match( '/\Auses[ \t]*:/', $key ) ) {
				if ( null !== $pin || 1 !== preg_match( $pattern, $key, $match ) ) {
					return null;
				}
				$pin = $match[2];
			}
		}
		return $jobs_seen && $release_seen ? $pin : null;
	}

	/** @return array<string,mixed>|null */
	public static function decode( string $bytes ): ?array {
		$data = self::json( $bytes, 8192 );
		if ( null === $data || array_keys( $data ) !== array( 'schema', 'schema_version', 'pack', 'shared_profile_b' )
			|| 'ran-release-starter-origin' !== $data['schema'] || 1 !== $data['schema_version']
			|| ! is_array( $data['pack'] ) || array_keys( $data['pack'] ) !== array( 'repository', 'repository_id', 'version', 'tag', 'commit', 'zip_sha256', 'profile' )
			|| self::PACK_REPOSITORY !== $data['pack']['repository'] || '1322743261' !== $data['pack']['repository_id']
			|| ! self::version( $data['pack']['version'] ) || 'v' . $data['pack']['version'] !== $data['pack']['tag']
			|| ! self::hash( $data['pack']['commit'], 40 ) || ! self::hash( $data['pack']['zip_sha256'], 64 )
			|| ! in_array( $data['pack']['profile'], array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ), true )
			|| ! is_array( $data['shared_profile_b'] ) || array_keys( $data['shared_profile_b'] ) !== array( 'repository', 'commit' )
			|| self::SHARED_REPOSITORY !== $data['shared_profile_b']['repository'] || ! self::hash( $data['shared_profile_b']['commit'], 40 ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Bounded metadata JSON with duplicate/escaped-equivalent key rejection.
	 *
	 * @param int<1,max> $depth
	 * @return array<array-key,mixed>|null
	 */
	public static function json( string $bytes, int $limit, int $depth = 24, int $flags = 0 ): ?array {
		if ( strlen( $bytes ) > $limit || str_contains( $bytes, "\0" ) ) {
			return null;
		}
		try {
			$data = json_decode( $bytes, true, $depth, JSON_THROW_ON_ERROR | $flags );
			if ( ! is_array( $data ) || ! self::has_unique_object_keys_after_decode( $bytes ) ) {
				return null;
			}
			return $data;
		} catch ( Throwable ) {
			return null;
		}
	}

	/** Reject duplicate JSON keys, including equivalent escaped spellings, failing closed on malformed JSON. */
	public static function has_unique_object_keys( string $bytes ): bool {
		if ( str_contains( $bytes, "\0" ) ) {
			return false;
		}
		try {
			json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return false;
		}

		return self::has_unique_object_keys_after_decode( $bytes );
	}

	/** Reject duplicate JSON keys after syntax validation without re-decoding the full document. */
	private static function has_unique_object_keys_after_decode( string $bytes ): bool {
		if ( false === preg_match_all( '/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\]:]/s', $bytes, $matches ) ) {
			return false;
		}
		$stack  = array();
		$tokens = $matches[0];
		foreach ( $tokens as $index => $token ) {
			if ( '{' === $token || '[' === $token ) {
				$stack[] = array();
			} elseif ( '}' === $token || ']' === $token ) {
				array_pop( $stack );
			} elseif ( str_starts_with( $token, '"' ) && ':' === ( $tokens[ $index + 1 ] ?? '' ) ) {
				try {
					$key = json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
				} catch ( Throwable ) {
					return false;
				}
				if ( ! is_string( $key ) ) {
					return false;
				}
				$level    = count( $stack ) - 1;
				$seen_key = "\0" . $key;
				if ( $level < 0 || isset( $stack[ $level ][ $seen_key ] ) ) {
					return false;
				}
				$stack[ $level ][ $seen_key ] = true;
			}
		}

		return true;
	}
	public static function version( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 63 && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $value );
	}
	public static function hash( mixed $value, int $length ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . $length . '}\z/D', $value );
	}
}
