<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;

final class WorkflowAssistanceStateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options'] = array_fill_keys(
			array(
				WorkflowAssistanceState::SETUP_OPTION,
				WorkflowAssistanceState::ASSESSMENT_OPTION,
				WorkflowAssistanceState::FAILURE_OPTION,
			),
			array( 'current' => true )
		);

		foreach (
			array(
				'ran_booster_release_deployments_setup_records',
				'ran_booster_release_deployments_assessment_observations',
				'ran_booster_release_deployments_failure_history',
			) as $legacyOption
		) {
			$GLOBALS['ran_booster_release_deployments_test_options'][ $legacyOption ] = array( 'legacy' => true );
		}
		$GLOBALS['ran_booster_release_deployments_test_options']['unrelated_option'] = 'preserved';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused state-owner test requires the WordPress options-table identity.
		$GLOBALS['wpdb'] = new \RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
	}

	public function testDurableCleanupOwnsOnlyCurrentProviderStateAndIsRepeatable(): void {
		$state = new WorkflowAssistanceState();

		self::assertTrue( $state->removeDurableState() );
		$options = $GLOBALS['ran_booster_release_deployments_test_options'];
		self::assertSame( array( 'legacy' => true ), $options['ran_booster_release_deployments_setup_records'] );
		self::assertSame(
			array( 'legacy' => true ),
			$options['ran_booster_release_deployments_assessment_observations']
		);
		self::assertSame( array( 'legacy' => true ), $options['ran_booster_release_deployments_failure_history'] );
		self::assertSame( 'preserved', $options['unrelated_option'] );
		self::assertCount( 4, $options );

		self::assertTrue( $state->removeDurableState() );
		self::assertCount( 4, $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function testLockAndPreviewNamesAreProviderOwned(): void {
		self::assertSame(
			'ran_booster_github_workflow_' . substr( hash( 'sha256', 'wp_options' ), 0, 32 ),
			WorkflowAssistanceState::claimLockName()
		);
		self::assertSame( 'ran_booster_github_provider_release_workflow_preview_', WorkflowAssistanceState::PREVIEW_PREFIX );
	}
}
