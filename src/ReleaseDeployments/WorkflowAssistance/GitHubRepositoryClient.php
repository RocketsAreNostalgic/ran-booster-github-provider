<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use Closure;
use Throwable;
/** Fixed-scope GitHub operations for assisted workflow setup. */
final class GitHubRepositoryClient {
	private const API_ROOT      = 'https://api.github.com';
	private const MAX_BODY      = 262144;
	private const MAX_TREE      = 2000;
	private const MAX_DOCUMENTS = 256;
	// Leave room in GitHub's anonymous 60-request hourly quota for target and pack discovery.
	private const MAX_ANONYMOUS_DOCUMENTS = 24;
	private const MAX_CHANGES             = 32;
	/** @var Closure(string,string,array<string,mixed>):mixed */
	private Closure $send;
	public function __construct( ?callable $send = null ) {
		$this->send = null === $send
			? static fn ( string $method, string $url, array $args ): mixed => wp_safe_remote_request(
				$url,
				array_merge( $args, array( 'method' => $method ) )
			)
			: Closure::fromCallable( $send );
	}

	public function repository( string $repository, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) ) {
			return $this->error( 'invalid_request' );
		}
		$response       = $this->request( 'GET', '/repos/' . $repository, $token );
		$data           = $response['data'] ?? array();
		$repository_id  = $this->numeric_string( $data['id'] ?? null );
		$full_name      = $data['full_name'] ?? null;
		$default_branch = $data['default_branch'] ?? null;
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( null === $repository_id || ! $this->valid_repository( $full_name ) || ! $this->valid_branch( $default_branch ) ) {
			return $this->error( 'invalid_response' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return $this->ok( array( 'repository_id' => $repository_id, 'full_name' => $full_name, 'default_branch' => $default_branch ) );
	}
	public function branch_ref( string $repository, string $branch, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || ! $this->valid_branch( $branch ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/ref/heads/' . rawurlencode( $branch ), $token, null, array( 404 => 'missing' ) );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$sha = $response['data']['object']['sha'] ?? null;
		$ref = $response['data']['ref'] ?? null;
		return $this->valid_sha( $sha ) && is_string( $ref ) && hash_equals( 'refs/heads/' . $branch, $ref )
			? $this->ok( array( 'sha' => $sha ) )
			: $this->error( 'invalid_response' );
	}
	public function snapshot(
		string $repository,
		string $repository_id,
		string $default_branch,
		string $sha,
		string $token = ''
	): array {
		if ( ! $this->valid_repository( $repository ) || null === $this->numeric_string( $repository_id )
			|| ! $this->valid_branch( $default_branch ) || ! $this->valid_sha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/trees/' . $sha . '?recursive=1', $token );
		$data     = $response['data'] ?? array();
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( true === ( $data['truncated'] ?? null ) || ! is_array( $data['tree'] ?? null )
			|| count( $data['tree'] ) > self::MAX_TREE ) {
			return $this->error( 'invalid_response' );
		}
		$entries           = array();
		$candidates        = array();
		$prefix_candidates = array();
		foreach ( $data['tree'] as $item ) {
			if ( ! is_array( $item ) || ! $this->valid_path( $item['path'] ?? null )
				|| isset( $entries[ $item['path'] ] )
				|| ! in_array( $item['type'] ?? null, array( 'blob', 'tree' ), true )
				|| ! in_array( $item['mode'] ?? null, array( '100644', '100755', '040000' ), true )
				|| ! $this->valid_sha( $item['sha'] ?? null ) ) {
				return $this->error( 'invalid_response' );
			}
			$size = $item['size'] ?? ( 'tree' === $item['type'] ? 0 : null );
			if ( ! is_int( $size ) || $size < 0 ) {
				return $this->error( 'invalid_response' );
			}
			$path             = $item['path'];
			$entries[ $path ] = array(
				'type' => $item['type'],
				'mode' => $item['mode'],
				'sha'  => $item['sha'],
				'size' => $size,
			);
			if ( 'blob' === $item['type'] && $this->assessment_document( $path ) ) {
				$candidates[ $path ] = $item['sha'];
			} elseif ( 'blob' === $item['type'] && $size >= 42 && SourceReadyAssessor::potential_runtime_blob( $path ) ) {
				$prefix_candidates[ $path ] = $item['sha'];
			}
		}
		if ( count( $candidates ) + count( $prefix_candidates ) > self::MAX_DOCUMENTS ) {
			return $this->error( 'invalid_response' );
		}
		if ( '' === $token && count( $candidates ) + count( $prefix_candidates ) > self::MAX_ANONYMOUS_DOCUMENTS ) {
			return $this->error( 'unauthorised' );
		}
		$documents = array();
		foreach ( $candidates as $path => $blob_sha ) {
			$blob = $this->blob( $repository, $blob_sha, $token );
			if ( 'ok' !== $blob['code'] ) {
				return $blob;
			}
			$documents[ $path ] = $blob['content'];
		}

		$prefixes = array();
		foreach ( $prefix_candidates as $path => $blob_sha ) {
			$prefix = $this->request( 'GET', '/repos/' . $repository . '/git/blobs/' . $blob_sha, $token, null, array(), true );
			if ( 'ok' !== $prefix['code'] ) {
				return $prefix;
			}
			$prefixes[ $path ] = $prefix['content'];
		}
		try {
			$snapshot = new RepositorySnapshot( $repository_id, $repository, $default_branch, $sha, $entries, $documents, $prefixes );
		} catch ( Throwable ) {
			return $this->error( 'invalid_response' );
		}
		return $this->ok( array( 'snapshot' => $snapshot ) );
	}
	public function blob( string $repository, string $sha, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || ! $this->valid_sha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/blobs/' . $sha, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$data    = $response['data'];
		$encoded = $data['content'] ?? null;
		if ( 'base64' !== ( $data['encoding'] ?? null ) || ! is_string( $encoded ) ) {
			return $this->error( 'invalid_response' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- GitHub Git Data encoding.
		$content = base64_decode( preg_replace( '/\s+/', '', $encoded ) ?? '', true );
		$size    = $data['size'] ?? null;
		if ( ! is_string( $content ) || ! is_int( $size ) || strlen( $content ) !== $size
			|| $size > self::MAX_BODY || str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			return $this->error( 'invalid_response' );
		}
		return $this->ok( array( 'content' => $content ) );
	}
	public function git_commit( string $repository, string $sha, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || ! $this->valid_sha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/commits/' . $sha, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$data     = $response['data'];
		$tree_sha = $data['tree']['sha'] ?? null;
		$parents  = $data['parents'] ?? null;
		if ( ! $this->valid_sha( $data['sha'] ?? null ) || ! hash_equals( $sha, $data['sha'] )
			|| ! $this->valid_sha( $tree_sha ) || ! is_array( $parents ) || count( $parents ) > 2 ) {
			return $this->error( 'invalid_response' );
		}
		$parent_shas = array();
		foreach ( $parents as $parent ) {
			if ( ! is_array( $parent ) || ! $this->valid_sha( $parent['sha'] ?? null ) ) {
				return $this->error( 'invalid_response' );
			}
			$parent_shas[] = $parent['sha'];
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return $this->ok( array( 'sha' => $sha, 'tree_sha' => $tree_sha, 'parents' => $parent_shas ) );
	}
	public function create_blob( string $repository, string $content, string $token ): array {
		if ( '' === $token || ! $this->valid_repository( $repository ) || strlen( $content ) > self::MAX_BODY
			|| str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/blobs',
			$token,
			array(
				'content'  => $content,
				'encoding' => 'utf-8',
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->valid_sha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	/** @param list<array{path:string,sha:string,mode:string}> $entries */
	public function create_tree( string $repository, string $base_tree_sha, array $entries, string $token ): array {
		if ( '' === $token || ! $this->valid_repository( $repository ) || ! $this->valid_sha( $base_tree_sha )
			|| array() === $entries || count( $entries ) > self::MAX_CHANGES ) {
			return $this->error( 'invalid_request' );
		}
		$tree = array();
		$seen = array();
		foreach ( $entries as $entry ) {
			$path = $entry['path'] ?? null;
			if ( ! $this->valid_path( $path ) || isset( $seen[ $path ] ) || ! $this->valid_sha( $entry['sha'] ?? null )
				|| ! in_array( $entry['mode'] ?? null, array( '100644', '100755' ), true ) ) {
				return $this->error( 'invalid_request' );
			}
			$seen[ $path ] = true;
			$tree[]        = array(
				'path' => $path,
				'mode' => $entry['mode'],
				'type' => 'blob',
				'sha'  => $entry['sha'],
			);
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/trees',
			$token,
			array(
				'base_tree' => $base_tree_sha,
				'tree'      => $tree,
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->valid_sha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	public function create_commit( string $repository, string $tree_sha, string $parent_sha, string $message, string $token ): array {
		if ( '' === $token || ! $this->valid_repository( $repository ) || ! $this->valid_sha( $tree_sha ) || ! $this->valid_sha( $parent_sha )
			|| '' === trim( $message ) || strlen( $message ) > 200 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/commits',
			$token,
			array(
				'message' => $message,
				'tree'    => $tree_sha,
				'parents' => array( $parent_sha ),
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->valid_sha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	public function create_ref( string $repository, string $branch, string $default_branch, string $sha, string $token ): array {
		if ( '' === $token || ! $this->valid_target_branch( $repository, $branch, $default_branch ) || ! $this->valid_sha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub request shape.
		$response = $this->request( 'POST', '/repos/' . $repository . '/git/refs', $token, array( 'ref' => 'refs/heads/' . $branch, 'sha' => $sha ), array( 422 => 'conflict' ) );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$created_sha = $response['data']['object']['sha'] ?? null;
		$created_ref = $response['data']['ref'] ?? null;
		return $this->valid_sha( $created_sha ) && hash_equals( $sha, $created_sha ) && is_string( $created_ref ) && hash_equals( 'refs/heads/' . $branch, $created_ref )
			? $this->ok( array( 'sha' => $created_sha ) )
			: $this->error( 'invalid_response' );
	}
	public function pull_requests( string $repository, string $branch, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || ! $this->valid_branch( $branch ) ) {
			return $this->error( 'invalid_request' );
		}
		$owner = explode( '/', $repository, 2 )[0];
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub query shape.
		$query    = http_build_query( array( 'state' => 'all', 'head' => $owner . ':' . $branch, 'per_page' => 10 ), '', '&', PHP_QUERY_RFC3986 );
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls?' . $query, $token );
		$data     = $response['data'] ?? array();
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( ! array_is_list( $data ) || count( $data ) > 10 ) {
			return $this->error( 'invalid_response' );
		}
		$pulls = array();
		foreach ( $data as $pull ) {
			$normalized = $this->normalize_pull( $repository, $pull );
			if ( null === $normalized || ! hash_equals( $branch, $normalized['head'] ) ) {
				return $this->error( 'invalid_response' );
			}
			$pulls[] = $normalized;
		}
		return $this->ok( array( 'pulls' => $pulls ) );
	}
	public function pull_request( string $repository, int $number, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || $number < 1 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls/' . $number, $token, null, array( 404 => 'missing' ) );
		$pull     = $this->normalize_pull( $repository, $response['data'] ?? null );
		return 'ok' !== $response['code'] ? $response : ( null === $pull
			? $this->error( 'invalid_response' )
			: $this->ok( array( 'pull' => $pull ) ) );
	}
	public function pull_request_file_set( string $repository, int $number, string $token = '' ): array {
		if ( ! $this->valid_repository( $repository ) || $number < 1 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls/' . $number . '/files?per_page=100', $token );
		$files    = $response['data'] ?? null;
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( ! array_is_list( $files ) || count( $files ) > self::MAX_CHANGES ) {
			return $this->error( 'invalid_response' );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $files as $file ) {
			$path = $file['filename'] ?? null;
			if ( ! is_array( $file ) || ! $this->valid_path( $path ) || isset( $seen[ $path ] )
				|| ! in_array( $file['status'] ?? null, array( 'added', 'modified' ), true )
				|| ! $this->valid_sha( $file['sha'] ?? null ) || isset( $file['previous_filename'] ) ) {
				return $this->error( 'invalid_response' );
			}
			$seen[ $path ] = true;
			$normalized[]  = array(
				'path'   => $path,
				'status' => $file['status'],
				'sha'    => $file['sha'],
			);
		}
		usort( $normalized, static fn ( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );
		return $this->ok( array( 'files' => $normalized ) );
	}
	public function create_draft_pull_request( string $repository, string $branch, string $default_branch, string $title, string $body, string $token ): array {
		if ( '' === $token || ! $this->valid_target_branch( $repository, $branch, $default_branch ) || '' === trim( $title )
			|| strlen( $title ) > 120 || strlen( $body ) > 8000
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title . $body ) ) {
			return $this->error( 'invalid_request' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub request shape.
		$response = $this->request( 'POST', '/repos/' . $repository . '/pulls', $token, array( 'title' => $title, 'head' => $branch, 'base' => $default_branch, 'body' => $body, 'draft' => true ), array( 422 => 'conflict' ) );
		$pull     = $this->normalize_pull( $repository, $response['data'] ?? null );
		return 'ok' !== $response['code'] ? $response : ( null === $pull || ! $pull['draft'] || ! hash_equals( $branch, $pull['head'] ) || ! hash_equals( $default_branch, $pull['base'] )
			? $this->error( 'invalid_response' )
			: $this->ok( array( 'pull' => $pull ) ) );
	}
	/** @param array<string,mixed>|null $body @param array<int,string> $special @return array<string,mixed> */
	private function request( string $method, string $path, string $token, ?array $body = null, array $special = array(), bool $raw_prefix = false ): array {
		if ( ! $this->valid_token( $token ) || ! str_starts_with( $path, '/repos/' ) ) {
			return $this->error( 'invalid_request' );
		}
		$headers = array(
			'Accept'               => $raw_prefix ? 'application/vnd.github.raw+json' : 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2026-03-10',
			'User-Agent'           => 'RAN-Booster-Release-Deployments',
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'headers'             => $headers,
			'timeout'             => 12,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $raw_prefix ? 43 : self::MAX_BODY,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			if ( ! is_string( $args['body'] ) ) {
				return $this->error( 'invalid_request' );
			}
		}
		try {
			$response = ( $this->send )( $method, self::API_ROOT . $path, $args );
		} catch ( Throwable ) {
			return $this->error( 'remote_unavailable' );
		}
		if ( ! is_array( $response ) ) {
			return $this->error( 'remote_unavailable' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( isset( $special[ $status ] ) ) {
			return $this->error( $special[ $status ] );
		}
		if ( 403 === $status && '0' === wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			return $this->error( 'rate_limited' );
		}
		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return $this->error( 'unauthorised' );
		}
		if ( 429 === $status ) {
			return $this->error( 'rate_limited' );
		}
		if ( $status < 200 || $status >= 300 || ! is_string( $response['body'] ?? '' ) || strlen( $raw ) > self::MAX_BODY ) {
			return $this->error( 'remote_unavailable' );
		}
		if ( $raw_prefix ) {
			return 200 === $status && strlen( $raw ) <= 43 ? $this->ok( array( 'content' => $raw ) ) : $this->error( 'invalid_response' );
		}
		try {
			$data = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( Throwable ) {
			return $this->error( 'invalid_response' );
		}
		return is_array( $data ) ? $this->ok( array( 'data' => $data ) ) : $this->error( 'invalid_response' );
	}
	private function normalize_pull( string $repository, mixed $data ): ?array {
		if ( ! is_array( $data ) || ! is_int( $data['number'] ?? null ) || $data['number'] < 1
			|| ! in_array( $data['state'] ?? null, array( 'open', 'closed' ), true )
			|| ! $this->valid_sha( $data['head']['sha'] ?? null ) || ! $this->valid_branch( $data['head']['ref'] ?? null )
			|| ! $this->valid_sha( $data['base']['sha'] ?? null ) || ! $this->valid_branch( $data['base']['ref'] ?? null )
			|| ! is_string( $data['head']['repo']['full_name'] ?? null ) || 0 !== strcasecmp( $repository, $data['head']['repo']['full_name'] )
			|| ! is_string( $data['base']['repo']['full_name'] ?? null ) || 0 !== strcasecmp( $repository, $data['base']['repo']['full_name'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return array( 'number' => $data['number'], 'state' => $data['state'], 'draft' => true === ( $data['draft'] ?? null ), 'merged' => is_string( $data['merged_at'] ?? null ) && '' !== $data['merged_at'], 'head' => $data['head']['ref'], 'base' => $data['base']['ref'], 'head_sha' => $data['head']['sha'], 'base_sha' => $data['base']['sha'] );
	}
	private function valid_target_branch( string $repository, string $branch, string $default_branch ): bool {
		return $this->valid_repository( $repository ) && $this->valid_branch( $branch )
			&& $this->valid_branch( $default_branch ) && ! hash_equals( $default_branch, $branch );
	}
	private function valid_repository( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z/D', $value );
	}
	private function valid_branch( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 191
			&& 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $value )
			&& ! str_contains( $value, '..' ) && ! str_contains( $value, '//' ) && ! str_contains( $value, '@{' )
			&& ! str_ends_with( $value, '.lock' );
	}
	private function valid_path( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 512
			&& ! str_starts_with( $value, '/' ) && ! str_contains( $value, '\\' ) && ! str_contains( $value, "\0" )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(/|\z)#', $value ) && 1 === preg_match( '//u', $value );
	}
	private function assessment_document( string $path ): bool {
		return ( ! str_contains( $path, '/' ) && ( str_ends_with( strtolower( $path ), '.php' )
			|| in_array( $path, array( 'style.css', 'package.json', 'readme.txt', '.prettierignore', InitialReleaseBundle::ORIGIN_PATH, 'release-please-config.json' ), true ) ) )
			|| ( str_starts_with( $path, '.github/workflows/' ) && 1 === preg_match( '/\.ya?ml\z/i', $path ) )
			|| ( ( str_starts_with( $path, 'scripts/' ) || str_starts_with( $path, '.github/scripts/' ) || str_starts_with( $path, '.ci/' ) )
				&& str_ends_with( strtolower( $path ), '.sh' ) )
			|| in_array( $path, array( 'composer.json', 'Makefile' ), true )
			|| in_array(
				$path,
				array(
					InitialReleaseBundle::WORKFLOW_PATH,
					'scripts/build-release.sh',
					'scripts/verify-release.sh',
					'scripts/upload-release-assets.sh',
				),
				true
			)
			|| str_ends_with( $path, 'block.json' ) || str_ends_with( strtolower( $path ), '.pot' );
	}
	private function valid_sha( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $value );
	}
	private function valid_token( string $value ): bool {
		return strlen( $value ) <= 255 && 0 === preg_match( '/[\x00-\x20\x7F]/', $value );
	}
	private function numeric_string( mixed $value ): ?string {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		return strlen( $value ) <= 191 && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value ) ? $value : null;
	}
	/** @param array<string,mixed> $values @return array<string,mixed> */
	private function ok( array $values ): array {
		return array_merge( array( 'code' => 'ok' ), $values );
	}
	private function error( string $code ): array {
		return array( 'code' => $code );
	}
}
