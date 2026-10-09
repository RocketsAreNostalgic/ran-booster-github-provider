<?php


declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\InitialReleaseBundle;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use ReflectionMethod;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi3Fixture;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\WorkflowProviderFixtures;
use function RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\wp_json_encode;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi3Fixture.php';
require_once __DIR__ . '/Support/WorkflowProviderFixtures.php';

final class WorkflowApplicationCoordinatorTest extends TestCase {
	public function test_null_preflight_is_reported_as_a_contract_availability_failure(): void {
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();

		$facade->preflight_contract_unavailable = true;
		$result                                 = $coordinator->inspect( $status, 'stable', WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE ), 'token' );
		self::assertSame( 'workflow_preflight_unavailable', $result['code'] );
		self::assertSame( 'provider_unavailable', $result['diagnostic_code'] );
	}

	public function test_release_asset_inspection_uses_the_assessment_preflight_contract(): void {
		$facade      = new D23ReleaseFacade( 'release_asset' );
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();

		$result = $coordinator->inspect( $status, 'stable', WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::RELEASE_UNAVAILABLE ), 'token' );

		self::assertSame( 'workflow_inspected', $result['code'] );
		self::assertSame( array(), $facade->calls );
	}

	public function test_returned_unavailable_preflight_uses_its_safe_reason_code(): void {
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$preflight   = WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE, 'provider_unavailable' );
		$result      = $coordinator->inspect( $status, 'stable', $preflight, 'token' );
		self::assertSame( 'workflow_preflight_unavailable', $result['code'] );
		self::assertSame( 'provider_unavailable', $result['diagnostic_code'] );
	}

	public function test_rejected_preflight_retains_the_release_preflight_diagnostic_for_inspection_and_setup(): void {
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$preflight   = WorkflowProviderFixtures::preflight( 'invalid_release_assets', 'invalid_release' );

		$inspection = $coordinator->inspect( $status, 'stable', $preflight, 'token' );
		self::assertSame( 'release_preflight', $inspection['failure_stage'] );
		self::assertSame( 'invalid_release', $inspection['diagnostic_code'] );

		$preflight = WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::READY );
		$preview   = $coordinator->inspect( $status, 'stable', $preflight, 'token' );
		$preflight = WorkflowProviderFixtures::preflight( 'invalid_release_assets', 'invalid_release' );
		$setup     = $coordinator->setup( $status, $preview['preview_key'], 'owner/example-plugin', $preflight, 'token' );
		self::assertSame( 'release_preflight', $setup['failure_stage'] );
		self::assertSame( 'invalid_release', $setup['diagnostic_code'] );
	}

	public function test_failure_stages_map_only_remote_auth_template_and_storage_faults(): void {
		$status      = WorkflowProviderFixtures::target();
		$coordinator = $this->coordinator( new D23ReleaseFacade(), new D23ApplicationTransport(), new SetupRecordStore() );
		$result      = new ReflectionMethod( $coordinator, 'result' );
		$cases       = array(
			'unauthorised'              => 'credential_authorisation',
			'preflight_unavailable'     => 'release_preflight',
			'remote_unavailable'        => 'repository_snapshot',
			'template_pack_unavailable' => 'template_pack',
			'target_changed'            => '',
			'template_superseded'       => '',
			'invalid_request'           => '',
		);

		foreach ( $cases as $code => $stage ) {
			$outcome = $result->invoke( $coordinator, $status, $code, false, '' );
			self::assertSame( $stage, $outcome['failure_stage'], $code );
		}
		self::assertSame(
			'preview_storage',
			$result->invoke( $coordinator, $status, 'remote_unavailable', false, '', 'preview_storage' )['failure_stage']
		);
		self::assertSame(
			'unexpected',
			$result->invoke( $coordinator, $status, 'remote_unavailable', false, '', 'unexpected' )['failure_stage']
		);
		self::assertSame(
			'repository_mutation',
			$result->invoke( $coordinator, $status, 'partial', false, '', 'repository_mutation' )['failure_stage']
		);
	}


	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function setUp(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_transient_delete_callback'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double exercises connection-local advisory-lock ownership.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this exact name.
	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
	}

	public function test_complete_api_three_bootstrap_uses_exact_preview_git_objects_readback_and_schema_three(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'secret-token' );

		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertTrue( $inspect['successful'] );
		$preview = $coordinator->preview( $inspect['preview_key'], $status );
		self::assertNotNull( $preview );
		self::assertSame( 3, $preview['schema_version'] );
		self::assertSame( 'source-ready-wordpress-plugin/3', $preview['profile_id'] );
		self::assertSame( 19, count( $preview ) );
		self::assertCount( 5, array_filter( $preview['changes'], static fn ( array $change ): bool => in_array( $change['path'], array( '.github/workflows/release-please.yml', 'release-please-config.json', 'scripts/build-release.sh', 'scripts/verify-release.sh', '.github/workflows/quality.yml' ), true ) ) );
		self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $preview ) );

		$setup = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'secret-token' );
		self::assertSame( 'workflow_setup_open', $setup['code'] );
		self::assertTrue( $setup['successful'] );
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 3, $record['schema_version'] );
		self::assertSame( 3, $record['consumer_api'] );
		self::assertSame( 'bootstrap', $record['operation'] );
		self::assertSame( 28, count( $record ) );
		self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		self::assertSame( 'workflow_pr_open', $coordinator->outcome( $status, 'secret-token' )['code'] );
		$transport->merge_pull();
		self::assertSame( 'workflow_pr_merged', $coordinator->outcome( $status, 'secret-token' )['code'] );
		self::assertGreaterThanOrEqual( 5, $transport->write_counts['blob'] );
		self::assertSame( 1, $transport->write_counts['tree'] );
		self::assertSame( 1, $transport->write_counts['commit'] );
		self::assertSame( 1, $transport->write_counts['ref'] );
		self::assertSame( 1, $transport->write_counts['pull'] );
		self::assertNotContains( 'PATCH', array_column( $transport->requests, 'method' ) );
		self::assertNotContains( 'DELETE', array_column( $transport->requests, 'method' ) );
		self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $GLOBALS['ran_booster_release_deployments_test_options'] ) );
		foreach ( $transport->requests as $request ) {
			if ( str_contains( (string) ( $request['args']['headers']['Authorization'] ?? '' ), 'secret-token' ) ) {
				continue;
			}
			self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $request ) );
		}
	}

	public function test_octet_stream_pack_survives_preview_exact_refetch_and_setup(): void {
		$transport   = new D23ApplicationTransport( asset_content_type: 'application/octet-stream' );
		$coordinator = $this->coordinator( new D23ReleaseFacade(), $transport, new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_inspected', $inspect['code'] );
		$preview = $coordinator->preview( $inspect['preview_key'], $status );
		self::assertNotNull( $preview );
		self::assertSame( 'application/octet-stream', $preview['template_identity']['asset_content_type'] );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
	}

	public function test_existing_starter_does_not_grant_adoption_or_write_authority(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$status      = WorkflowProviderFixtures::target();
		$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$preview     = $established->inspect( $status, 'stable', $this->ready_preflight(), 'selected-token' );
		self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'selected-token' )['code'] );
		$transport->merge_pull();
		$writes = $transport->write_counts;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();

		$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->ready_preflight(), 'selected-token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame( $writes, $transport->write_counts );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_incomplete_starter_still_requires_manual_integration(): void {
		foreach ( array( '.github/workflows/quality.yml', '.github/workflows/release-please.yml', '.ran-booster-release-starter.json', '.release-please-manifest.json', 'release-please-config.json', 'version.txt', 'release-contents.txt', 'scripts/build-release.sh', 'scripts/verify-release.sh', 'RELEASE-STARTER.md' ) as $missing_path ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options'] = array();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$status      = WorkflowProviderFixtures::target();
			$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$preview     = $established->inspect( $status, 'stable', $this->ready_preflight(), 'selected-token' );
			self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'selected-token' )['code'], $missing_path );
			$transport->merge_pull();
			$transport->remove_default_document( $missing_path );
			$writes = $transport->write_counts;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options'] = array();

			$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->ready_preflight(), 'selected-token' );

			self::assertSame( 'workflow_release_automation_conflict', $result['code'], $missing_path );
			self::assertFalse( $result['successful'], $missing_path );
			self::assertSame( $writes, $transport->write_counts, $missing_path );
			self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'], $missing_path );
		}
	}


	public function test_inspect_refuses_an_existing_managed_setup_when_the_package_header_is_missing(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$status      = WorkflowProviderFixtures::target();
		$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$preview     = $established->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
		$transport->merge_pull();
		$transport->remove_default_document( 'example-plugin.php' );
		$writes = $transport->write_counts;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();

		$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->ready_preflight(), 'token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame( $writes, $transport->write_counts );
	}

	public function test_inspect_rejects_additional_release_automation_beside_an_exact_canonical_setup(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$status      = WorkflowProviderFixtures::target();
		$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$preview     = $established->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
		$transport->merge_pull();
		$transport->mutate_default_document( '.github/workflows/publish-release.yml', "steps:\n  - uses: softprops/action-gh-release@v2\n" );
		$writes = $transport->write_counts;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();

		$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->ready_preflight(), 'token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'repository_snapshot', $result['failure_stage'] );
		self::assertSame( 'release_automation_detected', $result['diagnostic_code'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame( $writes, $transport->write_counts );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_healthy_published_release_can_inspect_preview_and_open_an_exact_setup_draft(): void {
		$transport              = new D23ApplicationTransport();
		$facade                 = new D23ReleaseFacade( 'release_asset' );
		$facade->preflight_code = RepositoryReleaseWorkflowPreflight::READY;
		$records                = new SetupRecordStore();
		$coordinator            = $this->coordinator( $facade, $transport, $records );
		$status                 = WorkflowProviderFixtures::target();

		$inspect = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertNotNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		self::assertSame(
			'workflow_setup_open',
			$coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code']
		);
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 17, $record['pr_number'] );
	}


	public function test_uses_the_operation_token_for_every_template_pack_read_during_bootstrap(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();

		$inspect = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'operation-token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'operation-token' )['code'] );
		$transport->merge_pull();

		$template_requests = array_filter(
			$transport->requests,
			static fn ( array $request ): bool => str_contains( $request['url'], '/ran-booster-release-bootstrap-templates' )
		);
		self::assertNotEmpty( $template_requests );
		foreach ( $template_requests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
	}

	public function test_setup_record_follows_exact_package_across_monotonic_core_source_revisions(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
		$transport->merge_pull();

		$published = $this->status_at_revision( $status, 4 );
		self::assertSame( 'workflow_pr_merged', $coordinator->outcome( $published, 'token' )['code'] );
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 4, $record['source_revision'] );

		$older = $this->status_at_revision( $status, 3 );
		self::assertSame( 'workflow_invalid_request', $coordinator->outcome( $older, 'token' )['code'] );
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 4, $record['source_revision'] );
	}

	public function test_theme_bootstrap_uses_the_theme_profile_and_complete_atomic_bundle(): void {
		$transport   = new D23ApplicationTransport( false, 'theme' );
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target( 'theme', 'example-theme' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'theme-token' );
		$preview     = $coordinator->preview( $inspect['preview_key'], $status );

		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertNotNull( $preview );
		self::assertSame( 'source-ready-wordpress-theme/3', $preview['profile_id'] );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'theme-token' )['code'] );
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 'theme', $record['package_type'] );
		self::assertGreaterThanOrEqual( 5, $transport->write_counts['blob'] );
	}

	public function test_competing_release_automation_refuses_inspection_before_any_remote_mutation(): void {
		$transport = new D23ApplicationTransport();
		$transport->mutate_default_document(
			'.github/workflows/publish.yml',
			"name: Publish\njobs:\n  release:\n    steps:\n      - uses: softprops/action-gh-release@v2\n"
		);
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$result      = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'repository_snapshot', $result['failure_stage'] );
		self::assertSame( 'release_automation_detected', $result['diagnostic_code'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame(
			array(
				'blob'   => 0,
				'tree'   => 0,
				'commit' => 0,
				'ref'    => 0,
				'pull'   => 0,
			),
			$transport->write_counts
		);
		self::assertSame( array(), array_values( array_intersect( array( 'POST', 'PATCH', 'DELETE' ), array_column( $transport->requests, 'method' ) ) ) );
	}

	public function test_outcome_keeps_closed_unmerged_and_merged_drift_distinct(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );

		$transport->close_pull();
		self::assertSame( 'workflow_pr_closed', $coordinator->outcome( $status, 'token' )['code'] );
		$transport->reopen_pull();
		$transport->drift_pull_base();
		self::assertSame( 'workflow_target_changed', $coordinator->outcome( $status, 'token' )['code'] );
	}

	public function test_merged_outcome_does_not_manage_later_maintainer_edits(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
		$transport->merge_pull();
		$transport->mutate_default_document( 'scripts/verify-release.sh', "#!/bin/sh\nprintf hostile\n" );

		self::assertSame( 'workflow_pr_merged', $coordinator->outcome( $status, 'token' )['code'] );
	}


	public function test_fresh_ready_preflight_can_continue_after_a_rejected_confirmation(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		$writes      = $transport->write_counts;
		self::assertSame( 'workflow_invalid_request', $coordinator->setup( $status, $inspect['preview_key'], 'owner/wrong', $this->ready_preflight(), 'token' )['code'] );
		self::assertSame( $writes, $transport->write_counts );
		$facade->preflight_code = RepositoryReleaseWorkflowPreflight::READY;
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' )['code'] );
		self::assertGreaterThan( $writes['pull'], $transport->write_counts['pull'] );
	}

	public function test_preview_rejects_every_scalar_identity_kind_and_change_drift_without_writes(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		$transient   = 'ran_booster_github_provider_release_workflow_preview_' . $inspect['preview_key'];
		$valid       = $GLOBALS['ran_booster_release_deployments_test_transients'][ $transient ];
		$cases       = array();
		foreach ( array(
			'user_id'           => '1',
			'revision'          => '3',
			'repo_id'           => 'zero',
			'repository'        => '../bad',
			'default_branch'    => 'bad branch',
			'base_sha'          => 'HEAD',
			'profile_id'        => 'source-ready-wordpress-plugin/1',
			'pack_version'      => 'next',
			'manifest_hash'     => 'bad',
			'bundle_hash'       => 'bad',
			'changed_path_hash' => 'bad',
			'allowlist_hash'    => 'bad',
		) as $field => $value ) {
			$case           = $valid;
			$case[ $field ] = $value;
			$cases[]        = $case;
		}
		$case                                        = $valid;
		$case['old_template_identity']               = array( 'asset_id' => 1 );
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['template_identity']['repository_id']  = '1';
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['template_identity']['release_target'] = str_repeat( 'f', 40 );
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['template_identity']['release_draft']  = true;
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['template_identity']['asset_size']     = 2097153;
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes'][0]['path']                  = '../unsafe';
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes'][1]                          = $case['changes'][0];
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes']                             = array_reverse( $case['changes'] );
		$cases[]                                     = $case;
		foreach ( $cases as $case ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_transients'][ $transient ] = $case;
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		}
		self::assertSame(
			array(
				'blob'   => 0,
				'tree'   => 0,
				'commit' => 0,
				'ref'    => 0,
				'pull'   => 0,
			),
			$transport->write_counts
		);
	}

	public function test_lost_ref_and_pull_acknowledgements_recover_by_exact_readback_without_duplicate_writes(): void {
		$transport   = new D23ApplicationTransport( true );
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'lost-ack-token' );
		$result      = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'lost-ack-token' );
		self::assertSame( 'workflow_setup_recovered', $result['code'] );
		self::assertSame( 1, $transport->write_counts['ref'] );
		self::assertSame( 1, $transport->write_counts['pull'] );
		self::assertNotNull( $records->find( '101' ) );
	}

	public function test_new_draft_setup_reports_partial_when_its_claim_release_fails(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		$result = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );

		self::assertSame( 'workflow_partial', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'local_persistence', $result['failure_stage'] );
		self::assertNotNull( $records->find( '101' ) );
		self::assertSame( 1, $transport->write_counts['pull'] );
	}

	public function test_recovered_draft_setup_reports_partial_when_its_claim_release_fails(): void {
		$transport   = new D23ApplicationTransport( true );
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		$result = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );

		self::assertSame( 'workflow_partial', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'local_persistence', $result['failure_stage'] );
		self::assertNotNull( $records->find( '101' ) );
		self::assertSame( 1, $transport->write_counts['pull'] );
	}

	public function test_closed_wrong_base_and_duplicate_deterministic_pulls_stop_before_object_writes(): void {
		foreach ( array( 'closed', 'wrong_base', 'duplicate' ) as $scenario ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options'] = array();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport = new D23ApplicationTransport();
			$transport->seed_pull_scenario( $scenario );
			$facade      = new D23ReleaseFacade();
			$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$status      = WorkflowProviderFixtures::target();
			$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
			$result      = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );
			self::assertSame( 'workflow_invalid_response', $result['code'], $scenario );
			self::assertSame(
				array(
					'blob'   => 0,
					'tree'   => 0,
					'commit' => 0,
					'ref'    => 0,
					'pull'   => 0,
				),
				$transport->write_counts,
				$scenario
			);
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ), $scenario );
		}
	}

	public function test_uncertain_blob_tree_and_commit_writes_consume_preview_and_cannot_replay(): void {
		foreach ( array( 'blob', 'tree', 'commit' ) as $operation ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options'] = array();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport = new D23ApplicationTransport();
			$transport->fail_write_acknowledgement( $operation );
			$facade      = new D23ReleaseFacade();
			$records     = new SetupRecordStore();
			$coordinator = $this->coordinator( $facade, $transport, $records );
			$status      = WorkflowProviderFixtures::target();
			$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
			$first       = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );
			self::assertSame( 'workflow_partial', $first['code'], $operation );
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ), $operation );
			$counts = $transport->write_counts;
			$retry  = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );
			self::assertSame( 'workflow_invalid_request', $retry['code'], $operation );
			self::assertSame( $counts, $transport->write_counts, $operation );
			$claim = $records->claim( '101', 'plugin', 'example-plugin/example-plugin.php', 3 );
			self::assertNotNull( $claim, $operation );
			self::assertTrue( $records->release_claim( '101', $claim ), $operation );
		}
	}

	public function test_claim_is_released_when_setup_throws_after_admission(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'token' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_transient_delete_callback'] = static function (): void {
			throw new \RuntimeException( 'expected test failure' );
		};

		try {
			$coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->ready_preflight(), 'token' );
			self::fail( 'Expected the test transient deletion to throw.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'expected test failure', $exception->getMessage() );
		}

		$claim = $records->claim( '101', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		self::assertTrue( $records->release_claim( '101', $claim ) );
	}


	public function test_any_existing_setup_row_blocks_fresh_inspection_without_remote_access(): void {
		foreach ( array(
			'legacy'    => array(
				'repo_id'            => '101',
				'repository'         => 'owner/example-plugin',
				'package_type'       => 'plugin',
				'package_identifier' => 'example-plugin/example-plugin.php',
				'source_revision'    => 3,
				'default_branch'     => 'main',
				'setup_branch'       => 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef',
				'head_sha'           => str_repeat( 'b', 40 ),
				'pr_number'          => 17,
			),
			'unknown'   => array( 'future_schema' => 3 ),
			'non_array' => 'occupied',
		) as $name => $existing ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] = array( '101' => $existing );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			$before      = serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] );
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$status      = WorkflowProviderFixtures::target();

			self::assertSame( 'workflow_invalid_request', $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'request-only-token' )['code'], $name );
			self::assertSame( array(), $transport->requests, $name );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] ), $name );
		}
	}

	public function test_concurrent_setup_loses_the_atomic_claim_before_it_can_start_provider_writes(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = WorkflowProviderFixtures::target();
		$preflight   = $this->ready_preflight();
		$inspect     = $coordinator->inspect( $status, 'stable', $preflight, 'token' );
		$competing   = null;
		$connection  = $GLOBALS['wpdb'];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] = static function () use ( &$competing, $coordinator, $status, $inspect, $transport, $connection, $preflight ): void {
			unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The callback models a competing request with a distinct database connection.
			$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
			$competing       = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $preflight, 'token' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the request connection after the competing setup attempt.
			$GLOBALS['wpdb'] = $connection;
			self::assertSame(
				array(
					'blob'   => 0,
					'tree'   => 0,
					'commit' => 0,
					'ref'    => 0,
					'pull'   => 0,
				),
				$transport->write_counts
			);
		};

		$winner = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $preflight, 'token' );

		self::assertNotNull( $competing );
		self::assertSame( 'workflow_invalid_request', $competing['code'] );
		self::assertSame( 'workflow_setup_open', $winner['code'] );
		self::assertNotNull( $records->find( '101' ) );
	}

	public function test_does_not_adopt_standalone_beta_eight_preview_keys(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = WorkflowProviderFixtures::target();
		$inspect     = $coordinator->inspect( $status, 'stable', $this->ready_preflight(), 'request-only-token' );
		$key         = $inspect['preview_key'];
		$new_key     = 'ran_booster_github_provider_release_workflow_preview_' . $key;
		$preview     = $GLOBALS['ran_booster_release_deployments_test_transients'][ $new_key ];

		unset( $GLOBALS['ran_booster_release_deployments_test_transients'][ $new_key ] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_transients'][ 'ran_booster_release_workflow_preview_' . $key ] = $preview;

		self::assertNull( $coordinator->preview( $key, $status ) );
		self::assertArrayHasKey( 'ran_booster_release_workflow_preview_' . $key, $GLOBALS['ran_booster_release_deployments_test_transients'] );
	}

	private function coordinator( D23ReleaseFacade $facade, D23ApplicationTransport $transport, SetupRecordStore $records ): WorkflowApplicationCoordinator {
		unset( $facade );
		return new WorkflowApplicationCoordinator( new GitHubRepositoryClient( $transport ), new TemplatePackRepositoryClient( $transport ), new SourceReadyAssessor(), $records );
	}

	private function ready_preflight(): RepositoryReleaseWorkflowPreflight {
		return WorkflowProviderFixtures::preflight( RepositoryReleaseWorkflowPreflight::RELEASE_UNAVAILABLE );
	}

	private function status_at_revision( RepositoryReleaseWorkflowTarget $status, int $revision ): RepositoryReleaseWorkflowTarget {
		return WorkflowProviderFixtures::target( $status->type(), $status->identifier(), $revision );
	}
}
