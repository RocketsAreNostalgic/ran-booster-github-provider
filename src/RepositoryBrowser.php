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

	public function __construct( ProviderCredentialStore $credentials ) {
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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $fullName,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?string $credentialId = null,
		float|int $timeout = 15,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?int $responseSize = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		bool $authenticateDefault = false
	): RepositoryDescriptor {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$fullName = $this->validate_repository_name( $fullName );
		$headers  = $this->request_headers();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( null !== $credentialId || $authenticateDefault ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			$credential = $this->credentials->credentialMaterial( $credentialId );
			$token      = is_array( $credential ) && isset( $credential['secret'] ) && is_string( $credential['secret'] )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
			}

			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$arguments = array(
			'timeout'            => $timeout,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => $headers,
		);
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( null !== $responseSize ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			$arguments['limit_response_size'] = $responseSize;
		}

		$response = wp_remote_get(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			self::PUBLIC_API_BASE . '/repos/' . $this->encode_repository_name( $fullName ),
			$arguments
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached. Please try again.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential.', 401 );
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied the repository request. Check repository access.', 403 );
		}

		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository, or the selected credential cannot access it.', 404 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve that repository. Please try again.', 502 );
		}

		$item = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$repository = $this->descriptor_from_item( $item, $credentialId );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		if ( null === $repository || 0 !== strcasecmp( $fullName, $repository->locator ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository response. Please try again.', 502 );
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function branchHead(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $fullName,
		string $branch,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $expectedRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?string $credentialId = null,
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.privateFound -- Preserve public named-parameter compatibility pending the coordinated contract cohort.
		bool $private = false
	): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$fullName = $this->validate_repository_name( $fullName );
		$branch   = $this->validate_branch( $branch );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$identity = $this->repository( $fullName, $credentialId, 15, 65536, $private );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort. Preserve promoted constructor or Core DTO property contracts.
		if ( ! hash_equals( $expectedRepositoryId, $identity->providerRepositoryId ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository identity while resolving the branch.', 502 );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		return $this->currentBranchHead( $fullName, $branch, $credentialId, $private );
	}

	/**
	 * Resolve a branch, tag or commit to an immutable repository-bound commit.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function immutableRef(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $fullName,
		string $ref,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $expectedRepositoryId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?string $credentialId = null,
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.privateFound -- Preserve public named-parameter compatibility pending the coordinated contract cohort.
		bool $private = false
	): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$fullName = $this->validate_repository_name( $fullName );
		$ref      = $this->validate_ref( $ref );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$identity = $this->repository( $fullName, $credentialId, 15, 65536, $private );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort. Preserve promoted constructor or Core DTO property contracts.
		if ( ! hash_equals( $expectedRepositoryId, $identity->providerRepositoryId ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository identity while resolving the revision.', 502 );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$headers           = $this->authenticated_request_headers( $credentialId, $private );
		$headers['Accept'] = 'application/vnd.github.sha';
		$response          = wp_remote_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
				. $this->encode_repository_name( $fullName )
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
			throw new RuntimeException( 'GitHub could not be reached to resolve the repository revision.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			throw new RuntimeException( 'GitHub rejected the repository revision.', 400 );
		}
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential while resolving the repository revision.', 401 );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The retry type stores only a normalized integer delay and fixed message.
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied access while resolving the repository revision.', 403 );
		}
		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository revision, or the selected credential cannot access it.', 404 );
		}
		if ( 410 === $status ) {
			throw new RuntimeException( 'The requested GitHub repository revision is no longer available.', 410 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve the repository revision.', 502 );
		}

		$sha = trim( wp_remote_retrieve_body( $response ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/i', $sha ) ) {
			throw new RuntimeException( 'GitHub returned an invalid revision-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	/**
	 * Re-read a previously identity-bound branch without another repository call.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort.
	public function currentBranchHead(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		string $fullName,
		string $branch,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		?string $credentialId = null,
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.privateFound -- Preserve public named-parameter compatibility pending the coordinated contract cohort.
		bool $private = false
	): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$fullName = $this->validate_repository_name( $fullName );
		$branch   = $this->validate_branch( $branch );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$headers = $this->authenticated_request_headers( $credentialId, $private );

		$response = wp_remote_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
				. $this->encode_repository_name( $fullName )
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
			throw new RuntimeException( 'GitHub could not be reached to resolve the repository branch.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			throw new RuntimeException( 'GitHub rejected the repository branch.', 400 );
		}

		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential while resolving the repository branch.', 401 );
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The retry type stores only a normalized integer delay and fixed message.
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied access while resolving the repository branch.', 403 );
		}

		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository branch, or the selected credential cannot access it.', 404 );
		}

		if ( 410 === $status ) {
			throw new RuntimeException( 'The requested GitHub repository branch is no longer available.', 410 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve the repository branch.', 502 );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$sha  = is_array( $data ) && is_array( $data['commit'] ?? null )
			? $data['commit']['sha'] ?? null
			: null;

		if ( ! is_array( $data )
			|| ! is_string( $data['name'] ?? null )
			|| ! hash_equals( $branch, $data['name'] )
			|| ! is_string( $sha )
			|| 1 !== preg_match( '/^[0-9a-f]{40}$/i', $sha )
		) {
			throw new RuntimeException( 'GitHub returned an invalid branch-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	/** Check one normalized repository-relative directory at an immutable ref. */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,Universal.NamingConventions.NoReservedKeywordParameterNames.privateFound -- Public contract naming awaits the coordinated #25/#167 caller cohort. Preserve public named-parameter compatibility pending the contract cohort.
	public function pathExists( string $fullName, string $ref, string $path, ?string $credentialId = null, bool $private = false ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$fullName = $this->validate_repository_name( $fullName );
		try {
			$path = RepositoryRelativePath::normalize( $path );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained for developers and never rendered.
			throw new RuntimeException( 'The GitHub repository path check is invalid.', 400, $exception );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			throw new RuntimeException( 'The GitHub repository path check is invalid.', 400 );
		}

		$response = wp_remote_get(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
			self::PUBLIC_API_BASE . '/repos/' . $this->encode_repository_name( $fullName ) . '/contents/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) . '?ref=' . rawurlencode( strtolower( $ref ) ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 1024,
				'reject_unsafe_urls'  => true,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
				'headers'             => $this->authenticated_request_headers( $credentialId, $private ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached to check the repository path.', 502 );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return false;
		}
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential while checking the repository path.', 401 );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied access while checking the repository path.', 403 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not check the repository path.', 502 );
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

		throw new RuntimeException( 'GitHub could not check the repository path.', 502 );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public contract naming awaits the coordinated #25/#167 caller cohort. Preserve public named-parameter compatibility pending the contract cohort.
	public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter compatibility pending the contract cohort.
		$credential = $this->credentials->credentialMaterial( $credentialId );
		$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			return CredentialValidationResult::invalid();
		}

		$response = wp_remote_get(
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
			return CredentialValidationResult::unavailable();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			return CredentialValidationResult::invalid();
		}

		if ( $this->is_rate_limited_response( $response, $status ) ) {
			return CredentialValidationResult::rateLimited();
		}

		if ( 403 === $status ) {
			return CredentialValidationResult::invalid();
		}

		if ( $status < 200 || $status >= 300 ) {
			return CredentialValidationResult::unavailable();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! is_string( $body['login'] ?? null ) || '' === trim( $body['login'] ) ) {
			return CredentialValidationResult::invalidResponse();
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
		return RepositoryBrowseMode::PUBLIC_OWNER === $request->getMode()
			? $this->browse_public( $request )
			: $this->browse_accessible( $request );
	}

	private function browse_accessible( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		$credential_id = (string) $request->getCredentialId();
		$credential    = $this->credentials->credentialMaterial( $credential_id );
		$token         = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
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
		$owner = trim( (string) $request->getOwner() );
		if ( ! preg_match( '/\A(?=.{1,39}\z)(?!-)(?!.*--)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/', $owner ) ) {
			throw new RuntimeException( 'Enter a valid GitHub user or organisation name.', 400 );
		}

		$credential_id = $request->getCredentialId();
		$headers       = $this->request_headers();
		if ( null !== $credential_id ) {
			$credential = $this->credentials->credentialMaterial( $credential_id );
			$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
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
		array $headers,
		RepositoryBrowseRequest $request,
		?string $credential_id,
		bool $public_only
	): RepositoryBrowseResult {
		$repositories = array();

		for ( $page = 1; ; ++$page ) {
			if ( ! $request->hasCapacity() ) {
				return $this->partial_browse_result( $repositories, 503 );
			}

			$separator = str_contains( $endpoint, '?' ) ? '&' : '?';
			$url       = $endpoint . $separator . 'per_page=' . self::PER_PAGE . '&page=' . $page . '&sort=full_name';
			try {
				$items = $this->browse_request( $url, $headers, $request );
			} catch ( RuntimeException | InvalidArgumentException $exception ) {
				if ( array() === $repositories
					|| ( $public_only
						&& null !== $request->getCredentialId()
						&& in_array( (int) $exception->getCode(), array( 401, 403, 429 ), true ) )
				) {
					throw $exception;
				}

				return $this->partial_browse_result( $repositories, (int) $exception->getCode() );
			}
			if ( ! array_is_list( $items ) ) {
				if ( array() === $repositories ) {
					throw new RuntimeException( 'GitHub returned an invalid repository list.', 422 );
				}

				return $this->partial_browse_result( $repositories, 422 );
			}

			foreach ( $items as $item ) {
				$repository = $this->descriptor_from_item( $item, $credential_id );
				if ( null === $repository || ( $public_only && $repository->private ) ) {
					continue;
				}

				$repositories[] = $repository;
				if ( RepositoryBrowseRequest::MAX_RESULTS <= count( $repositories ) ) {
					return $this->partial_browse_result( $repositories, 206 );
				}
			}

			if ( count( $items ) < self::PER_PAGE ) {
				$this->sort_repositories( $repositories );

				return new RepositoryBrowseResult( $repositories );
			}
		}
	}

	/** @param array<string, string> $headers */
	private function browse_request( string $url, array $headers, RepositoryBrowseRequest $request ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $request->claimRemoteCall(),
				'redirection'         => 0,
				'limit_response_size' => $request->getResponseSizeLimit(),
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached. Please try again.', 504 );
		}

		$body   = wp_remote_retrieve_body( $response );
		$status = (int) wp_remote_retrieve_response_code( $response );
		$request->acceptResponseBody( $body );

		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential.', 401 );
		}
		if ( $this->is_rate_limited_response( $response, $status ) ) {
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied repository access. Check credential permissions.', 403 );
		}
		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that user or organisation.', 404 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not list repositories. Please try again.', 502 );
		}

		$data = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository response.', 422 );
		}

		return $data;
	}

	/** @param list<RepositoryDescriptor> $repositories */
	private function partial_browse_result( array $repositories, int $status ): RepositoryBrowseResult {
		if ( array() === $repositories ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status is an internal fixed integer; the message is fixed and redacted.
			throw new RuntimeException( 'GitHub repository browsing could not continue safely.', $status );
		}

		$this->sort_repositories( $repositories );

		$reason = match ( $status ) {
			401, 403 => RepositoryBrowseResult::AUTHORIZATION,
			429 => RepositoryBrowseResult::RATE_LIMIT,
			206, 413, 503, 504 => RepositoryBrowseResult::LIMIT,
			default => RepositoryBrowseResult::PROVIDER,
		};

		return new RepositoryBrowseResult( $repositories, $reason );
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

		$credential = $this->credentials->credentialMaterial( $credential_id );
		$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
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
		if ( ! GitReferenceSyntax::isValidNamedReference( $branch ) ) {
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
		} catch ( RuntimeException ) {
			return null;
		}
		$parts = explode( '/', $full_name );

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$full_name,
			$parts[1],
			$provider_repository_id,
			$item['private'],
			$item['default_branch'],
			null !== $credential_id && '' !== $credential_id ? $credential_id : null
		);
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
