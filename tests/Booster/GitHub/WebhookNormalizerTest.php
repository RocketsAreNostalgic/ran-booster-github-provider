<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\BoosterGitHubProvider\V1\Tests\Booster\GitHub\Support\WebhookProfileReaderStub;

final class WebhookNormalizerTest extends TestCase {

	private const OWNER_SECRET     = 'owner-test-webhook-secret-0001';
	private const OTHER_SECRET     = 'other-test-webhook-secret';
	private const REPOSITORY       = 'RocketsAreNostalgic/ran-booster';
	private const REPOSITORY_ID    = '9223372036854775807123';
	private const COMMIT           = '0123456789abcdef0123456789abcdef01234567';
	private const ZERO_COMMIT      = '0000000000000000000000000000000000000000';
	private const DELIVERY_ID      = 'delivery-one';
	private const RETAINED_HEADERS = array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );

	public function test_valid_push_is_normalized_to_the_exact_provider_neutral_shape(): void {
		$body     = $this->encode( $this->valid_push_payload() );
		$envelope = $this->normalizer()->normalize_webhook( $this->request( $body ) );

		self::assertTrue( $envelope->has_events() );
		self::assertSame(
			array(
				'provider'               => 'gh',
				'repository'             => self::REPOSITORY,
				'provider_repository_id' => self::REPOSITORY_ID,
				'branch'                 => 'release/alpha',
				'commit'                 => self::COMMIT,
				'delivery_id'            => self::DELIVERY_ID,
			),
			$envelope->get_events()[0]->to_array()
		);
	}

	public function test_repository_ids_that_fit_native_integers_remain_opaque_strings(): void {
		$payload                     = $this->valid_push_payload();
		$payload['repository']['id'] = 123456;
		$body                        = $this->encode( $payload );
		$event                       = $this->normalizer()->normalize_webhook( $this->request( $body ) )->get_events()[0];

		self::assertSame( '123456', $event->provider_repository_id );
	}

	public function test_signed_ping_is_returned_as_a_probe(): void {
		$body     = '{"zen":"Keep it logically awesome."}';
		$request  = $this->request( $body, 'ping' );
		$envelope = $this->normalizer()->normalize_webhook( $request );

		self::assertTrue( $envelope->is_probe() );
		self::assertSame( array(), $envelope->get_events() );
	}

	public function test_signed_unrelated_event_is_ignored_without_parsing_its_body(): void {
		$body     = 'not-json-and-not-needed';
		$request  = $this->request( $body, 'issues' );
		$envelope = $this->normalizer()->normalize_webhook( $request );

		self::assertTrue( $envelope->is_ignored() );
	}

	public function test_exact_body_and_header_limits_are_accepted(): void {
		$body = str_repeat( 'a', 262144 );

		$envelope = $this->normalizer()->normalize_webhook(
			$this->request( $body, str_repeat( 'e', 64 ), str_repeat( 'd', 191 ) )
		);

		self::assertTrue( $envelope->is_ignored() );
	}

	public function test_body_above_the_limit_is_rejected_before_secrets(): void {
		$body                         = str_repeat( 'a', 262145 );
		list( $normalizer, $secrets ) = $this->counting_normalizer();

		$this->assert_rejected(
			413,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook( $this->request( $body, 'issues' ) )
		);
		self::assertSame( 0, $secrets->calls );
	}

	public function test_missing_processor_verification_is_rejected_after_bounded_headers(): void {
		$normalizer = $this->normalizer( array() );
		$request    = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'X-GitHub-Event'      => 'issues',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( '{}' ),
			),
			self::RETAINED_HEADERS
		);

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook( $request )
		);
	}

	/**
	 * @param list<string> $values
	 */
	#[DataProvider( 'malformed_signature_form_provider' )]
	public function test_malformed_signature_forms_are_rejected_before_secrets( array $values ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->counting_normalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $values,
		);

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{list<string>}>
	 */
	public static function malformed_signature_form_provider(): iterable {
		yield 'leading whitespace' => array( array( ' sha256=' . str_repeat( 'a', 64 ) ) );
		yield 'trailing whitespace' => array( array( 'sha256=' . str_repeat( 'a', 64 ) . ' ' ) );
		yield 'control byte' => array( array( 'sha256=' . str_repeat( 'a', 63 ) . "\x7F" ) );
		yield 'uppercase algorithm' => array( array( 'SHA256=' . str_repeat( 'a', 64 ) ) );
		yield 'uppercase digest' => array( array( 'sha256=' . str_repeat( 'A', 64 ) ) );
		yield 'short digest' => array( array( 'sha256=' . str_repeat( 'a', 63 ) ) );
		yield 'long digest' => array( array( 'sha256=' . str_repeat( 'a', 65 ) ) );
		yield 'repeated equivalent values' => array(
			array(
				'sha256=' . str_repeat( 'a', 64 ),
				'sha256=' . str_repeat( 'a', 64 ),
			),
		);
	}

	public function test_missing_signature_is_rejected_before_secrets(): void {
		list( $normalizer, $secrets ) = $this->counting_normalizer();

		$this->assert_rejected(
			401,
			static fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest(
					ProviderCode::parse( 'gh' ),
					'{}',
					array(
						'X-GitHub-Event'    => 'issues',
						'X-GitHub-Delivery' => self::DELIVERY_ID,
					),
					self::RETAINED_HEADERS
				)
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @param string|list<string> $value
	 */
	#[DataProvider( 'invalid_bounded_header_provider' )]
	public function test_invalid_event_and_delivery_headers_are_rejected_before_secrets(
		string $header,
		string|array $value
	): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->counting_normalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		$headers[ $header ]           = $value;

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string, string|list<string>}>
	 */
	public static function invalid_bounded_header_provider(): iterable {
		yield 'event leading whitespace' => array( 'X-GitHub-Event', ' push' );
		yield 'event trailing whitespace' => array( 'X-GitHub-Event', 'push ' );
		yield 'event tab' => array( 'X-GitHub-Event', "pu\tsh" );
		yield 'event control' => array( 'X-GitHub-Event', "pu\x7Fsh" );
		yield 'event non-ASCII' => array( 'X-GitHub-Event', "pu\xC3\xB1sh" );
		yield 'event too long' => array( 'X-GitHub-Event', str_repeat( 'e', 65 ) );
		yield 'event repeated equivalent' => array( 'X-GitHub-Event', array( 'push', 'push' ) );
		yield 'delivery leading whitespace' => array( 'X-GitHub-Delivery', ' delivery' );
		yield 'delivery trailing whitespace' => array( 'X-GitHub-Delivery', 'delivery ' );
		yield 'delivery newline' => array( 'X-GitHub-Delivery', "delivery\n" );
		yield 'delivery control' => array( 'X-GitHub-Delivery', "delivery\x7F" );
		yield 'delivery non-ASCII' => array( 'X-GitHub-Delivery', "delivery\xC3\xB1" );
		yield 'delivery too long' => array( 'X-GitHub-Delivery', str_repeat( 'd', 192 ) );
		yield 'delivery repeated equivalent' => array( 'X-GitHub-Delivery', array( 'delivery', 'delivery' ) );
	}

	#[DataProvider( 'missing_bounded_header_provider' )]
	public function test_missing_event_and_delivery_headers_are_rejected_before_secrets( string $header ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->counting_normalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		unset( $headers[ $header ] );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function missing_bounded_header_provider(): iterable {
		yield 'event missing' => array( 'X-GitHub-Event' );
		yield 'delivery missing' => array( 'X-GitHub-Delivery' );
	}

	#[DataProvider( 'aliased_header_provider' )]
	public function test_aliased_headers_are_rejected_before_secrets( string $canonical, string $alias, int $status ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->counting_normalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		$headers[ $alias ]            = $headers[ $canonical ];

		$this->assert_rejected(
			$status,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function aliased_header_provider(): iterable {
		yield 'signature alias' => array( 'X-Hub-Signature-256', 'X_Hub_Signature_256', 401 );
		yield 'event alias' => array( 'X-GitHub-Event', 'X_GitHub_Event', 400 );
		yield 'delivery alias' => array( 'X-GitHub-Delivery', 'X_GitHub_Delivery', 400 );
	}

	#[DataProvider( 'invalid_signature_provider' )]
	public function test_missing_and_invalid_signatures_are_rejected( ?string $signature ): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$headers = array(
			'X-GitHub-Event'    => 'push',
			'X-GitHub-Delivery' => self::DELIVERY_ID,
		);

		if ( null !== $signature ) {
			$headers['X-Hub-Signature-256'] = $signature;
		}

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalid_signature_provider(): iterable {
		yield 'missing' => array( null );
		yield 'wrong digest' => array( 'sha256=' . str_repeat( 'a', 64 ) );
		yield 'obsolete algorithm' => array( 'sha1=' . str_repeat( 'a', 40 ) );
		yield 'invalid encoding' => array( 'sha256=not-hex' );
	}

	public function test_push_requires_a_delivery_identifier(): void {
		$body = $this->encode( $this->valid_push_payload() );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest(
					ProviderCode::parse( 'gh' ),
					$body,
					array(
						'X-GitHub-Event'      => 'push',
						'X-Hub-Signature-256' => $this->signature( $body ),
					),
					self::RETAINED_HEADERS
				)
			)
		);
	}

	public function test_malformed_json_is_rejected_with_a_safe_message(): void {
		$secret_body                  = '{"token":"body-secret"';
		list( $normalizer, $secrets ) = $this->counting_normalizer();

		try {
			$normalizer->normalize_webhook( $this->request( $secret_body ) );
			self::fail( 'Malformed JSON should be rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( 400, $exception->get_status_code() );
				self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( 'body-secret', $exception->getMessage() );
		}

		self::assertSame( 0, $secrets->calls );
	}

	public function test_invalid_signature_on_malformed_json_is_rejected_before_json_parsing(): void {
		$body                         = '{"token":"body-secret"';
		list( $normalizer, $secrets ) = $this->counting_normalizer();
		$request                      = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array(
				'X-GitHub-Event'      => 'push',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( $body, self::OTHER_SECRET ),
			),
			self::RETAINED_HEADERS
		);

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook( $request )
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @param array<array-key,mixed> $payload
	 */
	#[DataProvider( 'malformed_push_provider' )]
	public function test_missing_or_malformed_required_push_fields_are_rejected( array $payload ): void {
		$body = $this->encode( $payload );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformed_push_provider(): iterable {
		$valid = self::static_valid_push_payload();

		$payload = $valid;
		unset( $payload['repository'] );
		yield 'missing repository' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['id'] );
		yield 'missing repository id' => array( $payload );

		$payload                     = $valid;
		$payload['repository']['id'] = 12.5;
		yield 'lossy repository id' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['full_name'] );
		yield 'missing repository name' => array( $payload );

		$payload                            = $valid;
		$payload['repository']['full_name'] = 'missing-repository-component';
		yield 'invalid repository name' => array( $payload );

		$payload = $valid;
		unset( $payload['ref'] );
		yield 'missing ref' => array( $payload );

		$payload = $valid;
		unset( $payload['after'] );
		yield 'missing commit' => array( $payload );

		$payload            = $valid;
		$payload['deleted'] = 'false';
		yield 'non-boolean deletion flag' => array( $payload );
	}

	/**
	 * @param array<array-key,mixed> $payload
	 */
	#[DataProvider( 'ignored_push_provider' )]
	public function test_non_deployable_pushes_are_ignored( array $payload ): void {
		$body     = $this->encode( $payload );
		$envelope = $this->normalizer()->normalize_webhook( $this->request( $body ) );

		self::assertTrue( $envelope->is_ignored() );
		self::assertSame( array(), $envelope->get_events() );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function ignored_push_provider(): iterable {
		$valid = self::static_valid_push_payload();

		$payload            = $valid;
		$payload['deleted'] = true;
		yield 'deleted branch' => array( $payload );

		$payload        = $valid;
		$payload['ref'] = 'refs/tags/v1.0.0';
		yield 'tag push' => array( $payload );

		$payload          = $valid;
		$payload['after'] = 'not-an-immutable-commit';
		yield 'invalid commit' => array( $payload );

		$payload          = $valid;
		$payload['after'] = self::ZERO_COMMIT;
		yield 'zero commit' => array( $payload );
	}

	#[DataProvider( 'authorized_scope_provider' )]
	public function test_matched_profiles_authorize_only_their_configured_scope( string $scope, string $target ): void {
		$body       = $this->encode( $this->valid_push_payload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		self::assertTrue( $normalizer->normalize_webhook( $this->verified_request( $body, $scope, $target ) )->has_events() );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function authorized_scope_provider(): iterable {
		yield 'owner case insensitive' => array( 'owner', 'rocketsarenostalgic' );
		yield 'repository case insensitive' => array( 'repository', 'rocketsarenostalgic/RAN-BOOSTER' );
	}

	#[DataProvider( 'unauthorized_scope_provider' )]
	public function test_matched_secret_outside_its_configured_scope_is_rejected( string $scope, string $target ): void {
		$body       = $this->encode( $this->valid_push_payload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook( $this->verified_request( $body, $scope, $target ) )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unauthorized_scope_provider(): iterable {
		yield 'different owner' => array( 'owner', 'ProtestsAndSuffergettes' );
		yield 'different repository' => array( 'repository', 'RocketsAreNostalgic/other-plugin' );
		yield 'removed global scope' => array( 'global', '' );
		yield 'unknown scope' => array( 'organization', 'RocketsAreNostalgic' );
	}

	public function test_a_second_request_cannot_reuse_the_first_requests_matched_profile(): void {
		$profiles   = array(
			$this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ),
			$this->profile( self::OTHER_SECRET, 'owner', 'ProtestsAndSuffergettes' ),
		);
		$normalizer = $this->normalizer( $profiles );
		$body       = $this->encode( $this->valid_push_payload() );

		self::assertTrue( $normalizer->normalize_webhook( $this->request( $body ) )->has_events() );

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				$this->verified_request( $body, 'owner', 'ProtestsAndSuffergettes', self::OTHER_SECRET )
			)
		);
	}

	public function test_webhook_readiness_reports_missing_configuration_without_reading_delivery_state(): void {
		$deliveries = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $calls = 0;

			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				++$this->calls;

				return null;
			}
		};
		$result     = $this->normalizer( array(), $deliveries )->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::NOT_CONFIGURED, $result->status );
		self::assertSame( 'gh.webhook.not_configured', $result->code );
		self::assertSame( 0, $deliveries->calls );
	}

	public function test_webhook_readiness_reports_configured_but_no_retained_delivery_evidence(): void {
		$result = $this->normalizer()->diagnose_webhook_readiness();
		$output = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.webhook.delivery_not_observed', $result->code );
		self::assertStringContainsString( 'Site-wide Push-to-Deploy check', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'GitHub ping tests', $result->remediation );
		self::assertStringNotContainsString( self::OWNER_SECRET, $output );
		self::assertStringNotContainsString( self::OTHER_SECRET, $output );
	}

	public function test_webhook_readiness_reports_latest_authenticated_matched_delivery(): void {
		$result = $this->normalizer(
			null,
			$this->delivery_reader( new AuthenticatedWebhookDeliveryEvidence( ProviderCode::parse( 'gh' ), '2026-07-26 18:30:00', true ) )
		)->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::PASSED, $result->status );
		self::assertSame( 'gh.webhook.delivery_authenticated', $result->code );
		self::assertStringContainsString( '2026-07-26 18:30:00 site time', $result->message );
		self::assertStringContainsString( 'matched at least one managed package', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'If the webhook secret or provider hook changed after this time', $result->remediation );
	}

	public function test_webhook_readiness_reports_authenticated_delivery_that_matched_no_package(): void {
		$result = $this->normalizer(
			null,
			$this->delivery_reader( new AuthenticatedWebhookDeliveryEvidence( ProviderCode::parse( 'gh' ), '2026-07-26 18:31:00', false ) )
		)->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.webhook.delivery_authenticated_unmatched', $result->code );
		self::assertStringContainsString( 'matched no managed package', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'repository identity, configured branch, and package deployment policy', strtolower( $result->remediation ) );
	}

	public function test_webhook_readiness_safely_reports_unavailable_delivery_evidence(): void {
		$deliveries = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				throw new \RuntimeException( 'delivery-evidence-canary' );
			}
		};
		$result     = $this->normalizer( null, $deliveries )->diagnose_webhook_readiness();
		$output     = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'gh.webhook.delivery_evidence_unavailable', $result->code );
		self::assertStringNotContainsString( 'delivery-evidence-canary', $output );
	}

	public function test_webhook_readiness_safely_reports_unreadable_configuration(): void {
		$result = ( new WebhookNormalizer(
			new WebhookProfileReaderStub( unreadable: true ),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		) )->diagnose_webhook_readiness();
		$output = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'gh.webhook.configuration_unavailable', $result->code );
		self::assertStringNotContainsString( 'github-webhook-secret-canary', $output );
	}

	public function test_normalizer_rejects_a_request_for_another_provider(): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$request = new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-GitHub-Event'      => 'push',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		);

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $request )
		);
	}

	/**
	 * @param list<array<string, mixed>>|null $profiles Secret profiles.
	 */
	private function normalizer(
		?array $profiles = null,
		?AuthenticatedWebhookDeliveryEvidenceReader $deliveries = null
	): WebhookNormalizer {
		return $this->counting_normalizer( $profiles, $deliveries )[0];
	}

	/**
	 * @param list<array<string, mixed>>|null $profiles Secret profiles.
	 * @return array{WebhookNormalizer, WebhookProfileReaderStub}
	 */
	private function counting_normalizer(
		?array $profiles = null,
		?AuthenticatedWebhookDeliveryEvidenceReader $deliveries = null
	): array {
		$profiles   ??= array( $this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ) );
		$deliveries ??= new EmptyAuthenticatedWebhookDeliveryEvidenceReader();

		$profile_reader = new WebhookProfileReaderStub( array() !== $profiles );

		return array( new WebhookNormalizer( $profile_reader, $deliveries ), $profile_reader );
	}

	private function delivery_reader( ?AuthenticatedWebhookDeliveryEvidence $evidence ): AuthenticatedWebhookDeliveryEvidenceReader {
		return new class( $evidence ) implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function __construct( private ?AuthenticatedWebhookDeliveryEvidence $evidence ) {
			}

			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return $this->evidence;
			}
		};
	}

	private function request(
		string $body,
		string $event = 'push',
		string $delivery_id = self::DELIVERY_ID,
		string $secret = self::OWNER_SECRET
	): WebhookRequest {
		return ( new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array(
				'X-GitHub-Event'      => $event,
				'X-GitHub-Delivery'   => $delivery_id,
				'X-Hub-Signature-256' => $this->signature( $body, $secret ),
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );
	}

	private function verified_request( string $body, string $scope, string $target, string $secret = self::OWNER_SECRET ): WebhookRequest {
		return $this->request( $body, 'push', self::DELIVERY_ID, $secret )
			->with_verification( $this->verification( $scope, $target ) );
	}

	private function verification( string $scope, string $target ): SignedWebhookVerification {
		return new SignedWebhookVerification(
			ProviderCode::parse( 'gh' ),
			array(
				array(
					'id'           => 'test-profile',
					'scope'        => $scope,
					'target'       => $target,
					'authority_id' => 'repository' === $scope && 0 === strcasecmp( $target, self::REPOSITORY )
						? self::REPOSITORY_ID
						: ( 'repository' === $scope ? 'different-repository-id' : '' ),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_push_payload(): array {
		return self::static_valid_push_payload();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function static_valid_push_payload(): array {
		return array(
			'ref'        => 'refs/heads/release/alpha',
			'after'      => self::COMMIT,
			'deleted'    => false,
			'repository' => array(
				'id'        => self::REPOSITORY_ID,
				'full_name' => self::REPOSITORY,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function profile( string $secret, string $scope, string $target ): array {
		return array(
			'id'           => 'test-profile',
			'label'        => 'Test profile',
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => 'repository' === $scope && 0 === strcasecmp( $target, self::REPOSITORY )
				? self::REPOSITORY_ID
				: '',
			'secret'       => $secret,
			'source'       => 'test',
			'immutable'    => false,
		);
	}

	private function signature( string $body, string $secret = self::OWNER_SECRET ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * @param array<string, mixed> $payload JSON payload.
	 */
	private function encode( array $payload ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded by focused unit tests.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR );

		self::assertIsString( $json );

		return $json;
	}

	/**
	 * @param callable(): WebhookEnvelope $operation Normalizer operation expected to reject.
	 */
	private function assert_rejected( int $status_code, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Webhook request should have been rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( $status_code, $exception->get_status_code() );
			self::assertNotSame( '', $exception->getMessage() );
			self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::OTHER_SECRET, $exception->getMessage() );
		}
	}
}
