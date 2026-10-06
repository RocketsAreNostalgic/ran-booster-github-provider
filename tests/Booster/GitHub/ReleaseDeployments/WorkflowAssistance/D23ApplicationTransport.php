<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;
use function RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\wp_json_encode;
use function RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\wp_parse_url;

final class D23ApplicationTransport {
	private const REPOSITORY  = 'owner/example-plugin';
	private const BASE        = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const BASE_TREE   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const HEAD        = 'cccccccccccccccccccccccccccccccccccccccc';
	private const HEAD_TREE   = 'dddddddddddddddddddddddddddddddddddddddd';
	private const UPDATE_HEAD = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	private const UPDATE_TREE = 'ffffffffffffffffffffffffffffffffffffffff';
	/** @var list<array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<string,int> */
	public array $write_counts           = array(
		'blob'   => 0,
		'tree'   => 0,
		'commit' => 0,
		'ref'    => 0,
		'pull'   => 0,
	);
	private array $blobs                 = array();
	private array $base_entries          = array();
	private array $original_base_entries = array();
	private array $head_entries          = array();
	private bool $branch_exists          = false;
	private bool $pull_exists            = false;
	private string $base_sha             = self::BASE;
	private string $pull_state           = 'open';
	private ?string $merged_at           = null;
	private string $pull_base_sha        = self::BASE;
	private string $pull_scenario        = 'none';
	private string $uncertain_at         = '';
	private string $uncertain_blob       = '';
	private string $branch_head          = '';
	private string $created_tree         = self::HEAD_TREE;
	private int $repository_status       = 200;
	/** @var array<int,string> */
	private array $archives     = array();
	private int $latest_release = TemplatePackApi3Fixture::RELEASE_ID;

	public function __construct( private readonly bool $lost_acknowledgements = false, string $package_type = 'plugin', private readonly string $asset_content_type = 'application/zip' ) {
		$this->archives[ TemplatePackApi3Fixture::RELEASE_ID ] = TemplatePackApi3Fixture::archive();
		if ( 'theme' === $package_type ) {
			$this->add_base( 'style.css', "/*\nTheme Name: Example Theme\nRequires PHP: 8.2\nRequires at least: 7.0\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/example-plugin\n*/\n" );
			$this->add_base( 'templates/index.html', '<!-- wp:paragraph --><p>Theme</p><!-- /wp:paragraph -->' );
		} else {
			$this->add_base( 'example-plugin.php', "<?php\n/**\n * Plugin Name: Example Plugin\n * Requires PHP: 8.2\n * Requires at least: 7.0\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n" );
			$this->add_base( 'src/Runtime.php', "<?php\nnamespace Example;\n" );
		}
	}
	public function merge_pull(): void {
		$this->original_base_entries = $this->base_entries;
		$this->base_sha              = $this->branch_head;
		$this->base_entries          = $this->head_entries;
		$this->pull_base_sha         = $this->base_sha;
		$this->pull_state            = 'closed';
		$this->merged_at             = '2026-08-11T12:00:00Z';
	}
	public function close_pull(): void {
		$this->pull_state = 'closed';
		$this->merged_at  = null;
	}
	public function reopen_pull(): void {
		$this->pull_state = 'open';
		$this->merged_at  = null;
	}
	public function drift_pull_base(): void {
		$this->pull_base_sha = str_repeat( '9', 40 );
	}
	public function mutate_default_document( string $path, string $content ): void {
		$this->add_base( $path, $content );
	}
	public function remove_default_document( string $path ): void {
		unset( $this->base_entries[ $path ], $this->head_entries[ $path ] );
	}
	/** @param callable(array<string,mixed>):array<string,mixed> $mutate */
	public function seed_pull_scenario( string $scenario ): void {
		$this->pull_scenario = $scenario;
	}
	public function fail_write_acknowledgement( string $operation ): void {
		$this->uncertain_at = $operation;
	}
	public function fail_repository_read( int $status ): void {
		$this->repository_status = $status;
	}
	/** @param array<string,mixed> $args */
	public function __invoke( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query            = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( str_contains( $path, '/ran-booster-release-bootstrap-templates' ) ) {
			return $this->template( $path );
		}
		if ( '/repos/' . self::REPOSITORY === $path ) {
			if ( 200 !== $this->repository_status ) {
				return $this->json( $this->repository_status, array() );
			}
			return $this->json(
				200,
				array(
					'id'             => 101,
					'full_name'      => self::REPOSITORY,
					'default_branch' => 'main',
				)
			);
		}
		if ( str_starts_with( $path, '/repos/' . self::REPOSITORY . '/git/ref/heads/' ) ) {
			$branch = rawurldecode( substr( $path, strrpos( $path, '/' ) + 1 ) );
			if ( 'main' === $branch ) {
				return $this->json(
					200,
					array(
						'ref'    => 'refs/heads/main',
						'object' => array( 'sha' => $this->base_sha ),
					)
				);
			}
			return $this->branch_exists ? $this->json(
				200,
				array(
					'ref'    => 'refs/heads/' . $branch,
					'object' => array( 'sha' => $this->branch_head ),
				)
			) : $this->json( 404, array() );
		}
		if ( str_starts_with( $path, '/repos/' . self::REPOSITORY . '/git/commits/' ) && 'GET' === $method ) {
			$sha = basename( $path );
			return $this->json(
				200,
				array(
					'sha'     => $sha,
					'tree'    => array( 'sha' => self::BASE === $sha ? self::BASE_TREE : ( self::HEAD === $sha ? self::HEAD_TREE : self::UPDATE_TREE ) ),
					'parents' => self::BASE === $sha ? array() : array( array( 'sha' => self::HEAD === $sha ? self::BASE : self::HEAD ) ),
				)
			);
		}
		if ( str_contains( $path, '/git/trees/' ) && 'GET' === $method ) {
			$sha     = basename( $path );
			$entries = in_array( $sha, array( self::HEAD, self::UPDATE_HEAD ), true ) ? $this->head_entries : $this->base_entries;
			return $this->json(
				200,
				array(
					'truncated' => false,
					'tree'      => array_values( $entries ),
				)
			);
		}
		if ( str_contains( $path, '/git/blobs/' ) && 'GET' === $method ) {
			if ( '' !== $this->uncertain_blob && hash_equals( $this->uncertain_blob, basename( $path ) ) ) {
				return $this->json( 500, array() );
			}
			$content = $this->blobs[ basename( $path ) ] ?? '';
			if ( 'application/vnd.github.raw+json' === ( $args['headers']['Accept'] ?? '' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => substr( $content, 0, $args['limit_response_size'] ),
				);
			}
			return $this->json(
				200,
				array(
					'encoding' => 'base64',
					'size'     => strlen( $content ),
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
					'content'  => base64_encode( $content ),
				)
			);
		}
		if ( str_ends_with( $path, '/git/blobs' ) && 'POST' === $method ) {
			++$this->write_counts['blob'];
			$body                = $this->body( $args );
			$sha                 = sha1( 'blob ' . strlen( $body['content'] ) . "\0" . $body['content'] );
			$this->blobs[ $sha ] = $body['content'];
			if ( 'blob' === $this->uncertain_at && 1 === $this->write_counts['blob'] ) {
				$this->uncertain_blob = $sha;
				return $this->json( 500, array() );
			}
			return $this->json( 201, array( 'sha' => $sha ) );
		}
		if ( str_ends_with( $path, '/git/trees' ) && 'POST' === $method ) {
			++$this->write_counts['tree'];
			$this->created_tree = self::HEAD === $this->base_sha ? self::UPDATE_TREE : self::HEAD_TREE;
			$this->head_entries = $this->base_entries;
			foreach ( $this->body( $args )['tree'] as $entry ) {
				$content                              = $this->blobs[ $entry['sha'] ];
				$this->head_entries[ $entry['path'] ] = array(
					'path' => $entry['path'],
					'type' => 'blob',
					'mode' => $entry['mode'],
					'sha'  => $entry['sha'],
					'size' => strlen( $content ),
				);
			}
			return 'tree' === $this->uncertain_at ? $this->json( 500, array() ) : $this->json( 201, array( 'sha' => $this->created_tree ) );
		}
		if ( str_ends_with( $path, '/git/commits' ) && 'POST' === $method ) {
			++$this->write_counts['commit'];
			$head = self::HEAD === $this->base_sha ? self::UPDATE_HEAD : self::HEAD;
			return 'commit' === $this->uncertain_at ? $this->json( 500, array() ) : $this->json( 201, array( 'sha' => $head ) );
		}
		if ( str_ends_with( $path, '/git/refs' ) && 'POST' === $method ) {
			++$this->write_counts['ref'];
			$this->branch_exists = true;
			$body                = $this->body( $args );
			$ref                 = $body['ref'];
			$this->branch_head   = $body['sha'];
			return $this->lost_acknowledgements ? $this->json( 500, array() ) : $this->json(
				201,
				array(
					'ref'    => $ref,
					'object' => array( 'sha' => $this->branch_head ),
				)
			);
		}
		if ( str_ends_with( $path, '/pulls' ) && 'GET' === $method ) {
			parse_str( $query, $parameters );
			$head  = is_string( $parameters['head'] ?? null ) ? explode( ':', $parameters['head'], 2 )[1] : '';
			$pulls = match ( $this->pull_scenario ) {
				'closed' => array( $this->pull( $head, 'closed' ) ),
				'wrong_base' => array( $this->pull( $head, 'open', 'develop' ) ),
				'duplicate' => array( $this->pull( $head ), $this->pull( $head ) ),
				default => $this->pull_exists ? array( $this->pull() ) : array(),
			};
			return $this->json( 200, $pulls );
		}
		if ( str_ends_with( $path, '/pulls' ) && 'POST' === $method ) {
			++$this->write_counts['pull'];
			$this->pull_exists = true;
			return $this->lost_acknowledgements ? $this->json( 500, array() ) : $this->json( 201, $this->pull() );
		}
		if ( str_ends_with( $path, '/pulls/17' ) ) {
			return $this->json( 200, $this->pull() );
		}
		if ( str_ends_with( $path, '/pulls/17/files' ) ) {
			$files           = array();
			$comparison_base = null === $this->merged_at ? $this->base_entries : $this->original_base_entries;
			foreach ( $this->head_entries as $path_name => $entry ) {
				if ( ! isset( $comparison_base[ $path_name ] ) || $comparison_base[ $path_name ]['sha'] !== $entry['sha'] ) {
					$files[] = array(
						'filename' => $path_name,
						'status'   => isset( $comparison_base[ $path_name ] ) ? 'modified' : 'added',
						'sha'      => $entry['sha'],
					);
				}
			}
			return $this->json( 200, $files );
		}
		return $this->json( 500, array( 'unexpected' => $method . ' ' . $path . '?' . $query ) );
	}
	private function template( string $path ): array {
		$release_id = $this->latest_release;
		if ( 1 === preg_match( '#/releases/([0-9]+)\z#', $path, $matches ) ) {
			$release_id = (int) $matches[1];
		}
		$archive = $this->archives[ $release_id ] ?? '';
		$release = array(
			'id'               => $release_id,
			'tag_name'         => 42 === $release_id ? 'v1.2.4' : 'v1.2.3',
			'target_commitish' => TemplatePackApi3Fixture::COMMIT,
			'draft'            => false,
			'prerelease'       => false,
			'immutable'        => true,
			'assets'           => array(
				array(
					'id'           => 42 === $release_id ? 74 : TemplatePackApi3Fixture::ASSET_ID,
					'name'         => TemplatePackApi3Fixture::ASSET_NAME,
					'size'         => strlen( $archive ),
					'state'        => 'uploaded',
					'content_type' => $this->asset_content_type,
					'digest'       => 'sha256:' . hash( 'sha256', $archive ),
				),
			),
		);
		if ( str_ends_with( $path, '/ran-booster-release-bootstrap-templates' ) ) {
			return $this->json(
				200,
				array(
					'id'        => TemplatePackApi3Fixture::REPOSITORY_ID,
					'full_name' => TemplatePackApi3Fixture::REPOSITORY,
				)
			);
		}
		if ( str_ends_with( $path, '/releases' ) ) {
			$releases = array( $release );
			if ( 42 === $release_id ) {
				$old                  = $this->latest_release;
				$this->latest_release = TemplatePackApi3Fixture::RELEASE_ID;
				$old_release          = $this->template( '/releases/' . TemplatePackApi3Fixture::RELEASE_ID );
				$this->latest_release = $old;
				$old_body             = json_decode( $old_release['body'], true );
				if ( is_array( $old_body ) ) {
					$releases[] = $old_body;
				}
			}
			return $this->json( 200, $releases );
		}
		if ( str_contains( $path, '/releases/' ) && ! str_contains( $path, '/assets/' ) ) {
			return $this->json( 200, $release );
		}
		if ( str_contains( $path, '/git/ref/tags/' ) ) {
			return $this->json(
				200,
				array(
					'object' => array(
						'type' => 'commit',
						'sha'  => TemplatePackApi3Fixture::COMMIT,
					),
				)
			);
		}
		if ( str_contains( $path, '/commits/' ) ) {
			return $this->json( 200, array( 'sha' => TemplatePackApi3Fixture::COMMIT ) );
		}
		if ( str_contains( $path, '/releases/assets/' ) ) {
			$asset_id = (int) basename( $path );
			$archive  = 74 === $asset_id ? ( $this->archives[42] ?? '' ) : $this->archives[ TemplatePackApi3Fixture::RELEASE_ID ];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $archive,
			);
		}
		return $this->json( 500, array() );
	}
	private function add_base( string $path, string $content ): void {
		$sha                         = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
		$this->blobs[ $sha ]         = $content;
		$this->base_entries[ $path ] = array(
			'path' => $path,
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => $sha,
			'size' => strlen( $content ),
		);
		if ( null === $this->merged_at ) {
			$this->head_entries = $this->base_entries;
		}
	}
	/** @param array<string,mixed> $args @return array<string,mixed> */
	private function body( array $args ): array {
		$value = json_decode( (string) $args['body'], true );
		return is_array( $value ) ? $value : array();
	}
	private function pull( string $name = '', string $state = '', string $base = 'main' ): array {
		$branch   = array_values( array_filter( $this->requests, static fn ( array $request ): bool => str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), '/git/refs' ) ) );
		$ref_body = array() !== $branch ? $this->body( $branch[ array_key_last( $branch ) ]['args'] ) : array( 'ref' => 'refs/heads/ran-booster/release-setup-v2-aaaaaaaaaaaa-unknown' );
		$name     = '' !== $name ? $name : substr( $ref_body['ref'], strlen( 'refs/heads/' ) );
		$state    = '' !== $state ? $state : $this->pull_state;
		return array(
			'number'    => 17,
			'state'     => $state,
			'draft'     => true,
			'merged_at' => $this->merged_at,
			'head'      => array(
				'ref'  => $name,
				'sha'  => '' !== $this->branch_head ? $this->branch_head : self::HEAD,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
			'base'      => array(
				'ref'  => $base,
				'sha'  => $this->pull_base_sha,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
		);
	}
	/** @param array<string,mixed> $body */
	private function json( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}
}
