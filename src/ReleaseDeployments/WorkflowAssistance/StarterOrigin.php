<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use Throwable;

/** Passive provenance only: never grants ownership or write authority. */
final class StarterOrigin {
	public const PACK_REPOSITORY   = 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates';
	public const SHARED_REPOSITORY = 'RocketsAreNostalgic/.github';
	public const SHARED_COMMIT     = '63c4a4b192bbb4cf203dab281b75a0907e85c3a9';

	public static function encode( TemplatePack $pack, string $profile ): string {
		$identity = $pack->identity();
		$rendered = $pack->render( $profile, 'release-workflow', array( 'PACKAGE_SLUG' => 'origin-check' ) );
		$pin      = 'ok' === $rendered['code'] ? self::workflow_pin( $rendered['content'] ) : null;
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
				$jobs                        = 'jobs:' === $key;
				$release                     = false;
				if ( $jobs ) {
					if ( $jobs_seen ) {
						return null;
					}
					$jobs_seen = true;
				}
			} elseif ( $jobs && 2 === $indent ) {
				if ( 'release:' !== $key || $release_seen ) {
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

	/** Bounded metadata JSON with duplicate/escaped-equivalent key rejection. @return array<string,mixed>|null */
	public static function json( string $bytes, int $limit ): ?array {
		if ( strlen( $bytes ) > $limit || str_contains( $bytes, "\0" ) ) {
			return null;
		}
		try {
			$data = json_decode( $bytes, true, 24, JSON_THROW_ON_ERROR );
			preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:]/s', $bytes, $matches );
			$stack = array();
			foreach ( $matches[0] as $index => $token ) {
				if ( '{' === $token || '[' === $token ) {
					$stack[] = array(); } elseif ( '}' === $token || ']' === $token ) {
					array_pop( $stack ); } elseif ( str_starts_with( $token, '"' ) && ':' === ( $matches[0][ $index + 1 ] ?? '' ) ) {
										$key = json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
						$level               = count( $stack ) - 1;
						if ( $level < 0 || isset( $stack[ $level ][ $key ] ) ) {
							return null;
						}
						$stack[ $level ][ $key ] = true;
					}
			}
			return is_array( $data ) ? $data : null;
		} catch ( Throwable ) {
			return null;
		}
	}
	public static function version( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 63 && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $value );
	}
	public static function hash( mixed $value, int $length ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{' . $length . '}\z/D', $value );
	}
}
