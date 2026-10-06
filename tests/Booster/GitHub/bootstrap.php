<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture variables model isolated CLI or WordPress state; declaration prefixes remain checked.


declare(strict_types=1);

$ran_provider_root = dirname( __DIR__, 3 );
$ran_booster_root  = getenv( 'RAN_BOOSTER_CORE_PATH' );
if ( ! is_string( $ran_booster_root ) || '' === trim( $ran_booster_root ) ) {
	throw new LogicException( 'RAN_BOOSTER_CORE_PATH must identify the certified Booster host for the extracted GitHub suite.' );
}
$ran_booster_root = rtrim( $ran_booster_root, '/\\' );

spl_autoload_register(
	static function ( string $class_name ) use ( $ran_provider_root, $ran_booster_root ): void {
		$prefixes = array(
			'RAN\\BoosterGitHubProvider\\V1\\Tests\\Booster\\GitHub\\' => __DIR__ . '/',
			'RAN\\BoosterGitHubProvider\\V1\\' => $ran_provider_root . '/src/',
			'RAN\\RepositoryProvider\\'        => $ran_booster_root . '/RAN/RepositoryProvider/',
			'RAN\\AddOn\\WebhookAssistance\\'  => $ran_booster_root . '/RAN/AddOn/WebhookAssistance/',
			'RAN\\Admin\\Interaction\\'        => $ran_booster_root . '/RAN/Admin/Interaction/',
			'RAN\\UpdaterSupport\\V1\\'        => $ran_provider_root . '/vendor/ran/updater-support/src/',
		);

		foreach ( $prefixes as $prefix => $directory ) {
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				continue;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = $directory . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}

			return;
		}

		if ( 'RAN\\AssistedHooks\\Plugin' === $class_name ) {
			// The retired add-on is external to Booster. Production checks it with
			// class_exists(..., false), so the certified host intentionally lacks it.
			return;
		}
		if ( 'RAN\\Provider\\ProviderCapability' === $class_name ) {
			require $ran_booster_root . '/RAN/Provider/ProviderCapability.php';
			return;
		}
		if ( 'RAN\\PackageSubdirectory' === $class_name ) {
			// RepositoryDescriptor remains a host contract and reaches the Core
			// wrapper transitively; GitHub implementation code no longer imports it.
			require $ran_booster_root . '/RAN/PackageSubdirectory.php';
			return;
		}
		if ( 'RAN\\PackageArtifactLimit' === $class_name ) {
			require $ran_booster_root . '/RAN/PackageArtifactLimit.php';
			return;
		}
		if ( 'RAN\\Deployment\\ReleaseArtifactCustodian' === $class_name ) {
			require $ran_booster_root . '/RAN/Deployment/ReleaseArtifactCustodian.php';
			return;
		}
		if ( 'RAN\\Deployment\\ReleaseArtifactCleanupFailure' === $class_name ) {
			require $ran_booster_root . '/RAN/Deployment/ReleaseArtifactCleanupFailure.php';
			return;
		}
		if ( 'RAN\\Deployment\\PreparedArtifact' === $class_name ) {
			// Exact reviewed one-shot custody handoff from the provider module to Core.
			require $ran_booster_root . '/RAN/Deployment/PreparedArtifact.php';
			return;
		}

		if ( str_starts_with( $class_name, 'RAN\\' ) ) {
			// Test-only exception text is not rendered.
			throw new LogicException( 'The bounded GitHub module suite attempted to load an unrelated Core or test class: ' . $class_name );
		}
	},
	true,
	true
);
