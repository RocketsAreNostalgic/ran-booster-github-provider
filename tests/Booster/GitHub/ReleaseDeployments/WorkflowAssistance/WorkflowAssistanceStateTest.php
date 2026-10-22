<?php


declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;

final class WorkflowAssistanceStateTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function setUp(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'] = array_fill_keys(
			array(
				WorkflowAssistanceState::SETUP_OPTION,
				'ran_booster_github_provider_release_workflow_assessment_observations',
				'ran_booster_github_provider_release_workflow_failure_history',
			),
			array( 'current' => true )
		);

		foreach (
			array(
				'ran_booster_release_deployments_setup_records',
				'ran_booster_release_deployments_assessment_observations',
				'ran_booster_release_deployments_failure_history',
			) as $legacy_option
		) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_options'][ $legacy_option ] = array( 'legacy' => true );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options']['unrelated_option'] = 'preserved';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused state-owner test requires the WordPress options-table identity.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		unset( $GLOBALS['ran_booster_release_deployments_test_transients'], $GLOBALS['ran_booster_release_deployments_test_transient_delete_callback'], $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'], $GLOBALS['ran_booster_release_deployments_test_lock_release_result'], $GLOBALS['ran_booster_github_provider_test_transient_delete_failure'], $GLOBALS['ran_booster_release_deployments_test_option_update_result'] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve PHPUnit lifecycle override names.
	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_github_provider_test_option_delete_failure'] );
	}

	public function test_durable_cleanup_removes_current_and_retired_provider_state_and_is_repeatable(): void {
		$state = new WorkflowAssistanceState();

		self::assertTrue( $state->remove_durable_state() );
		$options = $GLOBALS['ran_booster_release_deployments_test_options'];
		self::assertSame( array( 'legacy' => true ), $options['ran_booster_release_deployments_setup_records'] );
		self::assertSame(
			array( 'legacy' => true ),
			$options['ran_booster_release_deployments_assessment_observations']
		);
		self::assertSame( array( 'legacy' => true ), $options['ran_booster_release_deployments_failure_history'] );
		self::assertSame( 'preserved', $options['unrelated_option'] );
		self::assertCount( 4, $options );

		self::assertTrue( $state->remove_durable_state() );
		self::assertCount( 4, $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_failed_retired_option_deletion_is_reported_and_can_be_retried(): void {
		$option = 'ran_booster_github_provider_release_workflow_failure_history';
		$GLOBALS['ran_booster_github_provider_test_option_delete_failure'] = $option;
		$state = new WorkflowAssistanceState();
		self::assertFalse( $state->remove_durable_state() );
		self::assertArrayHasKey( $option, $GLOBALS['ran_booster_release_deployments_test_options'] );
		unset( $GLOBALS['ran_booster_github_provider_test_option_delete_failure'] );
		self::assertTrue( $state->remove_durable_state() );
		self::assertArrayNotHasKey( $option, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertSame( 'preserved', $GLOBALS['ran_booster_release_deployments_test_options']['unrelated_option'] );
	}

	public function test_lock_and_preview_names_are_provider_owned(): void {
		self::assertSame(
			'ran_booster_github_workflow_' . substr( hash( 'sha256', 'wp_options' ), 0, 32 ),
			WorkflowAssistanceState::claim_lock_name()
		);
		self::assertSame( 'ran_booster_github_provider_release_workflow_preview_', WorkflowAssistanceState::PREVIEW_PREFIX );
	}
	public function test_namespace_is_immutable_bounded_distinct_and_site_scoped(): void {
		$first  = new WorkflowAssistanceState( 'github-example' );
		$second = new WorkflowAssistanceState( 'github_example' );
		self::assertSame( 'github-example', $first->namespace() );
		self::assertTrue( ( new \ReflectionClass( $first ) )->isReadOnly() );
		self::assertSame( $first->setup_option(), ( new WorkflowAssistanceState( 'github-example' ) )->setup_option() );
		self::assertNotSame( $first->setup_option(), $second->setup_option() );
		self::assertNotSame( $first->preview_prefix(), $second->preview_prefix() );
		self::assertNotSame( $first->setup_lock_name(), $second->setup_lock_name() );
		self::assertNotSame( WorkflowAssistanceState::claim_lock_name(), $first->setup_lock_name() );
		$before                   = $first->setup_lock_name();
		$GLOBALS['wpdb']->options = 'another_site_options';
		self::assertNotSame( $before, $first->setup_lock_name() );
		$longest = new WorkflowAssistanceState( str_repeat( 'a', 32 ) );
		self::assertLessThanOrEqual( 64, strlen( $longest->setup_lock_name() ) );
		self::assertLessThanOrEqual( 172, strlen( $longest->preview_prefix() . str_repeat( 'f', 32 ) ) );
		self::assertSame( WorkflowAssistanceState::SETUP_OPTION, ( new WorkflowAssistanceState() )->setup_option() );
		self::assertSame( WorkflowAssistanceState::PREVIEW_PREFIX, ( new WorkflowAssistanceState() )->preview_prefix() );
	}

	public function test_invalid_namespaces_refuse_without_storage_or_lock_io(): void {
		$before = $GLOBALS['ran_booster_release_deployments_test_options'];
		foreach ( array( '', 'UPPER', ' leading', 'trailing ', '../example', 'example/name', '_example', '-example', '0example', 'é', "example\0", str_repeat( 'a', 33 ) ) as $invalid ) {
			try {
				new WorkflowAssistanceState( $invalid );
				self::fail( 'Invalid namespace was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Invalid GitHub workflow storage namespace.', $exception->getMessage() );
			}
		}
		self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertSame( 0, $GLOBALS['wpdb']->lock_acquisitions );
	}

	public function test_custom_durable_cleanup_preserves_bundled_retired_core_and_other_state(): void {
		$first  = new WorkflowAssistanceState( 'github-example' );
		$second = new WorkflowAssistanceState( 'other_example' );
		$this->seed_option( $first->setup_option(), array( 'custom' => true ) );
		$this->seed_option( $second->setup_option(), array( 'other' => true ) );
		$this->seed_option( 'ran_booster_provider_selection', array( 'core' => true ) );
		$before = $GLOBALS['ran_booster_release_deployments_test_options'];
		unset( $before[ $first->setup_option() ] );
		self::assertTrue( $first->remove_durable_state() );
		self::assertSame( $before, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertTrue( $first->remove_durable_state() );
	}

	public function test_preview_reads_consumption_and_cleanup_are_namespace_local(): void {
		$first   = new WorkflowAssistanceState( 'github-example' );
		$second  = new WorkflowAssistanceState( 'other_example' );
		$bundled = new WorkflowAssistanceState();
		$key     = str_repeat( 'a', 32 );
		self::assertTrue(
			$first->store_preview(
				$key,
				array(
					'repository' => 'owner/repo',
					'first'      => true,
				),
				900
			)
		);
		self::assertFalse( $second->read_preview( $key ) );
		self::assertFalse( $bundled->read_preview( $key ) );
		self::assertTrue(
			$second->store_preview(
				$key,
				array(
					'repository' => 'owner/repo',
					'second'     => true,
				),
				900
			)
		);
		self::assertTrue( $bundled->store_preview( $key, array( 'bundled' => true ), 900 ) );
		self::assertSame(
			array(
				'repository' => 'owner/repo',
				'first'      => true,
			),
			$first->read_preview( $key )
		);
		self::assertTrue( $first->consume_preview( $key ) );
		self::assertFalse( $first->read_preview( $key ) );
		self::assertSame(
			array(
				'repository' => 'owner/repo',
				'second'     => true,
			),
			$second->read_preview( $key )
		);
		self::assertTrue( $first->store_preview( $key, array( 'first' => true ), 900 ) );
		self::assertTrue( $first->remove_ephemeral_state() );
		self::assertFalse( $first->read_preview( $key ) );
		self::assertSame(
			array(
				'repository' => 'owner/repo',
				'second'     => true,
			),
			$second->read_preview( $key )
		);
		self::assertSame( array( 'bundled' => true ), $bundled->read_preview( $key ) );
		self::assertTrue( $first->remove_ephemeral_state() );
		self::assertFalse( $bundled->remove_ephemeral_state() );
	}

	public function test_invalid_preview_keys_and_ttls_refuse_before_io(): void {
		$state = new WorkflowAssistanceState( 'github-example' );
		foreach ( array( '', '../foreign', str_repeat( 'A', 32 ), str_repeat( 'a', 31 ), str_repeat( 'a', 33 ) ) as $key ) {
			self::assertFalse( $state->store_preview( $key, array(), 900 ) );
			self::assertFalse( $state->read_preview( $key ) );
			self::assertFalse( $state->consume_preview( $key ) );
		}
		self::assertFalse( $state->store_preview( str_repeat( 'a', 32 ), array(), 0 ) );
		self::assertFalse( $state->store_preview( str_repeat( 'a', 32 ), array(), 901 ) );
		self::assertSame( 0, $GLOBALS['wpdb']->lock_acquisitions );
		self::assertArrayNotHasKey( 'ran_booster_gh_wf_github-example_preview_keys', $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_unknown_preview_index_is_preserved_and_cannot_authorize_cleanup(): void {
		$state        = new WorkflowAssistanceState( 'github-example' );
		$index_option = 'ran_booster_gh_wf_github-example_preview_keys';
		foreach ( array( 'opaque', array( '../foreign' => time() + 900 ), array( str_repeat( 'a', 32 ) => '900' ), array( str_repeat( 'a', 32 ) => 0 ), array_fill_keys( array_map( static fn ( int $index ): string => md5( (string) $index ), range( 1, 101 ) ), time() + 900 ) ) as $unknown ) {
			$this->seed_option( $index_option, $unknown );
			self::assertFalse( $state->store_preview( str_repeat( 'b', 32 ), array( 'new' => true ), 900 ) );
			self::assertFalse( $state->remove_ephemeral_state() );
			self::assertSame( $unknown, $GLOBALS['ran_booster_release_deployments_test_options'][ $index_option ] );
		}
	}

	public function test_preview_cleanup_failure_retains_owned_index_for_retry(): void {
		$state        = new WorkflowAssistanceState( 'github-example' );
		$key          = str_repeat( 'a', 32 );
		$index_option = 'ran_booster_gh_wf_github-example_preview_keys';
		self::assertTrue( $state->store_preview( $key, array( 'preview' => true ), 900 ) );
		$GLOBALS['ran_booster_github_provider_test_transient_delete_failure'] = $state->preview_prefix() . $key;
		self::assertFalse( $state->remove_ephemeral_state() );
		self::assertArrayHasKey( $index_option, $GLOBALS['ran_booster_release_deployments_test_options'] );
		self::assertSame( array( 'preview' => true ), $state->read_preview( $key ) );
		unset( $GLOBALS['ran_booster_github_provider_test_transient_delete_failure'] );
		self::assertTrue( $state->remove_ephemeral_state() );
		self::assertArrayNotHasKey( $index_option, $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function test_preview_index_write_failure_does_not_create_unowned_transient(): void {
		$state = new WorkflowAssistanceState( 'github-example' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Inject the existing option-write failure used by storage contract fixtures.
		$GLOBALS['ran_booster_release_deployments_test_option_update_result'] = false;
		$key = str_repeat( 'a', 32 );
		self::assertFalse( $state->store_preview( $key, array( 'preview' => true ), 900 ) );
		self::assertFalse( $state->read_preview( $key ) );
	}

	public function test_preview_capacity_is_bounded_and_consumed_keys_are_reclaimed(): void {
		$state = new WorkflowAssistanceState( 'github-example' );
		for ( $index = 1; $index <= 100; ++$index ) {
			self::assertTrue( $state->store_preview( md5( (string) $index ), array( 'number' => $index ), 900 ) );
		}
		self::assertFalse( $state->store_preview( md5( '101' ), array( 'number' => 101 ), 900 ) );
		self::assertTrue( $state->consume_preview( md5( '1' ) ) );
		self::assertTrue( $state->store_preview( md5( '101' ), array( 'number' => 101 ), 900 ) );
		self::assertFalse( $state->read_preview( md5( '1' ) ) );
		self::assertSame( array( 'number' => 2 ), $state->read_preview( md5( '2' ) ) );
		self::assertTrue( $state->remove_ephemeral_state() );
	}

	public function test_expired_preview_index_entries_are_deleted_without_foreign_cleanup(): void {
		$state = new WorkflowAssistanceState( 'github-example' );
		$key   = str_repeat( 'a', 32 );
		self::assertTrue( $state->store_preview( $key, array( 'expired' => true ), 900 ) );
		$this->seed_option( 'ran_booster_gh_wf_github-example_preview_keys', array( $key => time() - 1 ) );
		self::assertTrue( $state->store_preview( str_repeat( 'b', 32 ), array( 'new' => true ), 900 ) );
		self::assertFalse( $state->read_preview( $key ) );
		self::assertSame( array( 'new' => true ), $state->read_preview( str_repeat( 'b', 32 ) ) );
	}

	public function test_preview_index_lock_contention_is_local_to_the_namespace(): void {
		$first            = new WorkflowAssistanceState( 'github-example' );
		$other            = new WorkflowAssistanceState( 'other_example' );
		$first_connection = $GLOBALS['wpdb'];
		$key              = str_repeat( 'a', 32 );
		$callback         = static function () use ( $first, $other, $first_connection, $key ): void {
			unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A second connection must contend only on the same namespace's preview index.
			$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
			self::assertFalse( $first->store_preview( $key, array( 'competing' => true ), 900 ) );
			self::assertTrue( $other->store_preview( $key, array( 'other' => true ), 900 ) );
			$GLOBALS['wpdb']->disconnect();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the first owner connection after the nested contender.
			$GLOBALS['wpdb'] = $first_connection;
		};
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Invoke the existing advisory-lock interleaving fixture immediately after acquisition.
		$GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] = $callback;
		self::assertTrue( $first->store_preview( $key, array( 'first' => true ), 900 ) );
		self::assertSame( array( 'first' => true ), $first->read_preview( $key ) );
		self::assertSame( array( 'other' => true ), $other->read_preview( $key ) );
	}

	public function test_connection_replacement_after_preview_lock_acquisition_refuses_mutation(): void {
		$state    = new WorkflowAssistanceState( 'github-example' );
		$original = $GLOBALS['wpdb'];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Replace the connection at the existing lock-acquired interleaving seam.
		$GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] = static function () use ( $original ): void {
			unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
			$original->disconnect();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate WordPress reconnecting while the advisory-lock request completes.
			$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		};
		$key = str_repeat( 'a', 32 );
		self::assertFalse( $state->store_preview( $key, array( 'unsafe' => true ), 900 ) );
		self::assertFalse( $state->read_preview( $key ) );
		self::assertArrayNotHasKey( 'ran_booster_gh_wf_github-example_preview_keys', $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	private function seed_option( string $key, mixed $value ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Seed the exact shared WordPress option fixture without executing persistence hooks.
		$GLOBALS['ran_booster_release_deployments_test_options'][ $key ] = $value;
	}
}
