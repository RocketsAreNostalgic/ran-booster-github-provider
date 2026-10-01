<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

/** Owns the GitHub provider's local workflow-assistance storage namespace and cleanup. */
final class WorkflowAssistanceState {
	public const SETUP_OPTION      = 'ran_booster_github_provider_release_workflow_setup_records';
	public const ASSESSMENT_OPTION = 'ran_booster_github_provider_release_workflow_assessment_observations';
	public const FAILURE_OPTION    = 'ran_booster_github_provider_release_workflow_failure_history';
	public const PREVIEW_PREFIX    = 'ran_booster_github_provider_release_workflow_preview_';

	public static function claim_lock_name(): string {
		global $wpdb;

		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'unavailable';

		return 'ran_booster_github_workflow_' . substr( hash( 'sha256', $options ), 0, 32 );
	}

	/** Remove current provider-owned durable workflow-assistance state. */
	public function remove_durable_state(): bool {
		$missing = new \stdClass();
		foreach ( self::current_options() as $option ) {
			delete_option( $option );
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option, 'options' );
			}
			if ( get_option( $option, $missing ) !== $missing ) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	private static function current_options(): array {
		return array(
			self::SETUP_OPTION,
			self::ASSESSMENT_OPTION,
			self::FAILURE_OPTION,
		);
	}
}
