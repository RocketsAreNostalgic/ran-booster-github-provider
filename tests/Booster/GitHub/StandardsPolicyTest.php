<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

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
		foreach ( array( '// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Process bindings still need occurrence scope.', '// phpcs:disable WordPress.PHP.YodaConditions.NotYoda -- Future-wide.', '// phpcs:ignore WordPress.WP.AlternativeFunctions -- Whole sniff.', '// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped', "/* phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Process bindings.\nphpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Piggyback. */", '// phpcs:disable RANOwnedMethods', '// phpcs:disable Generic.Files.LineLength, WordPress', '// PHPCS:IGNORE WordPress.NamingConventions', '// phpcs:set WordPress.NamingConventions.PrefixAllGlobals prefixes probe', '// phpcs:disable', '/* phpcs:disable */', '/** phpcs:disable */', '/* phpcs:ignore*/', '/** phpcs:ignore -- unwanted waiver */', "/*\n phpcs:disable\n */", '// phpcs:ignoreFile', '/* @codingStandardsIgnoreStart */', '// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals', '/* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals */' ) as $comment ) {
			self::assertNotNull( $this->blanket_annotation( '<?php ' . $comment ), $comment );
		}
		foreach ( array( '// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Foreign signature.', '$fixture = "/* phpcs:disable */";' ) as $allowed ) {
			self::assertNull( $this->blanket_annotation( '<?php ' . $allowed ), $allowed );
		}
	}

	private function blanket_annotation( string $source ): ?string {
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( preg_match( '/phpcs:(?:set\b|ignoreFile)|@codingStandards(?:Ignore|ChangeSetting)/i', $token[1] ) ) {
				return $token[1];
			}
			if ( preg_match_all( '/phpcs:(disable|ignore)\b([^\r\n]*)/i', $token[1], $directives, PREG_SET_ORDER ) ) {
				foreach ( $directives as $directive ) {
					$parts = explode( '--', trim( $directive[2], " \t*/" ), 2 );
					if ( 2 !== count( $parts ) || '' === trim( $parts[1] ) ) {
						return $token[1];
					}
					$selectors = array_map( 'trim', explode( ',', $parts[0] ) );
					if ( 'disable' === strtolower( $directive[1] ) ) {
						return $token[1];
					}
					foreach ( $selectors as $selector ) {
						if ( ! preg_match( '/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+){3}$/D', $selector ) ) {
							return $token[1];
						}
					}
				}
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
class OwnedMethodProbe { public function badOwnedMethod(): void {} }
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
		self::assertContains( 'WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid', $sources );
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

	public function test_ancestor_suppression_hides_real_diagnostic_but_is_rejected(): void {
		$diagnostic = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
		foreach ( array( 'RANOwnedMethods', 'RANOwnedMethods.NamingConventions' ) as $selector ) {
			$source = "<?php\n// phpcs:disable " . $selector . "\nnamespace RAN\\BoosterGitHubProvider\\V1; class Probe { public function badMethod() {} }";
			$report = $this->check_source( $source );
			foreach ( $report['files'] as $file ) {
				self::assertNotContains( $diagnostic, array_column( $file['messages'], 'source' ) );
			}
			self::assertNotNull( $this->blanket_annotation( $source ) );
		}
	}

	public function test_inline_prefix_configuration_is_rejected_despite_suppressing_the_checker(): void {
		foreach ( array( 'phpcs:set', '@codingStandardsChangeSetting' ) as $directive ) {
			$source = "<?php\n// " . $directive . " WordPress.NamingConventions.PrefixAllGlobals prefixes unowned\nfunction unowned_probe() {}";
			$report = $this->check_source( $source );
			foreach ( $report['files'] as $file ) {
				self::assertNotContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound', array_column( $file['messages'], 'source' ) );
			}
			self::assertNotNull( $this->blanket_annotation( $source ) );
		}
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

	public function test_owned_test_namespaces_require_the_package_prefix(): void {
		foreach ( array( 'tests/StandardsProbe.php', 'tests/Booster/GitHub/StandardsProbe.php' ) as $path ) {
			$report   = $this->check_source( '<?php namespace Tests\\Booster\\GitHub;', $path );
			$messages = array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) );
			self::assertContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound', array_column( $messages, 'source' ), $path );
		}
	}

	public function test_narrowed_real_fixtures_reject_adjacent_declarations_and_json(): void {
		foreach ( array(
			array( 'tests/analysis-coverage.php', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound', "\$unrelated_future_binding = true;\n" ),
			array( 'tests/Support/NeutralReleaseUpdaterWordPressFunctions.php', 'WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid', "function ran_booster_github_provider_unrelatedFutureFunction(): void {}\n" ),
			array( 'tests/Booster/GitHub/PublicReleaseResultMappingTest.php', 'Generic.Files.OneObjectStructurePerFile.MultipleFound', "class ExtraQualityProbe {}\n" ),
			array( 'tests/Booster/GitHub/ReleaseArtifactClaimLifetimeTest.php', 'Generic.Files.OneObjectStructurePerFile.MultipleFound', "class ExtraQualityProbe {}\n" ),
			array( 'tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/StarterSecurityCheckTest.php', 'WordPress.WP.AlternativeFunctions.json_encode_json_encode', "json_encode( array() );\n" ),
		) as list( $path, $diagnostic, $probe ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the actual narrowed fixture bytes without executing them.
			$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
			self::assertIsString( $source );
			self::assertStringContainsString( $diagnostic, $source );
			$this->check_source( $source, $path, true );
			$report   = $this->check_source( $source . "\n" . $probe, $path );
			$messages = array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) );
			self::assertContains( $diagnostic, array_column( $messages, 'source' ), $path );
		}
	}

	public function test_ruleset_weakening_is_rejected_with_real_checker_controls(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the canonical XML as inert local configuration.
		$xml = file_get_contents( dirname( __DIR__, 3 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		self::assertFalse( $this->weakened_ruleset( $xml ) );
		self::assertTrue( $this->weakened_ruleset( str_replace( 'value="ran_booster_github_provider"', 'value="rogue"', $xml ) ) );
		self::assertTrue( $this->weakened_ruleset( str_replace( '</ruleset>', '<rule ref="WordPress"><exclude name="WordPress.Security.EscapeOutput"/></rule></ruleset>', $xml ) ) );
		$diagnostic = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
		$source     = '<?php namespace RAN\\BoosterGitHubProvider\\V1; function probe( $camelCase ) { return $camelCase; }';
		$report     = $this->check_source( $source );
		self::assertContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		foreach ( array(
			'<rule ref="WordPress.NamingConventions.ValidVariableName"><severity>0</severity></rule>',
			'<rule ref="WordPress.NamingConventions.ValidVariableName"><severity>4</severity></rule>',
			'<arg name="sniffs" value="Generic.PHP.Syntax"/>',
			'<arg name="exclude" value="WordPress.NamingConventions.ValidVariableName"/>',
		) as $weakening ) {
			$mutant = str_replace( '</ruleset>', $weakening . '</ruleset>', $xml );
			self::assertTrue( $this->weakened_ruleset( $mutant ) );
			$path = sys_get_temp_dir() . '/ran-provider-rules-' . bin2hex( random_bytes( 8 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only a unique private checker configuration.
				file_put_contents( $path, $mutant );
				$report = $this->check_source( $source, 'tests/StandardsProbe.php', null, $path );
				self::assertNotContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private checker configuration.
				unlink( $path );
			}
		}
	}

	public function test_rule_path_and_command_selectors_cannot_hide_real_diagnostics(): void {
		$root = dirname( __DIR__, 3 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the canonical rules and actual fixture as inert local test inputs.
		$xml = file_get_contents( $root . '/.phpcs.xml' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preserve the real occurrence allowances while probing immediately outside them.
		$fixture = file_get_contents( $root . '/tests/foundation-contract.php' );
		self::assertIsString( $xml );
		self::assertIsString( $fixture );
		$source     = $fixture . "\n\$unrelated_future_binding = true;\n";
		$diagnostic = 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound';
		$report     = $this->check_source( $source, 'tests/foundation-contract.php' );
		self::assertContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		$cases = array();
		foreach ( array( '', ' type="absolute"', ' type="relative"' ) as $attributes ) {
			$cases[] = array( str_replace( '</ruleset>', '<rule ref="' . $diagnostic . '"><include-pattern' . $attributes . '>*/unrelated-only.php</include-pattern></rule></ruleset>', $xml ), $source, 'tests/foundation-contract.php', $diagnostic );
		}
		$cases[]           = array( str_replace( '</ruleset>', '<rule ref="' . $diagnostic . '"><exclude-pattern type="relative">*foundation-contract.php</exclude-pattern></rule></ruleset>', $xml ), $source, 'tests/foundation-contract.php', $diagnostic );
		$method_source     = '<?php namespace RAN\\BoosterGitHubProvider\\V1; class Probe extends \\stdClass { public function badMethod(): void {} }';
		$method_diagnostic = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
		$report            = $this->check_source( $method_source );
		self::assertContains( $method_diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		foreach ( array( 'phpcbf-only="true"', 'phpcs-only="false"' ) as $attribute ) {
			$cases[] = array( str_replace( '<rule ref="RANOwnedMethods"/>', '<rule ref="RANOwnedMethods" ' . $attribute . '/>', $xml ), $method_source, 'tests/StandardsProbe.php', $method_diagnostic );
		}
		foreach ( $cases as list( $mutant, $probe, $probe_path, $expected ) ) {
			self::assertTrue( $this->weakened_ruleset( $mutant ) );
			$path = sys_get_temp_dir() . '/ran-provider-scope-' . bin2hex( random_bytes( 8 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the unique private scope mutation, never the canonical ruleset.
				file_put_contents( $path, $mutant );
				$report = $this->check_source( $probe, $probe_path, null, $path );
				self::assertNotContains( $expected, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private scope mutation.
				unlink( $path );
			}
		}
	}

	public function test_base_standard_cannot_be_replaced_by_only_the_probed_rules(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the canonical XML as inert local configuration.
		$xml = file_get_contents( dirname( __DIR__, 3 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		$source     = '<?php namespace RAN\\BoosterGitHubProvider\\V1; function probe() { eval( "return true;" ); }';
		$diagnostic = 'Squiz.PHP.Eval.Discouraged';
		$report     = $this->check_source( $source );
		self::assertContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		foreach ( array( '', '<rule ref="WordPress.NamingConventions.ValidFunctionName"/><rule ref="Generic.Files.OneObjectStructurePerFile"/><rule ref="WordPress.WP.AlternativeFunctions"/>' ) as $replacement ) {
			$mutant = str_replace( '<rule ref="RANWordPressLibrary"/>', $replacement, $xml );
			self::assertTrue( $this->weakened_ruleset( $mutant ) );
			$path = sys_get_temp_dir() . '/ran-provider-base-' . bin2hex( random_bytes( 8 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only a unique private checker configuration.
				file_put_contents( $path, $mutant );
				$report = $this->check_source( $source, 'tests/StandardsProbe.php', null, $path );
				self::assertNotContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private checker configuration.
				unlink( $path );
			}
		}
	}

	public function test_compatibility_floor_cannot_hide_a_newer_php_feature(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the canonical XML as inert local configuration.
		$xml = file_get_contents( dirname( __DIR__, 3 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		$source     = '<?php namespace RAN\\BoosterGitHubProvider\\V1; class Probe { public const string VALUE = "value"; }';
		$diagnostic = 'PHPCompatibility.Classes.NewTypedConstants.Found';
		$report     = $this->check_source( $source );
		self::assertContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		$mutant = str_replace( 'name="testVersion" value="8.2-"', 'name="testVersion" value="8.5-"', $xml );
		self::assertTrue( $this->weakened_ruleset( $mutant ) );
		self::assertTrue( $this->weakened_ruleset( str_replace( 'name="minimum_wp_version" value="7.0"', 'name="minimum_wp_version" value="99.0"', $xml ) ) );
		$path = sys_get_temp_dir() . '/ran-provider-floor-' . bin2hex( random_bytes( 8 ) ) . '.xml';
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only a unique private checker configuration.
			file_put_contents( $path, $mutant );
			$report = $this->check_source( $source, 'tests/StandardsProbe.php', null, $path );
			self::assertNotContains( $diagnostic, array_column( array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) ), 'source' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private checker configuration.
			unlink( $path );
		}
	}

	private function weakened_ruleset( string $xml ): bool {
		$document = new \DOMDocument();
		if ( ! $document->loadXML( $xml, LIBXML_NONET ) ) {
			return true;
		}
		$xpath = new \DOMXPath( $document );
		if ( 1 !== $xpath->query( '/ruleset/rule[@ref="RANWordPressLibrary"]' )->length ) {
			return true;
		}
		$configurations = array();
		foreach ( $xpath->query( '//config' ) as $configuration ) {
			self::assertInstanceOf( \DOMElement::class, $configuration );
			$configurations[] = $configuration->getAttribute( 'name' ) . ':' . $configuration->getAttribute( 'value' );
		}
		sort( $configurations );
		if ( array( 'minimum_wp_version:7.0', 'testVersion:8.2-' ) !== $configurations ) {
			return true;
		}
		if ( 0 !== $xpath->query( '//rule/exclude | //rule/exclude-pattern | //rule/include-pattern | //@phpcs-only | //@phpcbf-only' )->length ) {
			return true;
		}
		foreach ( $xpath->query( '//rule/severity' ) as $severity ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM owns the textContent property.
			$value = trim( $severity->textContent );
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $value ) || (int) $value < 5 ) {
				return true;
			}
		}
		$properties = $xpath->query( '//rule/properties/property' );
		$prefixes   = $xpath->query( '//rule[@ref="WordPress.NamingConventions.PrefixAllGlobals"]/properties/property[@name="prefixes"]' );
		if ( 1 !== $properties->length || 1 !== $prefixes->length ) {
			return true;
		}
		$property = $prefixes->item( 0 );
		if ( ! $property instanceof \DOMElement || 'array' !== $property->getAttribute( 'type' ) || $property->hasAttribute( 'value' ) ) {
			return true;
		}
		$values = array();
		foreach ( $xpath->query( './element', $property ) as $element ) {
			self::assertInstanceOf( \DOMElement::class, $element );
			$values[] = $element->getAttribute( 'value' );
		}
		if ( array( 'ran_booster_github_provider', 'RAN\\BoosterGitHubProvider\\V1' ) !== $values ) {
			return true;
		}
		$arguments = array();
		foreach ( $xpath->query( '//arg' ) as $argument ) {
			self::assertInstanceOf( \DOMElement::class, $argument );
			$arguments[] = $argument->getAttribute( 'name' ) . ':' . $argument->getAttribute( 'value' );
		}
		sort( $arguments );
		return array( ':sp', 'basepath:.', 'colors:', 'extensions:php', 'parallel:4' ) !== $arguments;
	}

	private function check_source( ?string $source = null, string $path = 'tests/StandardsProbe.php', ?bool $accept_clean = false, ?string $standard = null ): array {
		$root    = dirname( __DIR__, 3 );
		$command = array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . ( $standard ?? $root . '/.phpcs.xml' ), '--report=json', '-q' );
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
		if ( null === $source || $accept_clean ) {
			self::assertSame( 0, $status, $output . $error );
		} elseif ( false === $accept_clean ) {
			self::assertNotSame( 0, $status, $error );
		}
		self::assertIsString( $output );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
