<?php


declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;

final class SetupRecordStoreTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function setUp(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_update_result'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double exercises connection-local advisory-lock ownership.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
	}
	public function test_schema_three_is_exact_bounded_and_non_autoloaded(): void {
		$store  = new SetupRecordStore();
		$record = $this->record();
		self::assertTrue( $store->save( $record ) );
		self::assertSame( $record, $store->find( '123456789' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact ordered scalar-array bytes are the compatibility subject under test.
		self::assertSame( hash( 'sha256', serialize( $record ) ), hash( 'sha256', serialize( $store->find( '123456789' ) ) ) );
		self::assertFalse( $GLOBALS['ran_booster_release_deployments_test_option_updates'][0][2] );
		self::assertFalse( $store->save( $record + array( 'github_token' => 'secret' ) ) );
		self::assertFalse( $store->save( $record + array( 'booster_credential_id' => 'credential_1' ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'consumer_api' => 1 ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'setup_branch' => 'ran-booster/release-setup-v1-old' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_name' => 'attacker/templates' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_id' => '1' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_name' => 'other.zip' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_size' => 2097153 ) ) ) );
	}
	public function test_obsolete_option_namespace_is_not_read_as_current_state(): void {
		$legacy_option = 'ran_booster_release_deployments_setup_records';
		$legacy_value  = array(
			'123456789' => array(
				'legacy_sentinel' => 'opaque',
			),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact obsolete bytes must remain untouched.
		$before = serialize( $legacy_value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'][ $legacy_option ] = $legacy_value;
		$store  = new SetupRecordStore();
		$record = $this->record();

		self::assertNull( $store->find( '123456789' ) );
		self::assertFalse( $store->occupied( '123456789' ) );
		self::assertTrue( $store->save( $record ) );
		self::assertSame( $record, $store->find( '123456789' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The obsolete option must not be adopted or rewritten.
		self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options'][ $legacy_option ] ) );
	}

	public function test_source_revision_refresh_is_monotonic_and_bound_to_the_exact_package(): void {
		$store  = new SetupRecordStore();
		$record = $this->record();
		self::assertTrue( $store->save( $record ) );

		$refreshed = $store->refresh_source_revision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 );
		self::assertNotNull( $refreshed );
		self::assertSame( 4, $refreshed['source_revision'] );
		self::assertNull( $store->refresh_source_revision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertNull( $store->refresh_source_revision( '123456789', 'theme', 'example-plugin/example-plugin.php', 5 ) );
		self::assertNull( $store->refresh_source_revision( '123456789', 'plugin', 'other/example.php', 5 ) );
		$record = $store->find( '123456789' );
		self::assertNotNull( $record );
		self::assertSame( 4, $record['source_revision'] );
	}
	public function test_existing_unknown_rows_occupy_their_key_without_byte_changes(): void {
		foreach ( array(
			'legacy'    => array(
				'repo_id'        => '123456789',
				'schema_version' => 1,
			),
			'future'    => array(
				'schema_version' => 3,
				'opaque'         => "future\0bytes",
			),
			'non_array' => 'opaque-row',
			'null_row'  => null,
		) as $name => $existing ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] = array( '123456789' => $existing );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			$before = serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] );
			$store  = new SetupRecordStore();

			self::assertTrue( $store->occupied( '123456789' ), $name );
			self::assertFalse( $store->save( $this->record() ), $name );
			self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'], $name );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] ), $name );
		}
	}
	public function test_claim_is_atomic_and_release_requires_the_exact_owner(): void {
		$store = new SetupRecordStore();
		$claim = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse( $store->release_claim( '123456789', $claim . 'tampered' ) );
		self::assertTrue( $store->release_claim( '123456789', $claim ) );
		self::assertNotNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
	}
	public function test_failed_claim_release_keeps_the_current_connection_claim_for_setup_persistence(): void {
		$store      = new SetupRecordStore();
		$connection = $GLOBALS['wpdb'];
		$claim      = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		self::assertFalse( $store->release_claim( '123456789', $claim ) );

		self::assertTrue( $store->save( $this->record() ) );
		self::assertTrue( $connection->is_lock_held() );
		self::assertSame( 1, $connection->lock_acquisitions );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		self::assertTrue( $store->release_claim( '123456789', $claim ) );
	}
	public function test_connection_replacement_drops_a_stale_claim_before_setup_persistence(): void {
		$store      = new SetupRecordStore();
		$connection = $GLOBALS['wpdb'];
		$claim      = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		self::assertFalse( $store->release_claim( '123456789', $claim ) );
		$connection->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double simulates a new connection after the failed release.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();

		self::assertTrue( $store->save( $this->record() ) );
		self::assertSame( 1, $GLOBALS['wpdb']->lock_acquisitions );
	}
	public function test_one_global_claim_serializes_distinct_repository_records(): void {
		$first            = new SetupRecordStore();
		$second           = new SetupRecordStore();
		$first_connection = $GLOBALS['wpdb'];
		$claim            = $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$second_connection = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $second_connection;
		self::assertNull( $second->claim( '987654321', 'theme', 'example-theme', 2 ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection after the cross-connection assertion.
		$GLOBALS['wpdb'] = $first_connection;
		self::assertTrue( $first->save( $this->record() ) );
		self::assertTrue( $first->release_claim( '123456789', $claim ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $second_connection;
		$other_claim     = $second->claim( '987654321', 'theme', 'example-theme', 2 );
		self::assertNotNull( $other_claim );
		self::assertTrue(
			$second->save(
				array_replace(
					$this->record(),
					array(
						'repo_id'            => '987654321',
						'repository'         => 'owner/example-theme',
						'package_type'       => 'theme',
						'package_identifier' => 'example-theme',
						'source_revision'    => 2,
					)
				)
			)
		);
		self::assertTrue( $second->release_claim( '987654321', $other_claim ) );
		self::assertNotNull( $second->find( '123456789' ) );
		self::assertNotNull( $second->find( '987654321' ) );
		self::assertCount( 2, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] );
	}
	public function test_connection_close_recovers_an_abandoned_claim_without_persistent_state(): void {
		$first = new SetupRecordStore();
		self::assertNotNull( $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertTrue( $GLOBALS['wpdb']->is_lock_held() );
		$GLOBALS['wpdb']->disconnect();

		$second = new SetupRecordStore();
		$claim  = $second->claim( '987654321', 'theme', 'example-theme', 2 );
		self::assertNotNull( $claim );
		self::assertTrue( $second->release_claim( '987654321', $claim ) );
		self::assertSame(
			array(),
			array_filter(
				array_keys( $GLOBALS['ran_booster_release_deployments_test_options'] ),
				static fn ( int|string $key ): bool => is_string( $key ) && str_contains( $key, 'setup_claim' )
			)
		);
	}
	public function test_source_revision_refresh_uses_the_same_cross_connection_lock(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertTrue(
			$store->save(
				array_replace(
					$this->record(),
					array(
						'repo_id'            => '987654321',
						'repository'         => 'owner/example-theme',
						'package_type'       => 'theme',
						'package_identifier' => 'example-theme',
						'source_revision'    => 2,
					)
				)
			)
		);

		$first_connection = $GLOBALS['wpdb'];
		$claim            = $store->claim( '555555555', 'plugin', 'lock-holder/lock-holder.php', 1 );
		self::assertNotNull( $claim );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		$other           = new SetupRecordStore();
		self::assertNull( $other->refresh_source_revision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		$record = $other->find( '123456789' );
		self::assertNotNull( $record );
		self::assertSame( 3, $record['source_revision'] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection to release its lock.
		$GLOBALS['wpdb'] = $first_connection;
		self::assertTrue( $store->release_claim( '555555555', $claim ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		$refreshed       = $other->refresh_source_revision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 );
		self::assertNotNull( $refreshed );
		self::assertSame( 4, $refreshed['source_revision'] );
		self::assertNotNull( $other->find( '987654321' ) );
		self::assertCount( 2, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] );
	}
	public function test_existing_record_cannot_be_claimed_again_even_by_the_same_package(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertNull( $store->claim( '123456789', 'theme', 'example-plugin/example-plugin.php', 3 ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'other/other.php', 3 ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
	}
	public function test_save_never_transfers_a_repository_record_to_another_package(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertFalse( $store->save( array_replace( $this->record(), array( 'package_identifier' => 'other/other.php' ) ) ) );
		$record = $store->find( '123456789' );
		self::assertNotNull( $record );
		self::assertSame( 'example-plugin/example-plugin.php', $record['package_identifier'] );
	}
	public function test_schema_one_record_is_occupied_but_never_interpreted_as_current_state(): void {
		$legacy                 = array_intersect_key( $this->record(), array_flip( array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' ) ) );
		$legacy['setup_branch'] = 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records']['123456789'] = $legacy;
		$store = new SetupRecordStore();

		self::assertNull( $store->find( '123456789' ) );
		self::assertTrue( $store->occupied( '123456789' ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse(
			$store->save(
				array_replace(
					$this->record(),
					array(
						'repo_id'      => '987654321',
						'setup_branch' => 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef',
					)
				)
			)
		);
	}

	public function test_readback_and_record_cap_fail_closed(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_option_override'] = array();
		self::assertFalse( ( new SetupRecordStore() )->save( $this->record() ) );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
		$records = array();
		for ( $index = 1; $index <= 100; ++$index ) {
			$record                     = array_replace( $this->record(), array( 'repo_id' => (string) $index ) );
			$records[ (string) $index ] = $record;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] = $records;
		self::assertTrue( ( new SetupRecordStore() )->occupied( '100' ) );
		self::assertFalse( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '101' ) ) ) );
		self::assertTrue( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '100' ) ) ) );
	}
	public function test_same_repository_records_and_cache_invalidations_are_namespace_local(): void {
		$first_state  = new WorkflowAssistanceState( 'github-example' );
		$second_state = new WorkflowAssistanceState( 'other_example' );
		$first        = new SetupRecordStore( $first_state );
		$second       = new SetupRecordStore( $second_state );
		$bundled      = new SetupRecordStore();
		$record       = $this->record();
		$other_record = array_replace( $record, array( 'pr_number' => 99 ) );
		$GLOBALS['ran_booster_github_provider_test_cache_deletes'] = array();

		self::assertTrue( $first->save( $record ) );
		self::assertNull( $second->find( '123456789' ) );
		self::assertFalse( $second->occupied( '123456789' ) );
		self::assertNull( $bundled->find( '123456789' ) );
		self::assertTrue( $second->save( $other_record ) );
		self::assertSame( $record, $first->find( '123456789' ) );
		self::assertSame( $other_record, $second->find( '123456789' ) );
		self::assertSame( $first_state, $first->workflow_state() );
		self::assertNotNull( $first->refresh_source_revision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		$second_record = $second->find( '123456789' );
		self::assertNotNull( $second_record );
		self::assertSame( 3, $second_record['source_revision'] );
		self::assertContains( array( $first_state->setup_option(), 'options' ), $GLOBALS['ran_booster_github_provider_test_cache_deletes'] );
		self::assertContains( array( $second_state->setup_option(), 'options' ), $GLOBALS['ran_booster_github_provider_test_cache_deletes'] );
		self::assertNotContains( array( WorkflowAssistanceState::SETUP_OPTION, 'options' ), $GLOBALS['ran_booster_github_provider_test_cache_deletes'] );
		self::assertTrue( $first_state->remove_durable_state() );
		self::assertNull( $first->find( '123456789' ) );
		self::assertSame( $other_record, $second->find( '123456789' ) );
	}

	public function test_namespace_claims_are_independent_and_exact_connection_owned(): void {
		$first            = new SetupRecordStore( new WorkflowAssistanceState( 'github-example' ) );
		$second           = new SetupRecordStore( new WorkflowAssistanceState( 'other_example' ) );
		$same_namespace   = new SetupRecordStore( new WorkflowAssistanceState( 'github-example' ) );
		$bundled          = new SetupRecordStore();
		$first_connection = $GLOBALS['wpdb'];
		$first_claim      = $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $first_claim );
		$second_connection = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Switch connection to prove namespace isolation without same-connection advisory-lock reentrancy.
		$GLOBALS['wpdb'] = $second_connection;
		self::assertNull( $same_namespace->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse( $first->release_claim( '123456789', $first_claim ) );
		$second_claim = $second->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $second_claim );
		self::assertFalse( $second->release_claim( '123456789', $first_claim ) );
		self::assertTrue( $second->release_claim( '123456789', $second_claim ) );
		$bundled_claim = $bundled->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $bundled_claim );
		self::assertTrue( $bundled->release_claim( '123456789', $bundled_claim ) );
		self::assertTrue( $first_connection->is_lock_held() );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the owner connection; stale store authority must not reappear.
		$GLOBALS['wpdb'] = $first_connection;
		self::assertFalse( $first->release_claim( '123456789', $first_claim ) );
		$first_connection->disconnect();
		$second_connection->disconnect();
	}

	/** @return array<string,int|string|list<array<string,string>>> */
	private function record(): array {
		return array(
			'schema_version'        => 3,
			'operation'             => 'bootstrap',
			'repo_id'               => '123456789',
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
		);
	}
	/** @return array<string,int|string> */
}
