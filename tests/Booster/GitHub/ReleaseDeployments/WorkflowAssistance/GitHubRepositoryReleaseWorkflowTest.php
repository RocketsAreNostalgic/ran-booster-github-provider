<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryReleaseWorkflow;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\WorkflowProviderFixtures;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/WorkflowCredentialStore.php';
require_once __DIR__ . '/Support/WorkflowProviderFixtures.php';

final class GitHubRepositoryReleaseWorkflowTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']    = array();
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The workflow record fixture requires the same connection-local advisory-lock double as its persistence tests.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
	}

	public function testPassiveStatusUsesOnlyDisplaySafeProfilesAndExactActionsUrl(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();

		$result = $workflow->status( $status );

		self::assertSame( 'https://github.com/owner/example-plugin/actions', $result->providerWorkflowUrl() );
		self::assertSame(
			array(
				array(
					'id'    => 'eligible',
					'label' => 'Repository access (classic)',
				),
			),
			$result->credentialChoices()
		);
		self::assertSame( 1, $credentials->profileReads );
		self::assertSame( array(), $credentials->materialReads );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function testStatusUsesStoredRecordIdentityWhenRecordOccupiesRepository(): void {
		$records = new SetupRecordStore();
		self::assertTrue(
			$records->save(
				$this->record(
					array(
						'repo_id'            => '101',
						'package_type'       => 'theme',
						'package_identifier' => 'foreign-plugin/foreign-plugin.php',
						'source_revision'    => 5,
					)
				)
			)
		);

		$workflow = $this->workflow( new WorkflowCredentialStore() );
		$status   = WorkflowProviderFixtures::target();
		$result   = $workflow->status( $status );

		self::assertSame( 'theme', $result->packageType() );
		self::assertSame( 'foreign-plugin/foreign-plugin.php', $result->packageIdentifier() );
		self::assertSame( 5, $result->sourceRevision() );
		self::assertFalse( $result->recordExact() );
		self::assertTrue( $result->recordOccupied() );
	}

	public function testStatusPreservesBootstrapOperationAcrossSourceRevisionsWithoutWriting(): void {
		$records = new SetupRecordStore();
		self::assertTrue( $records->save( $this->record() ) );
		$before      = $GLOBALS['ran_booster_release_deployments_test_options'];
		$credentials = new WorkflowCredentialStore();
		$transport   = new D23ApplicationTransport();
		$workflow    = $this->workflow( $credentials, $records, $transport );

		foreach ( array( 3, 4 ) as $revision ) {
			$result = $workflow->status( WorkflowProviderFixtures::target( sourceRevision: $revision ) );
			self::assertSame( 3 === $revision, $result->recordExact() );
			self::assertTrue( $result->recordOccupied() );
			self::assertSame( 'bootstrap', $result->recordOperation() );
			self::assertSame( 3, $result->sourceRevision() );
			self::assertSame( 'plugin', $result->packageType() );
			self::assertSame( 'example-plugin/example-plugin.php', $result->packageIdentifier() );
		}
		self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertSame( array(), $credentials->materialReads );
		self::assertSame( array(), $transport->requests );
	}

	public function testStatusDoesNotProjectAnOperationFromRetiredOrMalformedOccupiedRecords(): void {
		foreach ( array(
			array(
				'schema_version' => 2,
				'operation'      => 'template_update',
			),
			array( 'head_sha' => 'invalid' ),
		) as $overrides ) {
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records']['101'] = $this->record( $overrides );
			$before      = $GLOBALS['ran_booster_release_deployments_test_options'];
			$credentials = new WorkflowCredentialStore();
			$transport   = new D23ApplicationTransport();
			$result      = $this->workflow( $credentials, transport: $transport )->status( WorkflowProviderFixtures::target() );

			self::assertTrue( $result->recordOccupied() );
			self::assertFalse( $result->recordExact() );
			self::assertSame( '', $result->recordOperation() );
			self::assertSame( '', $result->pullRequestUrl() );
			self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
			self::assertSame( array(), $credentials->materialReads );
			self::assertSame( array(), $transport->requests );
		}
	}

	public function testInvalidOutcomeDoesNotReadCredentialMaterial(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();

		self::assertSame( 'workflow_invalid_request', $workflow->outcome( $status, 'eligible' )->workflowCode() );
		self::assertSame( array(), $credentials->materialReads );
		self::assertFalse( method_exists( $workflow, 'inspectUpdate' ) );
		self::assertFalse( method_exists( $workflow, 'setupUpdate' ) );
	}

	public function testUnavailableSelectedCredentialRefusesCurrentReadOperationsBeforeTransport(): void {
		$credentials                   = new WorkflowCredentialStore();
		$credentials->eligibleMaterial = null;
		$records                       = new SetupRecordStore();
		$transport                     = new D23ApplicationTransport();
		$status                        = WorkflowProviderFixtures::target();
		self::assertTrue( $records->save( $this->record() ) );
		$workflow = $this->workflow( $credentials, $records, $transport );

		self::assertSame( 'workflow_unauthorised', $workflow->inspect( $status, 'stable', WorkflowProviderFixtures::preflight(), 'eligible' )->workflowCode() );
		self::assertSame( 'workflow_unauthorised', $workflow->outcome( $status, 'eligible' )->workflowCode() );
		self::assertSame( array( 'eligible', 'eligible' ), $credentials->materialReads );
		self::assertSame( array(), $transport->requests );
	}


	public function testCredentialChoiceLabelIsUtf8SafeAndBoundedToStatusContract(): void {
		$credentials           = new WorkflowCredentialStore();
		$credentials->profiles = array(
			'eligible' => array(
				'id'         => 'eligible',
				'label'      => str_repeat( 'あ', 100 ),
				'kind'       => 'classic',
				'source'     => 'file',
				'immutable'  => false,
				'configured' => true,
			),
		);
		$status                = WorkflowProviderFixtures::target();

		$choice = $this->workflow( $credentials )->status( $status )->credentialChoices()[0];

		self::assertLessThanOrEqual( 255, strlen( $choice['label'] ) );
		self::assertSame( 1, preg_match( '//u', $choice['label'] ) );
		self::assertStringEndsWith( ' (classic)', $choice['label'] );
	}

	public function testPreflightAndIneligibleSavedCredentialAreRejectedBeforeMaterialRead(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();
		$blocked     = WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE, 'provider_unavailable' );

		$preflightResult = $workflow->inspect( $status, 'stable', $blocked, 'eligible' );
		self::assertSame( 'workflow_preflight_unavailable', $preflightResult->workflowCode() );
		self::assertSame( '', $preflightResult->correlationReference() );
		self::assertSame( array(), $credentials->materialReads );

		$preview = $workflow->inspect( $status, 'stable', WorkflowProviderFixtures::preflight(), null );
		self::assertTrue( $preview->successful() );
		self::assertSame( 'workflow_unauthorised', $workflow->setup( $status, $preview->previewKey(), 'owner/example-plugin', WorkflowProviderFixtures::preflight(), 'constant' )->workflowCode() );
		self::assertSame( array(), $credentials->materialReads );
	}

	private function workflow( WorkflowCredentialStore $credentials, ?SetupRecordStore $records = null, ?D23ApplicationTransport $transport = null ): GitHubRepositoryReleaseWorkflow {
		$records   ??= new SetupRecordStore();
		$transport ??= new D23ApplicationTransport();
		$coordinator = new WorkflowApplicationCoordinator( new GitHubRepositoryClient( $transport ), new TemplatePackRepositoryClient( $transport ), new SourceReadyAssessor(), $records );
		return new GitHubRepositoryReleaseWorkflow( $credentials, $coordinator, $records );
	}

	private function record( array $overrides = array() ): array {
		return array_replace(
			array(
				'schema_version'        => 3,
				'operation'             => 'bootstrap',
				'repo_id'               => '101',
				'repository'            => 'RocketsAreNostalgic/example-plugin',
				'package_type'          => 'plugin',
				'package_identifier'    => 'example-plugin/example-plugin.php',
				'source_revision'       => 3,
				'default_branch'        => 'main',
				'base_sha'              => str_repeat( 'a', 40 ),
				'setup_branch'          => 'ran-booster/release-setup-v3-aaaaaaaaaaaa-deadbeef',
				'head_sha'              => str_repeat( 'b', 40 ),
				'pr_number'             => 42,
				'profile_id'            => 'source-ready-wordpress-plugin/3',
				'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
				'template_repo_id'      => '1322743261',
				'template_release_id'   => 41,
				'template_tag'          => 'v1.2.3',
				'template_commit'       => str_repeat( 'c', 40 ),
				'template_asset_id'     => 73,
				'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
				'template_asset_size'   => 1000,
				'template_asset_digest' => str_repeat( 'd', 64 ),
				'manifest_digest'       => str_repeat( 'e', 64 ),
				'changed_files'         => array(
					array(
						'path'   => 'version.txt',
						'status' => 'added',
						'sha'    => str_repeat( 'f', 40 ),
					),
				),
				'consumer_api'          => 3,
				'pack_version'          => '1.2.3',
				'bundle_hash'           => str_repeat( '1', 64 ),
				'changed_path_hash'     => str_repeat( '2', 64 ),
			),
			$overrides
		);
	}
}
