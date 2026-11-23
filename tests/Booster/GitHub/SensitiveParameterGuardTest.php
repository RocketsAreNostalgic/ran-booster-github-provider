<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\StarterSecurityCheck;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\BoosterGitHubProvider\V1\RepositoryWebhookClient;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;
use RAN\RepositoryProvider\RepositoryReference;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * A Throwable raised inside a method keeps that method's argument values in its stack
 * frames while zend.exception_ignore_args is off. Every parameter that carries a token,
 * secret or Authorization value must therefore be marked #[\SensitiveParameter],
 * including private methods.
 */
final class SensitiveParameterGuardTest extends TestCase {

	private const SECRET_PARAMETER = '/\A(?:[a-z0-9]+_)*(?:token|secret|authorization|headers)\z/';

	public function test_every_secret_carrying_parameter_is_marked_sensitive(): void {
		$missing = array();
		$checked = 0;
		foreach ( $this->source_classes() as $class_name ) {
			foreach ( ( new ReflectionClass( $class_name ) )->getMethods() as $method ) {
				if ( $method->getDeclaringClass()->getName() !== $class_name ) {
					continue;
				}
				foreach ( $method->getParameters() as $parameter ) {
					if ( 1 !== preg_match( self::SECRET_PARAMETER, $parameter->getName() ) ) {
						continue;
					}
					++$checked;
					if ( array() === $parameter->getAttributes( \SensitiveParameter::class ) ) {
						$missing[] = $class_name . '::' . $method->getName() . '($' . $parameter->getName() . ')';
					}
				}
			}
		}

		self::assertGreaterThan( 10, $checked, 'The guard must find the secret-carrying parameters it protects.' );
		self::assertSame( array(), $missing );
	}

	public function test_the_guard_recognises_unmarked_secret_parameters(): void {
		$unmarked = new ReflectionFunction( static fn ( string $token ): string => $token );
		$marked   = new ReflectionFunction( static fn ( #[\SensitiveParameter] string $token ): string => $token );

		self::assertSame( array(), $unmarked->getParameters()[0]->getAttributes( \SensitiveParameter::class ) );
		self::assertCount( 1, $marked->getParameters()[0]->getAttributes( \SensitiveParameter::class ) );
		self::assertSame( 1, preg_match( self::SECRET_PARAMETER, 'access_token' ) );
		self::assertSame( 0, preg_match( self::SECRET_PARAMETER, 'credential_id' ) );
	}

	public function test_guarded_helpers_default_send_closures_and_authorizer_protect_secrets(): void {
		$guarded_get = new ReflectionMethod( RepositoryBrowser::class, 'guarded_get' );
		self::assertCount( 1, $guarded_get->getParameters()[1]->getAttributes( \SensitiveParameter::class ) );

		$valid_token = new ReflectionMethod( GitHubRepositoryClient::class, 'valid_token' );
		self::assertCount( 1, $valid_token->getParameters()[0]->getAttributes( \SensitiveParameter::class ) );

		$webhook_request = new ReflectionMethod( RepositoryWebhookClient::class, 'request' );
		self::assertCount( 1, $webhook_request->getParameters()[2]->getAttributes( \SensitiveParameter::class ) );
		self::assertCount( 1, $webhook_request->getParameters()[3]->getAttributes( \SensitiveParameter::class ) );

		$credential_constants = new ReflectionMethod( CredentialPolicy::class, 'credential_from_constants' );
		self::assertCount( 1, $credential_constants->getParameters()[0]->getAttributes( \SensitiveParameter::class ) );

		foreach ( array(
			array( new GitHubRepositoryClient(), 2 ),
			array( new TemplatePackRepositoryClient(), 2 ),
			array( new StarterSecurityCheck(), 1 ),
		) as $entry ) {
			$send_property = new ReflectionProperty( $entry[0], 'send' );
			$send_closure  = $send_property->getValue( $entry[0] );
			self::assertInstanceOf( \Closure::class, $send_closure );
			$send_reflection = new ReflectionFunction( $send_closure );
			self::assertCount( 1, $send_reflection->getParameters()[ $entry[1] ]->getAttributes( \SensitiveParameter::class ) );
		}

		// Throwing from assert_operation inside guarded_get must redact the Authorization header in the backtrace.
		$canary          = 'ghp_sensitive_trace_canary_9876543210';
		$previous_ignore = ini_get( 'zend.exception_ignore_args' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Enable exception arguments only during this isolated test assertion to prove SensitiveParameterValue redaction.
		ini_set( 'zend.exception_ignore_args', '0' );
		try {
			$browser = new RepositoryBrowser(
				new RepositoryResolverSecretsStub( array( 'profile-1' => $canary ) ),
				static function (): void {
					throw new RuntimeException( 'operation aborted' );
				}
			);
			$caught  = null;
			try {
				$guarded_get->invoke(
					$browser,
					'https://api.github.com/repos/owner/example',
					array( 'headers' => array( 'Authorization' => 'Bearer ' . $canary ) )
				);
			} catch ( RuntimeException $exception ) {
				$caught = $exception;
			}
			self::assertInstanceOf( RuntimeException::class, $caught );
			self::assertStringNotContainsString( $canary, $caught->getTraceAsString() );
			$found_frame = false;
			foreach ( $caught->getTrace() as $frame ) {
				if ( 'guarded_get' === ( $frame['function'] ?? '' ) ) {
					$found_frame = true;
					$frame_args  = $frame['args'] ?? null;
					self::assertIsArray( $frame_args );
					self::assertArrayHasKey( 1, $frame_args );
					self::assertInstanceOf( \SensitiveParameterValue::class, $frame_args[1] );
				}
			}
			self::assertTrue( $found_frame, 'Expected guarded_get frame in exception trace.' );
		} finally {
			if ( is_string( $previous_ignore ) ) {
				// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore previous ini value after isolated test assertion.
				ini_set( 'zend.exception_ignore_args', $previous_ignore );
			}
		}

		// archive_authorizer with an active operation guard must not capture plaintext authorization in closure state.
		$registrar = new class() {
			public function with_operation_guard( callable $guard ): object {
				unset( $guard );
				return $this;
			}
		};
		$provider  = GitHubProvider::create(
			new RepositoryResolverSecretsStub( array( 'profile-1' => $canary ) ),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			null,
			static fn (): bool => true
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		$authorizer_method = new ReflectionMethod( GitHubProvider::class, 'archive_authorizer' );
		$authorizer        = $authorizer_method->invoke( $provider, new RepositoryReference( 'owner/example', '101', true, 'profile-1' ) );
		self::assertInstanceOf( \Closure::class, $authorizer );
		$captured = ( new ReflectionFunction( $authorizer ) )->getStaticVariables();
		self::assertArrayNotHasKey( 'authorization', $captured );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion over closure variables.
		self::assertStringNotContainsString( $canary, json_encode( $captured, JSON_THROW_ON_ERROR ) );
	}

	/** @return list<class-string> */
	private function source_classes(): array {
		$root    = dirname( __DIR__, 3 ) . '/src/';
		$classes = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $root ) );
			$name     = 'RAN\\BoosterGitHubProvider\\V1\\' . str_replace( '/', '\\', substr( $relative, 0, -4 ) );
			if ( class_exists( $name ) || interface_exists( $name ) || trait_exists( $name ) ) {
				$classes[] = $name;
			}
		}
		sort( $classes );

		return $classes;
	}
}
