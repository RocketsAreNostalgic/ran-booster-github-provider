<?php


declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

require_once dirname( __DIR__, 4 ) . '/Support/workflow-cache.php';

final class SetupClaimDatabase {
	public string $options        = 'wp_options';
	public string $last_error     = '';
	public int $lock_acquisitions = 0;
	private string $connection_id;
	private static int $next_server_id = 100;
	private int $server_id;
	/** @var array<string,array{0:string,1:array<array-key,mixed>}> */
	private array $prepared_arguments = array();

	public function __construct() {
		$this->connection_id = spl_object_hash( $this );
		$this->server_id     = ++self::$next_server_id;
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		$original = $query;
		foreach ( $arguments as $argument ) {
			$query = (string) preg_replace_callback(
				'/%[ids]/',
				static fn ( array $matches ): string => '%i' === $matches[0] ? '`' . (string) $argument . '`' : ( '%d' === $matches[0] ? (string) (int) $argument : "'" . addslashes( (string) $argument ) . "'" ),
				$query,
				1
			);
		}
		$this->prepared_arguments[ $query ] = array( $original, $arguments );
		return $query;
	}

	public function get_var( string $query ): string|null {
		$this->last_error = '';
		preg_match( "/(?:GET_LOCK|RELEASE_LOCK)\('([^']+)'/", $query, $matches );
		$key = $matches[1] ?? '';
		if ( str_contains( $query, 'GET_LOCK(' ) ) {
			++$this->lock_acquisitions;
			$owner = $GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $key ] ?? null;
			if ( null !== $owner && $owner !== $this->connection_id ) {
				return '0';
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
			$GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $key ] = $this->connection_id;
			$callback = $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] ?? null;
			if ( is_callable( $callback ) ) {
				$callback();
			}
			return str_starts_with( $query, 'SELECT IF(' ) ? (string) $this->server_id : '1';
		}
		if ( str_starts_with( $query, 'SELECT CONNECTION_ID()' ) ) {
			$arguments = $this->prepared_arguments[ $query ][1];
			return $this->owns_preview_lock( (int) $arguments[0], (string) $arguments[1] ) ? '1' : '0';
		}
		if ( str_contains( $query, 'RELEASE_LOCK(' ) ) {
			if ( str_starts_with( $query, 'SELECT IF(' ) ) {
				$arguments = $this->prepared_arguments[ $query ][1];
				if ( ! $this->owns_preview_lock( (int) $arguments[0], (string) $arguments[1] ) ) {
					return '0';
				}
			}
			if ( false === ( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] ?? true ) ) {
				$this->last_error = 'release failed';
				return null;
			}
			if ( ( $GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $key ] ?? null ) !== $this->connection_id ) {
				return '0';
			}
			unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $key ] );
			return '1';
		}
		return null;
	}


	private function owns_preview_lock( int $owner, string $name ): bool {
		return $owner === $this->server_id && ( $GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $name ] ?? null ) === $this->connection_id;
	}

	public function reconnect(): void {
		$this->disconnect();
		$this->server_id = ++self::$next_server_id;
	}

	/** WordPress query returns affected rows, including zero for a guarded no-op. */
	public function query( string $query ): int|false {
		$this->last_error = '';
		$prepared         = $this->prepared_arguments[ $query ] ?? null;
		if ( null === $prepared ) {
			return false;
		}
		$arguments = $prepared[1];
		$callback  = $GLOBALS['ran_booster_github_provider_test_index_query_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $query );
		}
		$option = (string) $arguments[1];
		if ( str_starts_with( $prepared[0], 'DELETE FROM ' ) ) {
			if ( ! $this->owns_preview_lock( (int) $arguments[3], (string) $arguments[4] ) ) {
				return 0;
			}
			if ( ( $GLOBALS['ran_booster_github_provider_test_option_delete_failure'] ?? null ) === $option ) {
				return false;
			}
			if ( ! array_key_exists( $option, $GLOBALS['ran_booster_release_deployments_test_options'] ) ) {
				return 0;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Model the exact byte comparison performed by the narrowly scoped SQL fixture.
			if ( serialize( $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] ) !== $arguments[2] ) {
				return 0;
			}
			unset( $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] );
			return 1;
		}
		if ( ! $this->owns_preview_lock( (int) $arguments[3], (string) $arguments[4] ) ) {
			return 0;
		}
		if ( false === ( $GLOBALS['ran_booster_release_deployments_test_option_update_result'] ?? true ) ) {
			return false;
		}
		$current = $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] ?? null;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exercise compare-and-swap, not an unconditional test-store assignment.
		if ( null !== $current && serialize( $current ) !== $arguments[6] ) {
			return 0;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Decode only scalar-array bytes generated by this isolated query fixture, never external input.
		$replacement = unserialize( (string) $arguments[2], array( 'allowed_classes' => false ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Model the one namespace-owned options row changed by the real conditional statement.
		$GLOBALS['ran_booster_release_deployments_test_options'][ $option ] = $replacement;
		return $replacement === $current ? 0 : 1;
	}

	public function disconnect(): void {
		foreach ( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] ?? array() as $key => $owner ) {
			if ( $owner === $this->connection_id ) {
				unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'][ $key ] );
			}
		}
	}

	public function is_lock_held(): bool {
		return in_array( $this->connection_id, $GLOBALS['ran_booster_release_deployments_test_lock_owner'] ?? array(), true );
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Supply the exact WordPress or Core capability constant consumed by the host-contract fixture.
	define( 'MINUTE_IN_SECONDS', 60 );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
$ran_booster_root = getenv( 'RAN_BOOSTER_CORE_PATH' );
if ( ! is_string( $ran_booster_root ) || '' === trim( $ran_booster_root ) ) {
	throw new \LogicException( 'RAN_BOOSTER_CORE_PATH must identify the certified Booster host for workflow-assistance tests.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
$ran_booster_root = rtrim( $ran_booster_root, '/\\' );
foreach ( array(
	'ReleaseTrackingEligibility.php',
	'ReleaseTrackingPreflight.php',
	'ReleaseTrackingResult.php',
	'ReleaseTrackingStatus.php',
	'ReleaseTrackingFacade.php',
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
) as $release_tracking_file ) {
	require_once $ran_booster_root . '/RAN/AddOn/ReleaseTracking/' . $release_tracking_file;
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
	/** @param int<1,max> $depth */
	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- This exact WordPress function and adjacent filesystem or database double form one isolated host fixture.
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Minimal test shim for WordPress's wrapper.
		return json_encode( $value, $flags, $depth );
	}
}

function template_pack_repository_actions_reset(): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
	$GLOBALS['ran_booster_template_pack_repository_actions'] = array();
}

/** @return list<array{hook:string, callback:callable, priority:int, accepted_args:int}> */
function template_pack_repository_actions( string $hook ): array {
	return array_values(
		array_filter(
			$GLOBALS['ran_booster_template_pack_repository_actions'] ?? array(),
			static fn ( array $record ): bool => $hook === $record['hook']
		)
	);
}

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_template_pack_repository_actions'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\remove_action' ) ) {
	function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['ran_booster_template_pack_repository_actions'] ?? array() as $index => $record ) {
			if ( $hook === $record['hook'] && $callback === $record['callback'] && $priority === $record['priority'] ) {
				unset( $GLOBALS['ran_booster_template_pack_repository_actions'][ $index ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_response_code' ) ) {
	/** @param array<string,mixed> $response */
	function wp_remote_retrieve_response_code( array $response ): int {
		$code = $response['response']['code'] ?? 0;
		return is_int( $code ) ? $code : 0;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_body' ) ) {
	/** @param array<string,mixed> $response */
	function wp_remote_retrieve_body( array $response ): string {
		$body = $response['body'] ?? '';
		return is_string( $body ) ? $body : '';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_header' ) ) {
	/** @param array<string,mixed> $response */
	function wp_remote_retrieve_header( array $response, string $header ): string {
		$value = $response['headers'][ $header ] ?? '';
		return is_string( $value ) ? $value : '';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_url' ) ) {
	/**
	 * @return ($component is -1 ? array<array-key,mixed>|false : ($component is PHP_URL_PORT ? int|null|false : string|null|false))
	 */
	function wp_parse_url( string $url, int $component = -1 ): array|int|string|null|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Minimal test shim for WordPress's wrapper.
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( string $option, mixed $fallback = false ): mixed {
				$value = $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] ?? $fallback;
		$callback      = $GLOBALS['ran_booster_github_provider_test_option_read_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $option );
		}
		return $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_option_updates'][] = array( $option, $value, $autoload );
		if ( false === ( $GLOBALS['ran_booster_release_deployments_test_option_update_result'] ?? true ) ) {
			return false;
		}
		$stored = $GLOBALS['ran_booster_release_deployments_test_option_override'] ?? $value;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'][ $option ] = $stored;
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\add_option' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress add_option retains its deprecated positional parameter.
	function add_option( string $option, mixed $value = '', string $deprecated = '', bool|string $autoload = true ): bool {
		if ( array_key_exists( $option, $GLOBALS['ran_booster_release_deployments_test_options'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_options'][ $option ] = $value;
		$callback = $GLOBALS['ran_booster_release_deployments_test_option_add_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $option, $value, $autoload );
		}
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ( $GLOBALS['ran_booster_github_provider_test_option_delete_failure'] ?? null ) === $option ) {
			return false;
		}
		if ( ! array_key_exists( $option, $GLOBALS['ran_booster_release_deployments_test_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$callback = $GLOBALS['ran_booster_github_provider_test_transient_set_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $key );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_transients'][ $key ] = $value;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Preserve this existing extracted host-fixture binding shared by the test setup and WordPress or Core doubles.
		$GLOBALS['ran_booster_release_deployments_test_transient_expirations'][ $key ] = $expiration;
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return $GLOBALS['ran_booster_release_deployments_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		if ( ( $GLOBALS['ran_booster_github_provider_test_transient_delete_failure'] ?? null ) === $key ) {
			return false;
		}
		$callback = $GLOBALS['ran_booster_release_deployments_test_transient_delete_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $key );
		}
		unset( $GLOBALS['ran_booster_release_deployments_test_transients'][ $key ] );
		return true;
	}
}
