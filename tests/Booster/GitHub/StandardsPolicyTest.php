<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;

final class StandardsPolicyTest extends TestCase {
	public function test_every_maintained_php_file_is_checked_without_blanket_suppressions(): void {
		$root     = dirname( __DIR__, 3 );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static fn ( \SplFileInfo $file ): bool => ! $file->isDir() || ! in_array( $file->getFilename(), array( '.git', 'vendor', '.phpstan', '.phpunit.cache' ), true )
			)
		);
		$expected = array();
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || ! in_array( $file->getExtension(), array( 'php', '' ), true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect maintained source comments without executing fixture PHP.
			$contents = file_get_contents( $file->getPathname() );
			self::assertIsString( $contents );
			if ( '' === $file->getExtension() && ! str_contains( substr( $contents, 0, 256 ), '<?php' ) ) {
				continue;
			}
			$expected[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			self::assertNull( $this->blanket_annotation( $contents ), $file->getPathname() );
		}
		$report = $this->check_source();
		$actual = array_keys( $report['files'] );
		sort( $expected );
		sort( $actual );
		self::assertSame( $expected, $actual, 'Actual PHPCS discovery must cover every maintained PHP file.' );
	}

	public function test_blanket_annotation_guard_covers_comment_forms_but_not_fixture_strings(): void {
		foreach ( array( '// phpcs:disable', '/* phpcs:disable */', '/** phpcs:disable */', '/* phpcs:ignore*/', '/** phpcs:ignore -- unwanted waiver */', "/*\n phpcs:disable\n */", '// phpcs:ignoreFile', '/* @codingStandardsIgnoreStart */', '// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals', '/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals */' ) as $comment ) {
			self::assertNotNull( $this->blanket_annotation( '<?php ' . $comment ), $comment );
		}
		foreach ( array( '/* phpcs:disable WordPress.PHP.YodaConditions -- Precise synthetic contract. */', '// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Foreign signature.', '$fixture = "/* phpcs:disable */";' ) as $allowed ) {
			self::assertNull( $this->blanket_annotation( '<?php ' . $allowed ), $allowed );
		}
	}

	private function blanket_annotation( string $source ): ?string {
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			// PHPCS accepts a bare directive immediately before a block-comment delimiter.
			$comment = preg_replace( '/\*\/\s*$/', '', $token[1] );
			if ( 1 === preg_match( '/phpcs:(?:disable|ignore)\b[^\r\n]*\bWordPress\.NamingConventions\.PrefixAllGlobals(?![A-Za-z0-9_.])/i', $comment ) || 1 === preg_match( '/phpcs:ignoreFile|phpcs:(?:disable|ignore)\s*(?:--|$)|@codingStandardsIgnore/i', $comment ) ) {
				return $token[1];
			}
		}
		return null;
	}

	public function test_effective_rules_reject_owned_inherited_methods_and_unused_helpers(): void {
		$source  = <<<'FIXTURE'
<?php
namespace RAN\BoosterGitHubProvider\V1;
function helper_probe( $unused, $value ) { return $value; }
interface ProbeContract {}
class InterfaceProbe implements ProbeContract {
	public function unused( $unused ) { $result = true; return $result; }
	public function before( $unused, $value ) { $result = $value; return $result; }
	public function after( $value, $unused ) { $result = $value; return $result; }
}
class StandardsProbe extends \stdClass {
	public function badMethod( $unused ) { return true; }
	private function helper( $unused, $value ) { return $value; }
	private function after( $value, $unused ) { return $value; }
	public function probe( $class ) { $camelCase = $class; return $camelCase === true; }
}
FIXTURE;
		$report  = $this->check_source( $source );
		$sources = array();
		foreach ( $report['files'] as $file ) {
			foreach ( $file['messages'] as $message ) {
				$sources[] = $message['source'];
			}
		}
		self::assertContains( 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed', $sources );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed', $sources );
		self::assertContains( 'Universal.NamingConventions.NoReservedKeywordParameterNames.classFound', $sources );
		self::assertContains( 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase', $sources );
		self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $sources );
	}

	public function test_prefix_exceptions_do_not_hide_new_global_declarations(): void {
		foreach ( array( 'tests/FuturePrefix.php', 'tests/Support/WPError.php', 'src/FuturePrefix.php', 'src/tests/FuturePrefix.php', 'src/views/FuturePrefix.php', 'future-prefix.php' ) as $path ) {
			$report   = $this->check_source( '<?php function unowned_probe() {} class UnownedProbe {} const UNOWNED_PROBE = 1; $local_value = 1;', $path );
			$messages = array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) );
			foreach ( array( 'Function', 'Class', 'Constant', 'Variable' ) as $kind ) {
				self::assertContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixed' . $kind . 'Found', array_column( $messages, 'source' ), $path );
			}
		}
	}

	private function check_source( ?string $source = null, string $path = 'tests/StandardsProbe.php' ): array {
		$root    = dirname( __DIR__, 3 );
		$command = array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/.phpcs.xml', '--report=json', '-q' );
		if ( null !== $source ) {
			$command[] = '--stdin-path=' . $root . '/' . $path;
			$command[] = '-';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Execute the locked local checker against an in-memory negative fixture.
		$process = proc_open(
			$command,
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Feed exact negative fixture bytes to the local checker.
		fwrite( $pipes[0], $source ?? '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess input pipe.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess output pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned subprocess error pipe.
		fclose( $pipes[2] );
		$status = proc_close( $process );
		if ( null === $source ) {
			self::assertSame( 0, $status, $output . $error );
		} else {
			self::assertNotSame( 0, $status, $error );
		}
		self::assertIsString( $output );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
