<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use Closure;
use Throwable;

/** On-demand public advisory read. Never authorises adoption, execution or a write. */
final class StarterSecurityCheck {
	private Closure $send;

	public function __construct( ?callable $send = null ) {
		$this->send = null === $send
			? static fn ( string $url, array $args ): mixed => wp_safe_remote_get( $url, $args )
			: Closure::fromCallable( $send );
	}

	/**
	 * The caller supplies origin bytes from its already identity-verified, exact repository revision.
	 * Missing provenance does not block ordinary adoption. Core wiring is a separate contract.
	 * @return array{status:string,matches:list<array<string,mixed>>}
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
	public function check( string $originBytes, string $token = '' ): array {
		$unknown = array(
			'status'  => 'unknown',
			'matches' => array(),
		);
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public API and named-parameter compatibility pending coordinated naming.
		$origin = StarterOrigin::decode( $originBytes );
		if ( null === $origin ) {
			return $unknown;
		}
		$repository = $this->read( '/repos/' . StarterOrigin::PACK_REPOSITORY, $token );
		if ( null === $repository || StarterOrigin::PACK_REPOSITORY !== ( $repository['full_name'] ?? null )
			|| '1322743261' !== (string) ( $repository['id'] ?? '' )
			|| ! is_string( $repository['default_branch'] ?? null ) || '' === $repository['default_branch'] ) {
			return $unknown;
		}
		$index = $this->read( '/repos/' . StarterOrigin::PACK_REPOSITORY . '/contents/security/release-starter-advisories.json?ref=' . rawurlencode( $repository['default_branch'] ), $token, true );
		if ( null === $index || ! self::valid_index( $index ) ) {
			return $unknown;
		}
		// Two reads above plus one published-GHSA read per entry exceed the 60-call
		// anonymous hourly ceiling when there are more than 58 advisories.
		if ( '' === $token && count( $index['advisories'] ) > 58 ) {
			return $unknown;
		}
		$matches = array();
		foreach ( $index['advisories'] as $entry ) {
			$advisory = $this->read( '/repos/' . $entry['repository'] . '/security-advisories/' . $entry['ghsa_id'], $token );
			if ( null === $advisory || $entry['ghsa_id'] !== ( $advisory['ghsa_id'] ?? null )
				|| 'https://api.github.com/repos/' . $entry['repository'] . '/security-advisories/' . $entry['ghsa_id'] !== ( $advisory['url'] ?? null )
				|| 'https://github.com/' . $entry['repository'] . '/security/advisories/' . $entry['ghsa_id'] !== ( $advisory['html_url'] ?? null )
				|| 'published' !== ( $advisory['state'] ?? null ) || ! is_string( $advisory['published_at'] ?? null ) || '' === $advisory['published_at']
				|| ! array_key_exists( 'withdrawn_at', $advisory ) || null !== $advisory['withdrawn_at'] ) {
				return $unknown;
			}
			if ( in_array( $origin['pack']['version'], $entry['affected']['pack_versions'], true )
				|| in_array( $origin['shared_profile_b']['commit'], $entry['affected']['shared_profile_b_commits'], true ) ) {
				$matches[] = $entry + array( 'url' => 'https://github.com/' . $entry['repository'] . '/security/advisories/' . $entry['ghsa_id'] );
			}
		}
		return array(
			'status'  => array() === $matches ? 'no_matching_known_advisory' : 'matching_advisory',
			'matches' => $matches,
		);
	}

	/** @param array<string,mixed> $index */
	private static function valid_index( array $index ): bool {
		if ( ! self::keys( $index, array( 'schema', 'schema_version', 'advisories' ) )
			|| 'ran-release-starter-advisories' !== $index['schema'] || 1 !== $index['schema_version']
			|| ! is_array( $index['advisories'] ) || ! array_is_list( $index['advisories'] ) || count( $index['advisories'] ) > 64 ) {
			return false;
		}
		$seen = array();
		foreach ( $index['advisories'] as $entry ) {
			if ( ! is_array( $entry ) || ! self::keys( $entry, array( 'ghsa_id', 'repository', 'affected', 'fixed' ) )
				|| ! is_string( $entry['ghsa_id'] ) || 1 !== preg_match( '/\AGHSA-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}\z/D', $entry['ghsa_id'] )
				|| isset( $seen[ $entry['ghsa_id'] ] ) || ! in_array( $entry['repository'], array( StarterOrigin::PACK_REPOSITORY, StarterOrigin::SHARED_REPOSITORY ), true )
				|| ! is_array( $entry['affected'] ) || ! self::keys( $entry['affected'], array( 'pack_versions', 'shared_profile_b_commits' ) )
				|| ! is_array( $entry['fixed'] ) || ! self::keys( $entry['fixed'], array( 'pack_version', 'shared_profile_b_commit' ) ) ) {
				return false;
			}
			$seen[ $entry['ghsa_id'] ] = true;
			$is_pack                   = StarterOrigin::PACK_REPOSITORY === $entry['repository'];
			$active                    = $is_pack ? 'pack_versions' : 'shared_profile_b_commits';
			$inactive                  = $is_pack ? 'shared_profile_b_commits' : 'pack_versions';
			$fixed                     = $entry['fixed'][ $is_pack ? 'pack_version' : 'shared_profile_b_commit' ];
			$list                      = $entry['affected'][ $active ];
			if ( array() !== $entry['affected'][ $inactive ] || null !== $entry['fixed'][ $is_pack ? 'shared_profile_b_commit' : 'pack_version' ]
				|| ! ( $is_pack ? StarterOrigin::version( $fixed ) : StarterOrigin::hash( $fixed, 40 ) )
				|| ! is_array( $list ) || ! array_is_list( $list ) || array() === $list ) {
				return false;
			}
			$identities = array();
			foreach ( $list as $value ) {
				if ( ! ( $is_pack ? StarterOrigin::version( $value ) : StarterOrigin::hash( $value, 40 ) ) || isset( $identities[ $value ] ) || $value === $fixed ) {
					return false;
				}
				$identities[ $value ] = true;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $value @param list<string> $keys */
	private static function keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $keys );
		return $actual === $keys;
	}

	/** @return array<string,mixed>|null */
	private function read( string $path, string $token, bool $raw = false ): ?array {
		try {
			$headers = array(
				'Accept'               => $raw ? 'application/vnd.github.raw+json' : 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			);
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			$response = ( $this->send )(
				'https://api.github.com' . $path,
				array(
					'headers'             => $headers,
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => 65537,
					'reject_unsafe_urls'  => true,
				)
			);
			if ( ! is_array( $response ) || 200 !== ( $response['response']['code'] ?? null ) || ! is_string( $response['body'] ?? null ) || strlen( $response['body'] ) > 65536 ) {
				return null;
			}
			// Preserve JSON array/object distinctions before associative validation.
			if ( $raw ) {
				$shape = json_decode( $response['body'], false, 24, JSON_THROW_ON_ERROR );
				if ( ! $shape instanceof \stdClass || ! is_array( $shape->advisories ?? null ) ) {
					return null;
				}
				foreach ( $shape->advisories as $entry ) {
					if ( ! $entry instanceof \stdClass || ! ( $entry->affected ?? null ) instanceof \stdClass
						|| ! is_array( $entry->affected->pack_versions ?? null ) || ! is_array( $entry->affected->shared_profile_b_commits ?? null ) ) {
						return null;
					}
				}
			}
			return StarterOrigin::json( $response['body'], 65536 );
		} catch ( Throwable ) {
			return null;
		}
	}
}
