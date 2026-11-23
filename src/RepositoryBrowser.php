<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\GitReferenceSyntax;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryBrowseMode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;

/**
 * Lists the repositories available to the configured GitHub token.
 *
 * The token is used only in the server-side request and is never included in
 * the returned repository data or in exception messages.
 */
class RepositoryBrowser {

	const API_URL          = 'https://api.github.com/user/repos';
	const PUBLIC_API_BASE  = 'https://api.github.com';
	const API_VERSION      = '2022-11-28';
	const PER_PAGE         = 30;
	const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

	private ProviderCredentialStore $credentials;

	public function __construct( ProviderCredentialStore $credentials, private ?\Closure $assert_operation = null ) {
		$this->credentials = $credentials;
	}

	/**
	 * Resolve one repository from GitHub's canonical repository response.
	 *
	 * An omitted credential deliberately means an anonymous request, even when
	 * the site has a default GitHub credential. A selected credential is looked
	 * up only in GitHub's provider scope and is never included in the URL or an
	 * exception message.
	 */
	public function repository(
		string $full_name,
		?string $credential_id = null,
		float|int $timeout = 15,
		?int $response_size = null,
		bool $authenticate_default = false
	): RepositoryDescriptor {
		$full_name = $this->validate_repository_name( $full_name );
		$headers   = $this->request_headers();

		if ( null !== $credential_id || $authenticate_default ) {
			$credential = $this->read_credential_material( $credential_id );
			$token      = is_array( $credential ) && isset( $credential['secret'] ) && is_string( $credential['secret'] )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				$this->fail_request( null === $credential ? 'credential_missing' : 'credential_format_invalid', 'The selected GitHub credential is not available.', 400 );
			}

			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$arguments = array(
			'timeout'            => $timeout,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => $headers,
		);
		if ( null !== $response_size ) {
			$arguments['limit_response_size'] = $response_size;
		}

		$response = $this->guarded_get(
			self::PUBLIC_API_BASE . '/repos/' . $this->encode_repository_name( $full_name ),
			$arguments
		);

		if ( is_wp_error( $response ) ) {
			$this->fail_request( $this->transport_reason( $response ), 'GitHub could not be reached. Please try again.', 502, source_code: $this->http_source_code( $response ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			$this->fail_request( 'credential_rejected', 'GitHub rejected the selected credential.', 401, $status );
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			$this->fail_request( 'rate_limited', 'GitHub API rate limit has been reached. Try again later.', 429, $status, $response );
		}

		if ( 403 === $status ) {
			$this->fail_request( 'permission_denied', 'GitHub denied the repository request. Check repository access.', 403, $status );
		}

		if ( 404 === $status ) {
			$this->fail_request( 'repository_not_found_or_no_access', 'GitHub could not find that repository, or the selected credential cannot access it.', 404, $status );
		}

		if ( $status < 200 || $status >= 300 ) {
			$this->fail_request( $this->http_failure_reason( $status ), 'GitHub could not resolve that repository. Please try again.', 502, $status >= 400 && $status <= 599 ? $status : null );
		}

		$item       = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
		$repository = $this->descriptor_from_item( $item, $credential_id );

		if ( null === $repository || 0 !== strcasecmp( $full_name, $repository->locator ) ) {
			$this->fail_request( 'response_invalid', 'GitHub returned an invalid repository response. Please try again.', 502 );
		}

		return $repository;
	}

	/**
	 * Resolve the immutable commit currently at a repository branch head.
	 *
	 * This request is used to reject delayed webhook deliveries before an
	 * archive session (and its authentication hook) is created. Public
	 * repositories remain anonymous unless a credential was explicitly
	 * selected; private repositories may use the provider's default credential.
	 */
	public function branch_head(
		string $full_name,
		string $branch,
		string $expected_repository_id,
		?string $credential_id = null,
		bool $is_private = false
	): string {
		$full_name = $this->validate_repository_name( $full_name );
		$branch    = $this->validate_branch( $branch );
		$identity  = $this->repository( $full_name, $credential_id, 15, 65536, $is_private );

		if ( ! hash_equals( $expected_repository_id, $identity->provider_repository_id ) ) {
			$this->fail_request( 'repository_identity_changed', 'GitHub returned an invalid repository identity while resolving the branch.', 502 );
		}

		return $this->current_branch_head( $full_name, $branch, $credential_id, $is_private );
	}

	/**
	 * Resolve a branch, tag or commit to an immutable repository-bound commit.
	 */
	public function immutable_ref(
		string $full_name,
		string $ref,
		string $expected_repository_id,
		?string $credential_id = null,
		bool $is_private = false
	): string {
		$full_name = $this->validate_repository_name( $full_name );
		$ref       = $this->validate_ref( $ref );
		$identity  = $this->repository( $full_name, $credential_id, 15, 65536, $is_private );

		if ( ! hash_equals( $expected_repository_id, $identity->provider_repository_id ) ) {
			$this->fail_request( 'repository_identity_changed', 'GitHub returned an invalid repository identity while resolving the revision.', 502 );
		}

		$probe_headers     = $this->authenticated_request_headers( $credential_id, $is_private );
		$headers           = $probe_headers;
		$headers['Accept'] = 'application/vnd.github.sha';
		$response          = $this->guarded_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				. $this->encode_repository_name( $full_name )
				. '/commits/'
				. rawurlencode( $ref ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 128,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->fail_request( $this->transport_reason( $response ), 'GitHub could not be reached to resolve the repository revision.', 502, source_code: $this->http_source_code( $response ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			$this->fail_request( 'http_4xx_other', 'GitHub rejected the repository revision.', 400, $status );
		}
		if ( 401 === $status ) {
			$this->fail_request( 'credential_rejected', 'GitHub rejected the selected credential while resolving the repository revision.', 401, $status );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			// The retry type stores only a normalized integer delay and fixed message.
			$this->fail_request( 'rate_limited', 'GitHub API rate limit has been reached. Try again later.', 429, $status, $response );
		}
		if ( 403 === $status ) {
			$this->fail_request( 'permission_denied', 'GitHub denied access while resolving the repository revision.', 403, $status );
		}
		if ( 404 === $status ) {
			$reason = class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class )
				&& $this->repository_contents_readable( $full_name, $ref, $probe_headers ) ? 'ref_not_found' : 'repository_not_found_or_no_access';
			$this->fail_request( $reason, 'GitHub could not find that repository revision, or the selected credential cannot access it.', 404, $status );
		}
		if ( 410 === $status ) {
			$this->fail_request( 'ref_not_found', 'The requested GitHub repository revision is no longer available.', 410, $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			$this->fail_request( $this->http_failure_reason( $status ), 'GitHub could not resolve the repository revision.', 502, $status >= 400 && $status <= 599 ? $status : null );
		}

		$sha = trim( wp_remote_retrieve_body( $response ) );
		if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/iD', $sha ) ) {
			$this->fail_request( 'response_invalid', 'GitHub returned an invalid revision-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	/**
	 * Re-read a previously identity-bound branch without another repository call.
	 */
	public function current_branch_head(
		string $full_name,
		string $branch,
		?string $credential_id = null,
		bool $is_private = false
	): string {
		$full_name = $this->validate_repository_name( $full_name );
		$branch    = $this->validate_branch( $branch );

		$headers = $this->authenticated_request_headers( $credential_id, $is_private );

		$response = $this->guarded_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				. $this->encode_repository_name( $full_name )
				. '/branches/'
				. rawurlencode( $branch ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->fail_request( $this->transport_reason( $response ), 'GitHub could not be reached to resolve the repository branch.', 502, source_code: $this->http_source_code( $response ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			$this->fail_request( 'http_4xx_other', 'GitHub rejected the repository branch.', 400, $status );
		}

		if ( 401 === $status ) {
			$this->fail_request( 'credential_rejected', 'GitHub rejected the selected credential while resolving the repository branch.', 401, $status );
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			// The retry type stores only a normalized integer delay and fixed message.
			$this->fail_request( 'rate_limited', 'GitHub API rate limit has been reached. Try again later.', 429, $status, $response );
		}

		if ( 403 === $status ) {
			$this->fail_request( 'permission_denied', 'GitHub denied access while resolving the repository branch.', 403, $status );
		}

		if ( 404 === $status ) {
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
				$readable = $this->repository_contents_readable( $full_name, $branch, $headers );
				throw new \RAN\RepositoryProvider\ProviderRequestFailed(
					new \RAN\RepositoryProvider\ProviderFailure( $readable ? 'ref_not_found' : 'repository_not_found_or_no_access', 404 )
				);
			}
			throw new RuntimeException( 'GitHub could not find that repository branch, or the selected credential cannot access it.', 404 );
		}

		if ( 410 === $status ) {
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
				throw new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'ref_not_found', 410 ) );
			}
			throw new RuntimeException( 'The requested GitHub repository branch is no longer available.', 410 );
		}

		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true )
			&& class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
			// A redirect is refused, not evidence of a branch rename or its destination.
			throw new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'redirect_blocked' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$this->fail_request( $this->http_failure_reason( $status ), 'GitHub could not resolve the repository branch.', 502, $status >= 400 && $status <= 599 ? $status : null );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$sha  = is_array( $data ) && is_array( $data['commit'] ?? null )
			? $data['commit']['sha'] ?? null
			: null;

		if ( ! is_array( $data )
			|| ! is_string( $data['name'] ?? null )
			|| ! hash_equals( $branch, $data['name'] )
			|| ! is_string( $sha )
			|| 1 !== preg_match( '/\A[0-9a-f]{40}\z/iD', $sha )
		) {
			$this->fail_request( 'response_invalid', 'GitHub returned an invalid branch-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	/** Check one normalized repository-relative directory at an immutable ref. */
	public function path_exists( string $full_name, string $ref, string $path, ?string $credential_id = null, bool $is_private = false ): bool {
		$full_name = $this->validate_repository_name( $full_name );
		try {
			$path = RepositoryRelativePath::normalize( $path );
		} catch ( InvalidArgumentException $exception ) {
			// Chained for developers and never rendered.
			throw new RuntimeException( 'The GitHub repository path check is invalid.', 400, $exception );
		}
		if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/iD', $ref ) ) {
			throw new RuntimeException( 'The GitHub repository path check is invalid.', 400 );
		}

		$headers  = $this->authenticated_request_headers( $credential_id, $is_private );
		$response = $this->guarded_get(
			self::PUBLIC_API_BASE . '/repos/' . $this->encode_repository_name( $full_name ) . '/contents/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) . '?ref=' . rawurlencode( strtolower( $ref ) ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 1024,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->fail_request( $this->transport_reason( $response ), 'GitHub could not be reached to check the repository path.', 502, source_code: $this->http_source_code( $response ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			// A credentialed 404 also means the credential cannot read the repository, so absence needs proof of access.
			if ( ! $is_private && null === $credential_id ) {
				return false;
			}
			if ( $this->repository_contents_readable( $full_name, $ref, $headers ) ) {
				return false;
			}
			$this->fail_request( 'repository_not_found_or_no_access', 'GitHub could not find the repository path, or the selected credential cannot access it.', 404, $status );
		}
		if ( 401 === $status ) {
			$this->fail_request( 'credential_rejected', 'GitHub rejected the selected credential while checking the repository path.', 401, $status );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			$this->fail_request( 'rate_limited', 'GitHub API rate limit has been reached. Try again later.', 429, $status, $response );
		}
		if ( 403 === $status ) {
			$this->fail_request( 'permission_denied', 'GitHub denied access while checking the repository path.', 403, $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			$this->fail_request( $this->http_failure_reason( $status ), 'GitHub could not check the repository path.', 502, $status >= 400 && $status <= 599 ? $status : null );
		}

		// GitHub returns a JSON list for a directory and an object for a file,
		// symlink, or submodule. The response is deliberately capped, so validate
		// its shape without requiring the potentially truncated JSON to decode.
		$prefix = substr( ltrim( wp_remote_retrieve_body( $response ) ), 0, 1 );
		if ( '[' === $prefix ) {
			return true;
		}
		if ( '{' === $prefix ) {
			return false;
		}

		$this->fail_request( 'response_invalid', 'GitHub could not check the repository path.', 502 );
	}

	public function validate_credential( string $credential_id, float $timeout = 15.0 ): CredentialValidationResult {
		try {
			$credential = $this->read_credential_material( $credential_id );
		} catch ( RuntimeException $exception ) {
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && 'credential_store_unavailable' === \RAN\RepositoryProvider\ProviderFailure::carried( $exception )?->reason ) {
				return $this->validation_failure( 'unavailable', 'credential_store_unavailable' );
			}
			throw $exception;
		}
		$token = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			return $this->validation_failure( 'invalid', null === $credential ? 'credential_missing' : 'credential_format_invalid' );
		}

		$response = $this->guarded_get(
			self::PUBLIC_API_BASE . '/user',
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => array_merge(
					$this->request_headers(),
					array( 'Authorization' => 'Bearer ' . $token )
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->validation_failure( 'unavailable', $this->transport_reason( $response ), source_code: $this->http_source_code( $response ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			$expires_at = $this->credential_expiry_report( $response )->expires_at;
			$expired    = null !== $expires_at && new DateTimeImmutable( $expires_at ) <= new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			return $this->validation_failure( 'invalid', $expired ? 'credential_expired' : 'credential_rejected', 401, null, $expired ? $expires_at : null );
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			return $this->validation_failure( 'rate_limited', 'rate_limited', $status, $response );
		}

		if ( 403 === $status ) {
			return $this->validation_failure( 'invalid', 'permission_denied', 403 );
		}

		if ( $status < 200 || $status >= 300 ) {
			return $this->validation_failure( 'unavailable', $this->http_failure_reason( $status ), $status >= 400 && $status <= 599 ? $status : null );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! is_string( $body['login'] ?? null ) || '' === trim( $body['login'] ) ) {
			return $this->validation_failure( 'invalid_response', 'response_invalid' );
		}

		return CredentialValidationResult::valid( $this->credential_expiry_report( $response ) );
	}

	/**
	 * Parse GitHub's optional token-expiration header without retaining the raw
	 * response value. Missing or untrustworthy metadata remains explicitly
	 * unknown.
	 *
	 * @param array<string, mixed> $response WordPress HTTP response.
	 */
	private function credential_expiry_report( array $response ): CredentialExpiryReport {
		$value = wp_remote_retrieve_header( $response, 'GitHub-Authentication-Token-Expiration' );
		if ( ! is_string( $value )
			|| '' === $value
			|| strlen( $value ) > 64
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			return CredentialExpiryReport::unknown();
		}

		$format = match ( true ) {
			1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\z/D', $value ) => '!Y-m-d H:i:s \U\T\C',
			1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-](?:[01]\d|2[0-3])[0-5]\d\z/D', $value ) => '!Y-m-d H:i:s O',
			default => null,
		};
		if ( null === $format ) {
			return CredentialExpiryReport::unknown();
		}

		$expiry = DateTimeImmutable::createFromFormat( $format, $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $expiry
			|| ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) )
			|| $expiry->format( substr( $format, 1 ) ) !== $value
		) {
			return CredentialExpiryReport::unknown();
		}

		return CredentialExpiryReport::known(
			$expiry->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' )
		);
	}

	public function browse( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		return RepositoryBrowseMode::PUBLIC_OWNER === $request->get_mode()
			? $this->browse_public( $request )
			: $this->browse_accessible( $request );
	}

	private function browse_accessible( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		$credential_id = (string) $request->get_credential_id();
		$credential    = $this->read_credential_material( $credential_id );
		$token         = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			$this->fail_request( null === $credential ? 'credential_missing' : 'credential_format_invalid', 'The selected GitHub credential is not available.', 400 );
		}

		$headers                  = $this->request_headers();
		$headers['Authorization'] = 'Bearer ' . $token;

		return $this->browse_pages(
			self::API_URL . '?affiliation=owner%2Ccollaborator%2Corganization_member',
			$headers,
			$request,
			$credential_id,
			false
		);
	}

	private function browse_public( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		$owner = trim( (string) $request->get_owner() );
		if ( ! preg_match( '/\A(?=.{1,39}\z)(?!-)(?!.*--)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/', $owner ) ) {
			throw new RuntimeException( 'Enter a valid GitHub user or organisation name.', 400 );
		}

		$credential_id = $request->get_credential_id();
		$headers       = $this->request_headers();
		if ( null !== $credential_id ) {
			$credential = $this->read_credential_material( $credential_id );
			$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				$this->fail_request( null === $credential ? 'credential_missing' : 'credential_format_invalid', 'The selected GitHub credential is not available.', 400 );
			}

			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$account         = $this->browse_request(
			self::PUBLIC_API_BASE . '/users/' . rawurlencode( $owner ),
			$headers,
			$request
		);
		$account_type    = is_string( $account['type'] ?? null ) ? $account['type'] : '';
		$canonical_owner = is_string( $account['login'] ?? null ) ? $account['login'] : $owner;

		if ( 'Organization' === $account_type ) {
			$endpoint = self::PUBLIC_API_BASE . '/orgs/' . rawurlencode( $canonical_owner ) . '/repos?type=public';
		} elseif ( 'User' === $account_type ) {
			$endpoint = self::PUBLIC_API_BASE . '/users/' . rawurlencode( $canonical_owner ) . '/repos?type=owner';
		} else {
			throw new RuntimeException( 'That GitHub account is not a user or organisation.', 400 );
		}

		return $this->browse_pages( $endpoint, $headers, $request, null, true );
	}

	/** @param array<string, string> $headers */
	private function browse_pages(
		string $endpoint,
		#[\SensitiveParameter] array $headers,
		RepositoryBrowseRequest $request,
		?string $credential_id,
		bool $public_only
	): RepositoryBrowseResult {
		$repositories      = array();
		$had_invalid_items = false;

		for ( $page = 1; ; ++$page ) {
			if ( ! $request->has_capacity() ) {
				return $this->partial_browse_result( $repositories, 503 );
			}

			$separator = str_contains( $endpoint, '?' ) ? '&' : '?';
			$url       = $endpoint . $separator . 'per_page=' . self::PER_PAGE . '&page=' . $page . '&sort=full_name';
			try {
				$items = $this->browse_request( $url, $headers, $request, true );
			} catch ( RuntimeException | InvalidArgumentException $exception ) {
				if ( array() === $repositories
					|| ( $public_only
						&& null !== $request->get_credential_id()
						&& in_array( (int) $exception->getCode(), array( 401, 403, 429 ), true ) )
				) {
					throw $exception;
				}

				return $this->partial_browse_result( $repositories, (int) $exception->getCode(), $exception );
			}

			foreach ( $items as $item ) {
				$repository = $this->descriptor_from_item( $item, $credential_id );
				if ( null === $repository ) {
					$had_invalid_items = true;
					continue;
				}
				if ( $public_only && $repository->private ) {
					continue;
				}

				$repositories[] = $repository;
				if ( RepositoryBrowseRequest::MAX_RESULTS <= count( $repositories ) ) {
					return $this->partial_browse_result( $repositories, 206 );
				}
			}

			if ( count( $items ) < self::PER_PAGE ) {
				$this->sort_repositories( $repositories );

				return new RepositoryBrowseResult( $repositories, $had_invalid_items ? RepositoryBrowseResult::PROVIDER : null );
			}
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<array-key,mixed>
	 */
	private function browse_request( string $url, #[\SensitiveParameter] array $headers, RepositoryBrowseRequest $request, bool $expect_list = false ): array {
		$response = $this->guarded_get(
			$url,
			array(
				'timeout'             => $request->claim_remote_call(),
				'redirection'         => 0,
				'limit_response_size' => $request->get_response_size_limit(),
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->fail_request( $this->transport_reason( $response ), 'GitHub could not be reached. Please try again.', 504, source_code: $this->http_source_code( $response ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$status = (int) wp_remote_retrieve_response_code( $response );
		try {
			$request->accept_response_body( $body );
		} catch ( RuntimeException $exception ) {
			if ( 413 === $exception->getCode() && class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
				$this->fail_request( 'response_too_large', 'GitHub repository response exceeded the safe size limit.', 413 );
			}
			throw $exception;
		}

		if ( 401 === $status ) {
			$this->fail_request( 'credential_rejected', 'GitHub rejected the selected credential.', 401, $status );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			$this->fail_request( 'rate_limited', 'GitHub API rate limit has been reached. Try again later.', 429, $status, $response );
		}
		if ( 403 === $status ) {
			$this->fail_request( 'permission_denied', 'GitHub denied repository access. Check credential permissions.', 403, $status );
		}
		if ( 404 === $status ) {
			$this->fail_request( 'repository_not_found_or_no_access', 'GitHub could not find that user or organisation.', 404, $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			$this->fail_request( $this->http_failure_reason( $status ), 'GitHub could not list repositories. Please try again.', 502, $status >= 400 && $status <= 599 ? $status : null );
		}

		$data = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			$this->fail_request( 'response_invalid', 'GitHub returned an invalid repository response.', 422 );
		}

		if ( $expect_list && ( ! array_is_list( $data ) || '[' !== substr( ltrim( $body ), 0, 1 ) ) ) {
			$this->fail_request( 'response_invalid', 'GitHub returned an invalid repository list.', 422 );
		}

		return $data;
	}

	/** @param list<RepositoryDescriptor> $repositories */
	private function partial_browse_result( array $repositories, int $status, ?\Throwable $exception = null ): RepositoryBrowseResult {
		if ( array() === $repositories ) {
			// Status is an internal fixed integer; the message is fixed and redacted.
			throw new RuntimeException( 'GitHub repository browsing could not continue safely.', $status );
		}

		$this->sort_repositories( $repositories );

		$reason = match ( $status ) {
			401, 403 => RepositoryBrowseResult::AUTHORIZATION,
			429 => RepositoryBrowseResult::RATE_LIMIT,
			206, 413, 503 => RepositoryBrowseResult::LIMIT,
			default => RepositoryBrowseResult::PROVIDER,
		};

		if ( null !== $exception && class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) ) {
			$failure = \RAN\RepositoryProvider\ProviderFailure::carried( $exception );
			if ( null !== $failure ) {
				$reason = match ( $failure->reason ) {
					'rate_limited', 'rate_limited_secondary' => RepositoryBrowseResult::RATE_LIMIT,
					'credential_rejected', 'credential_expired', 'permission_denied' => RepositoryBrowseResult::AUTHORIZATION,
					'response_too_large' => RepositoryBrowseResult::LIMIT,
					default => RepositoryBrowseResult::PROVIDER,
				};
			}
		}

		return new RepositoryBrowseResult( $repositories, $reason );
	}

	private function rate_limit_integer( mixed $value, int $maximum ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) {
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

	/** @return array<string, mixed>|null */
	private function read_credential_material( ?string $credential_id ): ?array {
		$this->assert_operation?->__invoke();
		try {
			$credential = $this->credentials->credential_material( $credential_id );
		} catch ( \Throwable $exception ) {
			if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
				$this->fail_request( 'credential_store_unavailable', 'The saved GitHub credential could not be read.', 503 );
			}
			throw $exception;
		}
		$this->assert_operation?->__invoke();
		return $credential;
	}

	/** @param array<string, string> $headers */
	private function repository_contents_readable( string $full_name, string $ref, #[\SensitiveParameter] array $headers ): bool {
		// Metadata access alone does not prove Contents permission on a private repository.
		$probe    = $this->guarded_get(
			self::PUBLIC_API_BASE . '/repos/' . $this->encode_repository_name( $full_name ) . '/branches?per_page=1',
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);
		$branches = ! is_wp_error( $probe ) && 200 === (int) wp_remote_retrieve_response_code( $probe )
			? json_decode( wp_remote_retrieve_body( $probe ) )
			: null;
		return is_array( $branches ) && ( array() === $branches
			|| ( 1 === count( $branches ) && $branches[0] instanceof \stdClass
				&& is_string( $branches[0]->name ?? null )
				&& GitReferenceSyntax::is_valid_named_reference( $branches[0]->name )
				&& $ref !== $branches[0]->name
				&& ( $branches[0]->commit ?? null ) instanceof \stdClass
				&& is_string( $branches[0]->commit->sha ?? null )
				&& 1 === preg_match( '/\A[0-9a-f]{40}\z/iD', $branches[0]->commit->sha ) ) );
	}

	/** Throw the optional request carrier while preserving an older host's exception. */
	private function fail_request( string $reason, string $message, int $legacy_code, ?int $http_status = null, #[\SensitiveParameter] mixed $response = null, ?string $expires_at = null, #[\SensitiveParameter] ?string $source_code = null ): never {
		if ( ! class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) || ! class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
			throw new RuntimeException( $message, $legacy_code );
		}
		$retry = null;
		$reset = null;
		if ( 'rate_limited' === $reason && is_array( $response ) ) {
			$retry = $this->rate_limit_integer( wp_remote_retrieve_header( $response, 'retry-after' ), 86400 );
			$reset = $this->rate_limit_integer( wp_remote_retrieve_header( $response, 'x-ratelimit-reset' ), PHP_INT_MAX );
		}
		$failure = property_exists( \RAN\RepositoryProvider\ProviderFailure::class, 'source_code' )
			? ( new \ReflectionClass( \RAN\RepositoryProvider\ProviderFailure::class ) )->newInstanceArgs(
				array(
					'reason'                => $reason,
					'http_status'           => $http_status,
					'retry_after'           => $retry,
					'reset_at'              => $reset,
					'credential_expires_at' => $expires_at,
					'source_code'           => $source_code,
				)
			)
			: new \RAN\RepositoryProvider\ProviderFailure( $reason, $http_status, $retry, $reset, $expires_at );
		throw new \RAN\RepositoryProvider\ProviderRequestFailed( $failure );
	}

	/** @param 'invalid'|'unavailable'|'rate_limited'|'invalid_response' $factory */
	private function validation_failure( string $factory, string $reason, ?int $http_status = null, #[\SensitiveParameter] mixed $response = null, ?string $expires_at = null, #[\SensitiveParameter] ?string $source_code = null ): CredentialValidationResult {
		if ( class_exists( \RAN\RepositoryProvider\ProviderFailure::class ) && class_exists( \RAN\RepositoryProvider\ProviderRequestFailed::class ) ) {
			$retry   = 'rate_limited' === $reason && is_array( $response ) ? $this->rate_limit_integer( wp_remote_retrieve_header( $response, 'retry-after' ), 86400 ) : null;
			$reset   = 'rate_limited' === $reason && is_array( $response ) ? $this->rate_limit_integer( wp_remote_retrieve_header( $response, 'x-ratelimit-reset' ), PHP_INT_MAX ) : null;
			$failure = property_exists( \RAN\RepositoryProvider\ProviderFailure::class, 'source_code' )
				? ( new \ReflectionClass( \RAN\RepositoryProvider\ProviderFailure::class ) )->newInstanceArgs(
					array(
						'reason'                => $reason,
						'http_status'           => $http_status,
						'retry_after'           => $retry,
						'reset_at'              => $reset,
						'credential_expires_at' => $expires_at,
						'source_code'           => $source_code,
					)
				)
				: new \RAN\RepositoryProvider\ProviderFailure( $reason, $http_status, $retry, $reset, $expires_at );
			// The certified legacy host predates this optional public factory argument.
			$result = ( new \ReflectionMethod( CredentialValidationResult::class, $factory ) )->invoke( null, $failure );
			if ( ! $result instanceof CredentialValidationResult ) {
				throw new \LogicException( 'The credential-validation factory is incompatible.' );
			}
			return $result;
		}
		return CredentialValidationResult::$factory();
	}

	private function http_failure_reason( int $status ): string {
		return match ( true ) {
			in_array( $status, array( 301, 302, 303, 307, 308 ), true ) => 'redirect_blocked',
			$status >= 400 && $status < 500 => 'http_4xx_other',
			$status >= 500 && $status <= 599 => 'http_5xx',
			default => 'response_invalid',
		};
	}

	private function http_source_code( #[\SensitiveParameter] mixed $error ): ?string {
		$code = is_object( $error ) && is_callable( array( $error, 'get_error_code' ) ) ? $error->get_error_code() : null;
		return is_string( $code ) ? 'wp_http.' . $code : null;
	}

	private function transport_reason( #[\SensitiveParameter] mixed $error ): string {
		return is_object( $error ) && is_callable( array( $error, 'get_error_code' ) ) && 'http_request_not_executed' === $error->get_error_code()
			? 'blocked_by_site'
			: 'transport_other';
	}

	private function is_rate_limited_response( mixed $response, int $status ): bool {
		if ( 429 === $status ) {
			return true;
		}

		if ( 403 !== $status ) {
			return false;
		}

		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		if ( is_string( $remaining ) && '0' === trim( $remaining ) ) {
			return true;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( ! is_string( $retry_after ) ) {
			return false;
		}

		$retry_after = trim( $retry_after );
		if ( '' === $retry_after ) {
			return false;
		}

		if ( preg_match( '/\A\d+\z/', $retry_after ) ) {
			return true;
		}

		$retry_at = DateTimeImmutable::createFromFormat(
			'!' . self::HTTP_DATE_FORMAT,
			$retry_after,
			new DateTimeZone( 'GMT' )
		);

		return false !== $retry_at && $retry_at->format( self::HTTP_DATE_FORMAT ) === $retry_after;
	}

	/** @param array<string,mixed> $arguments */
	private function guarded_get( string $url, #[\SensitiveParameter] array $arguments ): mixed {
		$this->assert_operation?->__invoke();
		$response = wp_remote_get( $url, $arguments );
		$this->assert_operation?->__invoke();
		return $response;
	}

	/**
	 * @return array<string, string>
	 */
	private function request_headers(): array {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => self::API_VERSION,
			'User-Agent'           => 'RAN-Booster',
		);
	}

	/** @return array<string, string> */
	private function authenticated_request_headers( ?string $credential_id, bool $is_private ): array {
		$headers = $this->request_headers();

		if ( ! $is_private && null === $credential_id ) {
			return $headers;
		}

		$credential = $this->read_credential_material( $credential_id );
		$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			$this->fail_request( null === $credential ? 'credential_missing' : 'credential_format_invalid', 'The selected GitHub credential is not available.', 400 );
		}

		$headers['Authorization'] = 'Bearer ' . $token;

		return $headers;
	}

	private function validate_repository_name( string $full_name ): string {
		$full_name = trim( $full_name );
		$parts     = explode( '/', $full_name );

		if ( 2 !== count( $parts )
			|| ! preg_match( '/\A(?=.{1,39}\z)(?!-)(?!.*--)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/', $parts[0] )
			|| ! preg_match( '/\A(?=.{1,100}\z)[A-Za-z0-9._-]+\z/', $parts[1] )
			|| '.' === $parts[1]
			|| '..' === $parts[1] ) {
			throw new RuntimeException( 'Enter a valid GitHub repository in owner/repository form.', 400 );
		}

		return $full_name;
	}

	private function validate_branch( string $branch ): string {
		if ( ! GitReferenceSyntax::is_valid_named_reference( $branch ) ) {
			throw new RuntimeException( 'Enter a valid GitHub repository branch.', 400 );
		}

		return $branch;
	}

	private function validate_ref( string $ref ): string {
		try {
			return $this->validate_branch( $ref );
		} catch ( RuntimeException ) {
			throw new RuntimeException( 'Enter a valid GitHub repository branch, tag or commit.', 400 );
		}
	}

	private function encode_repository_name( string $full_name ): string {
		$parts = explode( '/', $full_name );

		return rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}

	private function descriptor_from_item( mixed $item, ?string $credential_id = null ): ?RepositoryDescriptor {
		if ( ! is_array( $item )
			|| ! isset( $item['id'] )
			|| ( ! is_int( $item['id'] ) && ! is_string( $item['id'] ) )
			|| empty( $item['full_name'] )
			|| ! is_string( $item['full_name'] )
			|| ! array_key_exists( 'private', $item )
			|| ! is_bool( $item['private'] )
			|| empty( $item['default_branch'] )
			|| ! is_string( $item['default_branch'] ) ) {
			return null;
		}

		$provider_repository_id = trim( (string) $item['id'] );
		if ( '' === $provider_repository_id ) {
			return null;
		}

		try {
			$full_name = $this->validate_repository_name( $item['full_name'] );
			$parts     = explode( '/', $full_name );
			return new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				$full_name,
				$parts[1],
				$provider_repository_id,
				$item['private'],
				$item['default_branch'],
				null !== $credential_id && '' !== $credential_id ? $credential_id : null
			);
		} catch ( RuntimeException | InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories Repositories to sort.
	 */
	private function sort_repositories( array &$repositories ): void {
		usort(
			$repositories,
			static function ( RepositoryDescriptor $left, RepositoryDescriptor $right ): int {
				return strcasecmp( $left->locator, $right->locator );
			}
		);
	}
}
