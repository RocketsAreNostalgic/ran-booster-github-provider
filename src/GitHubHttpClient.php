<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared GitHub HTTP request construction, guarded redirect transport, rate-limit detection
 * and HTTP error classification.
 *
 * @internal
 */
final class GitHubHttpClient {

	public const API_ORIGIN           = 'https://api.github.com';
	public const API_VERSION          = '2022-11-28';
	public const WORKFLOW_API_VERSION = '2026-03-10';
	public const HTTP_DATE_FORMAT     = 'D, d M Y H:i:s \G\M\T';

	/** @var list<int> */
	public const REDIRECT_STATUSES = array( 301, 302, 303, 307, 308 );

	/**
	 * @return array<string, string>
	 */
	public static function build_headers(
		string $accept = 'application/vnd.github+json',
		string $api_version = self::API_VERSION,
		?string $user_agent = 'RAN-Booster',
		#[\SensitiveParameter] string $token = '',
		?string $content_type = null
	): array {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => $api_version,
		);
		if ( null !== $user_agent ) {
			$headers['User-Agent'] = $user_agent;
		}
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		if ( null !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		}

		return $headers;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	public static function build_request_args(
		float|int $timeout,
		#[\SensitiveParameter] array $headers,
		?int $limit_response_size = null,
		?string $method = null,
		#[\SensitiveParameter] ?string $body = null
	): array {
		$arguments = array();
		if ( null !== $method ) {
			$arguments['method'] = $method;
		}
		$arguments['timeout']     = $timeout;
		$arguments['redirection'] = 0;
		if ( null !== $limit_response_size ) {
			$arguments['limit_response_size'] = $limit_response_size;
		}
		$arguments['reject_unsafe_urls'] = true;
		$arguments['headers']            = $headers;
		if ( null !== $body ) {
			$arguments['body'] = $body;
		}

		return $arguments;
	}

	/**
	 * Execute one bounded GitHub HTTP request with operation-guard checks and optional
	 * allowlisted redirect hops that strip Authorization headers before following.
	 *
	 * @param array<string, mixed>                                  $arguments
	 * @param callable(string, string, array<string, mixed>): mixed $transport
	 * @param list<string>                                          $allowed_redirect_hosts
	 * @return array{response:mixed,status:int,body:string,has_authorization:bool,redirect_rejected:bool,wp_error:bool}
	 */
	public static function send(
		string $method,
		string $url,
		#[\SensitiveParameter] array $arguments,
		callable $transport,
		?Closure $assert_operation = null,
		int $max_redirects = 0,
		array $allowed_redirect_hosts = array()
	): array {
		$current_url                        = $url;
		$current_args                       = $arguments;
		$current_args['redirection']        = 0;
		$current_args['reject_unsafe_urls'] = true;

		for ( $hop = 0; $hop <= $max_redirects; ++$hop ) {
			$assert_operation?->__invoke();
			$response = $transport( $method, $current_url, $current_args );
			$assert_operation?->__invoke();

			$current_headers   = is_array( $current_args['headers'] ?? null ) ? $current_args['headers'] : array();
			$has_authorization = self::has_authorization_header( $current_headers );

			if ( self::is_wp_error_response( $response ) ) {
				return array(
					'response'          => $response,
					'status'            => 0,
					'body'              => '',
					'has_authorization' => $has_authorization,
					'redirect_rejected' => false,
					'wp_error'          => true,
				);
			}

			if ( ! is_array( $response ) ) {
				return array(
					'response'          => $response,
					'status'            => 0,
					'body'              => '',
					'has_authorization' => $has_authorization,
					'redirect_rejected' => false,
					'wp_error'          => false,
				);
			}

			$status = self::response_code( $response );
			if ( $hop < $max_redirects && self::is_redirect_status( $status ) ) {
				$location = self::response_header( $response, 'location' );
				$next_url = self::resolve_and_validate_redirect_location( $current_url, $location, $allowed_redirect_hosts );
				if ( null === $next_url ) {
					return array(
						'response'          => $response,
						'status'            => $status,
						'body'              => self::response_body( $response ),
						'has_authorization' => $has_authorization,
						'redirect_rejected' => true,
						'wp_error'          => false,
					);
				}

				$current_args['headers'] = self::strip_authorization_headers( $current_headers );
				$current_url             = $next_url;
				continue;
			}

			return array(
				'response'          => $response,
				'status'            => $status,
				'body'              => self::response_body( $response ),
				'has_authorization' => $has_authorization,
				'redirect_rejected' => $max_redirects > 0 && self::is_redirect_status( $status ),
				'wp_error'          => false,
			);
		}

		return array(
			'response'          => null,
			'status'            => 0,
			'body'              => '',
			'has_authorization' => false,
			'redirect_rejected' => true,
			'wp_error'          => false,
		);
	}

	/**
	 * @param array<array-key, mixed> $headers
	 * @return array<array-key, mixed>
	 */
	public static function strip_authorization_headers( #[\SensitiveParameter] array $headers ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}

	/**
	 * @param array<array-key, mixed> $headers
	 */
	public static function has_authorization_header( #[\SensitiveParameter] array $headers ): bool {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_redirect_status( int $status ): bool {
		return in_array( $status, self::REDIRECT_STATUSES, true );
	}

	/**
	 * Detect GitHub primary (429, or 403 with x-ratelimit-remaining: 0) and secondary
	 * (403 with numeric or HTTP-date retry-after) rate-limit responses.
	 */
	public static function is_rate_limited_response( mixed $response, int $status ): bool {
		if ( 429 === $status ) {
			return true;
		}

		if ( 403 !== $status ) {
			return false;
		}

		$remaining = self::response_header( $response, 'x-ratelimit-remaining' );
		if ( '0' === trim( $remaining ) ) {
			return true;
		}

		$retry_after = trim( self::response_header( $response, 'retry-after' ) );
		if ( '' === $retry_after ) {
			return false;
		}

		if ( 1 === preg_match( '/\A\d+\z/', $retry_after ) ) {
			return true;
		}

		$retry_at = DateTimeImmutable::createFromFormat(
			'!' . self::HTTP_DATE_FORMAT,
			$retry_after,
			new DateTimeZone( 'GMT' )
		);

		return false !== $retry_at && $retry_at->format( self::HTTP_DATE_FORMAT ) === $retry_after;
	}

	/**
	 * Canonical classification for GitHub HTTP status codes and rate-limit signals.
	 *
	 * @return 'ok'|'bad_request'|'credential_rejected'|'rate_limited'|'permission_denied'|'not_found'|'gone'|'unprocessable'|'redirect_blocked'|'http_4xx_other'|'http_5xx'|'response_invalid'
	 */
	public static function classify_status( int $status, mixed $response = null ): string {
		if ( $status >= 200 && $status < 300 ) {
			return 'ok';
		}
		if ( 401 === $status ) {
			return 'credential_rejected';
		}
		if ( self::is_rate_limited_response( $response, $status ) ) {
			return 'rate_limited';
		}
		if ( 403 === $status ) {
			return 'permission_denied';
		}
		if ( 404 === $status ) {
			return 'not_found';
		}
		if ( 410 === $status ) {
			return 'gone';
		}
		if ( 422 === $status ) {
			return 'unprocessable';
		}
		if ( 400 === $status ) {
			return 'bad_request';
		}
		if ( self::is_redirect_status( $status ) ) {
			return 'redirect_blocked';
		}
		if ( $status >= 400 && $status < 500 ) {
			return 'http_4xx_other';
		}
		if ( $status >= 500 && $status <= 599 ) {
			return 'http_5xx';
		}

		return 'response_invalid';
	}

	public static function http_failure_reason( int $status ): string {
		return match ( true ) {
			self::is_redirect_status( $status ) => 'redirect_blocked',
			$status >= 400 && $status < 500 => 'http_4xx_other',
			$status >= 500 && $status <= 599 => 'http_5xx',
			default => 'response_invalid',
		};
	}

	public static function rate_limit_retry_after( #[\SensitiveParameter] mixed $response ): ?int {
		if ( ! is_array( $response ) ) {
			return null;
		}

		return self::rate_limit_integer( self::response_header( $response, 'retry-after' ), 86400 );
	}

	public static function rate_limit_reset_at( #[\SensitiveParameter] mixed $response ): ?int {
		if ( ! is_array( $response ) ) {
			return null;
		}

		return self::rate_limit_integer( self::response_header( $response, 'x-ratelimit-reset' ), PHP_INT_MAX );
	}

	public static function transport_reason( #[\SensitiveParameter] mixed $error ): string {
		return is_object( $error ) && is_callable( array( $error, 'get_error_code' ) ) && 'http_request_not_executed' === $error->get_error_code()
			? 'blocked_by_site'
			: 'transport_other';
	}

	public static function http_source_code( #[\SensitiveParameter] mixed $error ): ?string {
		$code = is_object( $error ) && is_callable( array( $error, 'get_error_code' ) ) ? $error->get_error_code() : null;

		return is_string( $code ) ? 'wp_http.' . $code : null;
	}

	public static function is_wp_error_response( mixed $response ): bool {
		if ( function_exists( __NAMESPACE__ . '\\is_wp_error' ) || function_exists( 'is_wp_error' ) ) {
			return is_wp_error( $response );
		}

		return $response instanceof \WP_Error;
	}

	public static function response_code( mixed $response ): int {
		if ( ! is_array( $response ) ) {
			return 0;
		}
		if ( function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_response_code' ) || function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) wp_remote_retrieve_response_code( $response );
		}

		$code = is_array( $response['response'] ?? null ) ? ( $response['response']['code'] ?? 0 ) : 0;

		return is_int( $code ) || ( is_string( $code ) && is_numeric( $code ) ) ? (int) $code : 0;
	}

	public static function response_body( mixed $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		if ( function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_body' ) || function_exists( 'wp_remote_retrieve_body' ) ) {
			return (string) wp_remote_retrieve_body( $response );
		}

		return is_string( $response['body'] ?? null ) ? $response['body'] : '';
	}

	public static function response_header( mixed $response, string $header ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		if ( function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_header' ) || function_exists( 'wp_remote_retrieve_header' ) ) {
			$value = wp_remote_retrieve_header( $response, $header );
			return is_string( $value ) ? $value : '';
		}

		$headers = $response['headers'] ?? array();
		if ( ! is_array( $headers ) ) {
			return '';
		}
		if ( array_key_exists( $header, $headers ) ) {
			return is_string( $headers[ $header ] ) ? $headers[ $header ] : '';
		}
		$needle = strtolower( $header );
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && strtolower( $name ) === $needle ) {
				return is_string( $value ) ? $value : '';
			}
		}

		return '';
	}

	/**
	 * Resolve a redirect Location header against the current URL and enforce HTTPS,
	 * no credentials/fragments/non-443 ports, no IP literals, and an explicit host allowlist.
	 *
	 * @param list<string> $allowed_hosts
	 */
	public static function resolve_and_validate_redirect_location( string $base_url, mixed $location, array $allowed_hosts ): ?string {
		if (
			! is_string( $location )
			|| '' === $location
			|| strlen( $location ) > 4096
			|| str_contains( $location, '\\' )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $location )
		) {
			return null;
		}
		if ( str_starts_with( $location, '//' ) ) {
			$candidate = 'https:' . $location;
		} elseif ( 1 === preg_match( '/\A[A-Za-z][A-Za-z0-9+.-]*:/', $location ) ) {
			$candidate = $location;
		} else {
			$base = self::parse_url( $base_url );
			if ( ! is_array( $base ) || ! is_string( $base['host'] ?? null ) || '' === $base['host'] ) {
				return null;
			}
			$base_host = strtolower( $base['host'] );
			$base_path = is_string( $base['path'] ?? null ) && '' !== $base['path'] ? $base['path'] : '/';
			if ( str_starts_with( $location, '/' ) ) {
				$resolved_relative = $location;
			} elseif ( str_starts_with( $location, '?' ) ) {
				$resolved_relative = $base_path . $location;
			} else {
				$last_slash        = strrpos( $base_path, '/' );
				$base_dir          = false === $last_slash ? '/' : substr( $base_path, 0, $last_slash + 1 );
				$resolved_relative = $base_dir . $location;
			}
			$candidate = 'https://' . $base_host . $resolved_relative;
		}
		if ( strlen( $candidate ) > 4096 ) {
			return null;
		}
		$parts = self::parse_url( $candidate );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ! is_string( $parts['host'] ?? null )
			|| '' === $parts['host']
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] )
			|| isset( $parts['fragment'] )
		) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if (
			false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| ! in_array( $host, $allowed_hosts, true )
		) {
			return null;
		}
		$normalized_path = self::normalize_redirect_path( is_string( $parts['path'] ?? null ) ? $parts['path'] : '/' );
		$query           = isset( $parts['query'] ) && is_string( $parts['query'] ) ? '?' . $parts['query'] : '';

		return 'https://' . $host . $normalized_path . $query;
	}

	private static function normalize_redirect_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		$input  = str_starts_with( $path, '/' ) ? $path : '/' . $path;
		$output = '';
		while ( '' !== $input ) {
			if ( str_starts_with( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( str_starts_with( $input, './' ) ) {
				$input = substr( $input, 2 );
			} elseif ( str_starts_with( $input, '/./' ) ) {
				$input = '/' . substr( $input, 3 );
			} elseif ( '/.' === $input ) {
				$input = '/';
			} elseif ( str_starts_with( $input, '/../' ) ) {
				$input      = '/' . substr( $input, 4 );
				$last_slash = strrpos( $output, '/' );
				$output     = false === $last_slash ? '' : substr( $output, 0, $last_slash );
			} elseif ( '/..' === $input ) {
				$input      = '/';
				$last_slash = strrpos( $output, '/' );
				$output     = false === $last_slash ? '' : substr( $output, 0, $last_slash );
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
			} else {
				$next_slash = strpos( $input, '/', 1 );
				if ( false === $next_slash ) {
					$output .= $input;
					$input   = '';
				} else {
					$output .= substr( $input, 0, $next_slash );
					$input   = substr( $input, $next_slash );
				}
			}
		}

		return '' === $output ? '/' : $output;
	}

	private static function rate_limit_integer( mixed $value, int $maximum ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]+\z/', $value ) ) {
			return null;
		}
		$number = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => $maximum,
				),
			)
		);

		return false === $number ? null : $number;
	}

	/**
	 * @return array<array-key, mixed>|false
	 */
	private static function parse_url( string $url ): array|false {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( $url );
			return is_array( $parsed ) ? $parsed : false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when WordPress is not loaded in isolated unit tests.
		return parse_url( $url );
	}
}
