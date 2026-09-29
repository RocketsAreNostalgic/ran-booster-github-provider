<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use function RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\wp_json_encode;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

final class GitHubRepositoryClientTest extends TestCase {
	private const REPOSITORY = 'owner/example-plugin';
	private const SHA        = '0123456789abcdef0123456789abcdef01234567';
	private const TREE       = '1111111111111111111111111111111111111111';
	private const BLOB       = '2222222222222222222222222222222222222222';

	public function testExactRepositoryBranchCommitAndSnapshotReadsAreBounded(): void {
		$header    = "<?php\n/** Plugin Name: Example\n * Requires PHP: 8.2\n * Requires at least: 7.0\n * Version: 1.2.3\n */\n";
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'id'             => 101,
						'full_name'      => self::REPOSITORY,
						'default_branch' => 'main',
					)
				),
				$this->response(
					200,
					array(
						'ref'    => 'refs/heads/main',
						'object' => array( 'sha' => self::SHA ),
					)
				),
				$this->response(
					200,
					array(
						'sha'     => self::SHA,
						'tree'    => array( 'sha' => self::TREE ),
						'parents' => array(),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array(
							array(
								'path' => 'example.php',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => self::BLOB,
								'size' => strlen( $header ),
							),
						),
					)
				),
				$this->response(
					200,
					array(
						'encoding' => 'base64',
						'size'     => strlen( $header ),
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
						'content'  => base64_encode( $header ),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertSame( '101', $client->repository( self::REPOSITORY )['repository_id'] );
		self::assertSame( self::SHA, $client->branchRef( self::REPOSITORY, 'main' )['sha'] );
		self::assertSame( self::TREE, $client->gitCommit( self::REPOSITORY, self::SHA )['tree_sha'] );
		$snapshot = $client->snapshot( self::REPOSITORY, '101', 'main', self::SHA );
		self::assertSame( $header, $snapshot['snapshot']->document( 'example.php' ) );
		self::assertSame( array( 'GET', 'GET', 'GET', 'GET', 'GET' ), array_column( $transport->requests, 'method' ) );
	}

	public function testSnapshotRejectsDuplicateTruncatedUnsafeAndNonTextEvidence(): void {
		$entry     = array(
			'path' => 'example.php',
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => self::BLOB,
			'size' => 4,
		);
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'truncated' => true,
						'tree'      => array(),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( $entry, $entry ),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( array_replace( $entry, array( 'path' => '../bad' ) ) ),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( $entry ),
					)
				),
				$this->response(
					200,
					array(
						'encoding' => 'base64',
						'size'     => 4,
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
						'content'  => base64_encode( "a\0bc" ),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		for ( $index = 0; $index < 4; ++$index ) {
			self::assertSame( 'invalid_response', $client->snapshot( self::REPOSITORY, '101', 'main', self::SHA )['code'] );
		}
	}

	public function testSnapshotReadsExactAlternateAutomationEvidenceAndSkipsUnrelatedDocuments(): void {
		$documents = array(
			'example.php'                       => "<?php\n/** Plugin Name: Example */\n",
			'.github/workflows/ci.yml'          => "steps:\n  - run: composer check\n",
			'scripts/package.sh'                => "#!/bin/sh\ngh release view v1.2.3\n",
			'.github/scripts/release.sh'        => "#!/bin/sh\nprintf release\n",
			'.ci/publish.sh'                    => "#!/bin/sh\nprintf publish\n",
			'composer.json'                     => '{}',
			'Makefile'                          => "check:\n\tcomposer check\n",
			'config/release-please-config.json' => '{}',
			'docs/release.sh'                   => "#!/bin/sh\ngh release create v1\n",
		);
		$tree      = array();
		$responses = array();
		foreach ( $documents as $path => $content ) {
			$tree[] = array(
				'path' => $path,
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $content ),
			);
		}
		$responses[] = $this->response(
			200,
			array(
				'truncated' => false,
				'tree'      => $tree,
			)
		);
		foreach ( $documents as $path => $content ) {
			if ( in_array( $path, array( 'config/release-please-config.json', 'docs/release.sh' ), true ) ) {
				continue;
			}
			$responses[] = $this->response(
				200,
				array(
					'encoding' => 'base64',
					'size'     => strlen( $content ),
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
					'content'  => base64_encode( $content ),
				)
			);
		}

		$transport = new D23GitHubTransport( $responses );
		$result    = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA );
		self::assertSame( 'ok', $result['code'] );
		self::assertSame(
			array( '.ci/publish.sh', '.github/scripts/release.sh', '.github/workflows/ci.yml', 'Makefile', 'composer.json', 'example.php', 'scripts/package.sh' ),
			$result['snapshot']->documentPaths()
		);
		self::assertCount( 8, $transport->requests );
	}

	public function testSnapshotReadsOnlyABoundedPrefixOfLargeRuntimeBlobs(): void {
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array(
							array(
								'path' => 'build/application.js.map',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => self::BLOB,
								'size' => 1048576,
							),
							array(
								'path' => 'tests/large-fixture.bin',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => str_repeat( 'c', 40 ),
								'size' => 1048576,
							),
							array(
								'path' => 'docs/screenshot.png',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => str_repeat( 'd', 40 ),
								'size' => 1048576,
							),
							array(
								'path' => 'other/data.bin',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => str_repeat( 'e', 40 ),
								'size' => 1048576,
							),
						),
					)
				),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => str_repeat( 'a', 43 ),
				),
			)
		);
		$result    = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA );

		self::assertSame( 'ok', $result['code'] );
		self::assertSame( 1048576, $result['snapshot']->entries()['build/application.js.map']['size'] );
		self::assertSame( array(), $result['snapshot']->documentPaths() );
		self::assertCount( 2, $transport->requests );
		self::assertSame( 43, $transport->requests[1]['args']['limit_response_size'] );
		self::assertSame( 'application/vnd.github.raw+json', $transport->requests[1]['args']['headers']['Accept'] );
		self::assertSame( str_repeat( 'a', 43 ), $result['snapshot']->blobPrefix( 'build/application.js.map' ) );
		self::assertNull( $result['snapshot']->blobPrefix( 'tests/large-fixture.bin' ) );
	}

	public function testRuntimeAssetPrefixIsInspectedBeforeSourceReadiness(): void {
		foreach ( array( "version https://git-lfs.github.com/spec/v1\n" . str_repeat( 'x', 2048 ), str_repeat( "\0", 2048 ) ) as $content ) {
			$transport = new D23ApplicationTransport();
			$transport->mutateDefaultDocument( 'assets/logo.png', $content );
			$result = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', str_repeat( 'a', 40 ) );
			self::assertSame( 'ok', $result['code'] );
			self::assertNull( $result['snapshot']->document( 'assets/logo.png' ) );
			$assessment = ( new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor() )->assess( $result['snapshot'], 'plugin', 'example-plugin', '1.2.3', 'https://github.com/' . self::REPOSITORY );
			self::assertSame( str_starts_with( $content, "\0" ), $assessment->readyForBootstrap() );
			foreach ( $transport->requests as $request ) {
				self::assertSame( 'GET', $request['method'] );
				self::assertSame( 0, $request['args']['redirection'] );
			}
		}
	}

	public function testThemeRootJsonPrefixIsFetchedWithoutReadingDevelopmentBlobs(): void {
		$transport = new D23ApplicationTransport();
		$transport->mutateDefaultDocument( 'theme.json', str_repeat( '{', 2048 ) );
		$result = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', str_repeat( 'a', 40 ) );
		self::assertSame( 'ok', $result['code'] );
		self::assertSame( str_repeat( '{', 43 ), $result['snapshot']->blobPrefix( 'theme.json' ) );
	}

	public function testShortOversizedOrUnavailableBlobPrefixFailsClosed(): void {
		$entry = array(
			'path' => 'assets/logo.png',
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => self::BLOB,
			'size' => 1234,
		);
		foreach ( array( array( 200, '' ), array( 200, str_repeat( 'a', 42 ) ), array( 200, str_repeat( 'a', 44 ) ), array( 503, '' ), array( 302, '' ) ) as [ $status, $body ] ) {
			$transport = new D23GitHubTransport(
				array(
					$this->response(
						200,
						array(
							'truncated' => false,
							'tree'      => array( $entry ),
						)
					),
					array(
						'response' => array( 'code' => $status ),
						'body'     => $body,
					),
				)
			);
			self::assertNotSame( 'ok', ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA )['code'] );
		}
	}

	public function testSnapshotRefusesMoreThanTheBoundedAdmissionDocumentSetBeforeBlobReads(): void {
		$tree = array();
		for ( $index = 0; $index < 257; ++$index ) {
			$tree[] = array(
				'path' => '.github/workflows/check-' . $index . '.yml',
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( 'workflow-' . $index ),
				'size' => 1,
			);
		}
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => $tree,
					)
				),
			)
		);
		$result    = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA );

		self::assertSame( 'invalid_response', $result['code'] );
		self::assertCount( 1, $transport->requests );
	}

	public function testGitObjectDraftAndRefWritesHaveNoUpdateMergeOrSecretAuthority(): void {
		$pull      = $this->pull( 17, 'open', 'ran-booster/setup', 'main', self::SHA );
		$transport = new D23GitHubTransport(
			array(
				$this->response( 201, array( 'sha' => self::BLOB ) ),
				$this->response( 201, array( 'sha' => self::TREE ) ),
				$this->response( 201, array( 'sha' => self::SHA ) ),
				$this->response(
					201,
					array(
						'ref'    => 'refs/heads/ran-booster/setup',
						'object' => array( 'sha' => self::SHA ),
					)
				),
				$this->response( 201, $pull ),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertSame( 'ok', $client->createBlob( self::REPOSITORY, 'bytes', 'secret-token' )['code'] );
		self::assertSame(
			'ok',
			$client->createTree(
				self::REPOSITORY,
				self::TREE,
				array(
					array(
						'path' => 'file.php',
						'sha'  => self::BLOB,
						'mode' => '100644',
					),
				),
				'secret-token'
			)['code']
		);
		self::assertSame( 'ok', $client->createCommit( self::REPOSITORY, self::TREE, self::BLOB, 'chore: exact', 'secret-token' )['code'] );
		self::assertSame( 'ok', $client->createRef( self::REPOSITORY, 'ran-booster/setup', 'main', self::SHA, 'secret-token' )['code'] );
		self::assertSame( 'ok', $client->createDraftPullRequest( self::REPOSITORY, 'ran-booster/setup', 'main', 'Title', 'Body', 'secret-token' )['code'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'Bearer secret-token', $request['args']['headers']['Authorization'] );
			self::assertStringNotContainsString( 'secret-token', (string) ( $request['args']['body'] ?? '' ) );
		}
		self::assertSame( array( 'POST', 'POST', 'POST', 'POST', 'POST' ), array_column( $transport->requests, 'method' ) );
		self::assertTrue( $this->body( $transport, 4 )['draft'] );
	}

	public function testPullReadbackAndFileSetAreExactSortedAndBounded(): void {
		$transport = new D23GitHubTransport(
			array(
				$this->response( 200, array( $this->pull( 17, 'open', 'ran-booster/setup', 'main', self::SHA ) ) ),
				$this->response( 200, $this->pull( 17, 'closed', 'ran-booster/setup', 'main', self::SHA, 'now' ) ),
				$this->response(
					200,
					array(
						array(
							'filename' => 'z.php',
							'status'   => 'added',
							'sha'      => self::BLOB,
						),
						array(
							'filename' => 'a.php',
							'status'   => 'modified',
							'sha'      => self::SHA,
						),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertFalse( $client->pullRequests( self::REPOSITORY, 'ran-booster/setup' )['pulls'][0]['merged'] );
		self::assertTrue( $client->pullRequest( self::REPOSITORY, 17 )['pull']['merged'] );
		$files = $client->pullRequestFileSet( self::REPOSITORY, 17 );
		self::assertSame( array( 'a.php', 'z.php' ), array_column( $files['files'], 'path' ) );
		self::assertStringEndsWith( '/pulls/17/files?per_page=100', $transport->requests[2]['url'] );
	}

	public function testMalformedInputsResponsesAndConflictsFailClosed(): void {
		$wrong                              = $this->pull( 17, 'open', 'setup', 'main', self::SHA );
		$wrong['head']['repo']['full_name'] = 'owner/other';
		$transport                          = new D23GitHubTransport( array( $this->response( 200, $wrong ), $this->response( 422, array() ), $this->response( 422, array() ) ) );
		$client                             = new GitHubRepositoryClient( $transport );
		self::assertSame( 'invalid_response', $client->pullRequest( self::REPOSITORY, 17 )['code'] );
		self::assertSame( 'invalid_request', $client->createBlob( self::REPOSITORY, "bad\0bytes", 'token' )['code'] );
		self::assertSame( 'invalid_request', $client->createRef( self::REPOSITORY, 'main', 'main', self::SHA, 'token' )['code'] );
		self::assertSame( 'conflict', $client->createRef( self::REPOSITORY, 'setup', 'main', self::SHA, 'token' )['code'] );
		self::assertSame( 'conflict', $client->createDraftPullRequest( self::REPOSITORY, 'setup', 'main', 'Title', 'Body', 'token' )['code'] );
		self::assertSame( 'invalid_request', $client->repository( '../unsafe', "bad\ntoken" )['code'] );
	}
	public function testRateLimitedResponsesInclude429AndOnlyExhausted403Responses(): void {
		$transport = new D23GitHubTransport(
			array(
				$this->response( 403, array(), array( 'x-ratelimit-remaining' => '0' ) ),
				$this->response( 403, array(), array( 'x-ratelimit-remaining' => '1' ) ),
				$this->response( 401, array(), array( 'x-ratelimit-remaining' => '0' ) ),
				$this->response( 429, array() ),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertSame( 'rate_limited', $client->repository( self::REPOSITORY )['code'] );
		self::assertSame( 'unauthorised', $client->repository( self::REPOSITORY )['code'] );
		self::assertSame( 'unauthorised', $client->repository( self::REPOSITORY )['code'] );
		self::assertSame( 'rate_limited', $client->repository( self::REPOSITORY )['code'] );
	}

	/** @return array<string,mixed> */
	private function response( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}
	/** @return array<string,mixed> */
	private function pull( int $number, string $state, string $head, string $base, string $sha, ?string $merged = null ): array {
		return array(
			'number'    => $number,
			'state'     => $state,
			'draft'     => true,
			'merged_at' => $merged,
			'head'      => array(
				'ref'  => $head,
				'sha'  => $sha,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
			'base'      => array(
				'ref'  => $base,
				'sha'  => self::SHA,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
		);
	}
	/** @return array<string,mixed> */
	private function body( D23GitHubTransport $transport, int $index ): array {
		$body = json_decode( (string) $transport->requests[ $index ]['args']['body'], true );
		self::assertIsArray( $body );
		return $body;
	}
}
