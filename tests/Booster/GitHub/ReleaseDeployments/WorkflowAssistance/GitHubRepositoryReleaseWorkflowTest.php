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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']    = array();
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The workflow record fixture requires the same connection-local advisory-lock double as its persistence tests.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
	}

	public function test_passive_status_uses_only_display_safe_profiles_and_exact_actions_url(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();

		$result = $workflow->status( $status );

		self::assertSame( 'https://github.com/owner/example-plugin/actions', $result->provider_workflow_url() );
		self::assertSame(
			array(
				array(
					'id'    => 'eligible',
					'label' => 'Repository access (classic)',
				),
			),
			$result->credential_choices()
		);
		self::assertSame( 1, $credentials->profile_reads );
		self::assertSame( array(), $credentials->material_reads );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_status_uses_stored_record_identity_when_record_occupies_repository(): void {
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

		self::assertSame( 'theme', $result->package_type() );
		self::assertSame( 'foreign-plugin/foreign-plugin.php', $result->package_identifier() );
		self::assertSame( 5, $result->source_revision() );
		self::assertFalse( $result->record_exact() );
		self::assertTrue( $result->record_occupied() );
	}

	public function test_status_preserves_bootstrap_operation_across_source_revisions_without_writing(): void {
		$records = new SetupRecordStore();
		self::assertTrue( $records->save( $this->record() ) );
		$before      = $GLOBALS['ran_booster_release_deployments_test_options'];
		$credentials = new WorkflowCredentialStore();
		$transport   = new D23ApplicationTransport();
		$workflow    = $this->workflow( $credentials, $records, $transport );

		foreach ( array( 3, 4 ) as $revision ) {
			$result = $workflow->status( WorkflowProviderFixtures::target( source_revision: $revision ) );
			self::assertSame( 3 === $revision, $result->record_exact() );
			self::assertTrue( $result->record_occupied() );
			self::assertSame( 'bootstrap', $result->record_operation() );
			self::assertSame( 3, $result->source_revision() );
			self::assertSame( 'plugin', $result->package_type() );
			self::assertSame( 'example-plugin/example-plugin.php', $result->package_identifier() );
		}
		self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertSame( array(), $credentials->material_reads );
		self::assertSame( array(), $transport->requests );
	}

	public function test_status_does_not_project_an_operation_from_retired_or_malformed_occupied_records(): void {
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

			self::assertTrue( $result->record_occupied() );
			self::assertFalse( $result->record_exact() );
			self::assertSame( '', $result->record_operation() );
			self::assertSame( '', $result->pull_request_url() );
			self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
			self::assertSame( array(), $credentials->material_reads );
			self::assertSame( array(), $transport->requests );
		}
	}

	public function test_invalid_outcome_does_not_read_credential_material(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();

		self::assertSame( 'workflow_invalid_request', $workflow->outcome( $status, 'eligible' )->workflow_code() );
		self::assertSame( array(), $credentials->material_reads );
		self::assertFalse( method_exists( $workflow, 'inspectUpdate' ) );
		self::assertFalse( method_exists( $workflow, 'setupUpdate' ) );
	}

	public function test_unavailable_selected_credential_refuses_current_read_operations_before_transport(): void {
		$credentials                    = new WorkflowCredentialStore();
		$credentials->eligible_material = null;
		$records                        = new SetupRecordStore();
		$transport                      = new D23ApplicationTransport();
		$status                         = WorkflowProviderFixtures::target();
		self::assertTrue( $records->save( $this->record() ) );
		$workflow = $this->workflow( $credentials, $records, $transport );

		self::assertSame( 'workflow_unauthorised', $workflow->inspect( $status, 'stable', WorkflowProviderFixtures::preflight(), 'eligible' )->workflow_code() );
		self::assertSame( 'workflow_unauthorised', $workflow->outcome( $status, 'eligible' )->workflow_code() );
		self::assertSame( array( 'eligible', 'eligible' ), $credentials->material_reads );
		self::assertSame( array(), $transport->requests );
	}


	public function test_credential_choice_label_is_utf8_safe_and_bounded_to_status_contract(): void {
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

		$choice = $this->workflow( $credentials )->status( $status )->credential_choices()[0];

		self::assertLessThanOrEqual( 255, strlen( $choice['label'] ) );
		self::assertSame( 1, preg_match( '//u', $choice['label'] ) );
		self::assertStringEndsWith( ' (classic)', $choice['label'] );
	}

	public function test_preflight_and_ineligible_saved_credential_are_rejected_before_material_read(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = WorkflowProviderFixtures::target();
		$blocked     = WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE, 'provider_unavailable' );

		$preflight_result = $workflow->inspect( $status, 'stable', $blocked, 'eligible' );
		self::assertSame( 'workflow_preflight_unavailable', $preflight_result->workflow_code() );
		self::assertSame( '', $preflight_result->correlation_reference() );
		self::assertSame( array(), $credentials->material_reads );

		$preview = $workflow->inspect( $status, 'stable', WorkflowProviderFixtures::preflight(), null );
		self::assertTrue( $preview->successful() );
		self::assertSame( 'workflow_unauthorised', $workflow->setup( $status, $preview->preview_key(), 'owner/example-plugin', WorkflowProviderFixtures::preflight(), 'constant' )->workflow_code() );
		self::assertSame( array(), $credentials->material_reads );
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
