<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

use Closure;
use InvalidArgumentException;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryPathInspector;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer as WebhookNormalizerContract;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryReleaseWorkflow;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RuntimeException;

final class GitHubProvider implements RepositoryProvider, RepositoryPathInspector, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizerContract, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink, RepositoryWebhookFitness, RepositoryWebhookManagement, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseNativeTargets, RepositoryReleaseWorkflowManagementV3 {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;

	private ProviderMetadata $metadata;
	private ProviderCredentialStore $credentials;
	private RepositoryBrowser $browser;
	private RepositoryWebhookClient $webhook_client;
	private WebhookNormalizer $webhooks;
	private Diagnostics $diagnostics;
	private CredentialPolicy $credential_policy;
	private GitHubRepositoryReleaseWorkflow $release_workflow;
	private object $registrar;

	/**
	 * @var (Closure(): int)|null Host-resolved archive-limit supplier.
	 */
	private ?Closure $maximum_artifact_bytes;

	/** @var array<string, GitHubReleaseNativeTarget> */
	private array $native_targets = array();

	public static function create(
		ProviderCredentialStore $credentials,
		AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
		object $registrar,
		?callable $maximum_artifact_bytes = null
	): RepositoryProvider {
		return new self(
			$credentials,
			new RepositoryBrowser( $credentials ),
			new WebhookNormalizer( $credentials, $delivery_evidence ),
			new RepositoryWebhookClient(),
			$registrar,
			$maximum_artifact_bytes
		);
	}

	private function __construct(
		ProviderCredentialStore $credentials,
		RepositoryBrowser $browser,
		WebhookNormalizer $webhooks,
		RepositoryWebhookClient $webhook_client,
		object $registrar,
		?callable $maximum_artifact_bytes = null
	) {
		$this->registrar              = $registrar;
		$this->maximum_artifact_bytes = null === $maximum_artifact_bytes ? null : Closure::fromCallable( $maximum_artifact_bytes );
		$this->credentials            = $credentials;
		$this->browser                = $browser;
		$this->webhooks               = $webhooks;
		$this->webhook_client         = $webhook_client;
		$this->diagnostics            = new Diagnostics( $browser );
		$this->credential_policy      = new CredentialPolicy();
		$workflow_records             = new SetupRecordStore();
		$this->release_workflow       = new GitHubRepositoryReleaseWorkflow(
			$credentials,
			new WorkflowApplicationCoordinator(
				new GitHubRepositoryClient(),
				new TemplatePackRepositoryClient(),
				new SourceReadyAssessor(),
				$workflow_records
			),
			$workflow_records
		);
		$this->metadata               = new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner',
			new ProviderAdminMetadata(
				array(
					new CredentialKindMetadata(
						'classic',
						'Classic personal access token',
						'Personal access token',
						'ghp_...',
						array(),
						'Classic PAT'
					),
					new CredentialKindMetadata(
						'fine-grained',
						'Fine-grained personal access token',
						'Personal access token',
						'github_pat_...',
						array(
							new CredentialFieldMetadata(
								'owner',
								'Resource owner',
								'text',
								true,
								'organization-or-user',
								'Enter the GitHub username or organization selected as the token resource owner, not an email address.'
							),
						),
						'Fine-grained PAT'
					),
				),
				array(
					new WebhookScopeMetadata(
						'owner',
						'GitHub owner',
						true,
						'Owner',
						'organization-or-user',
						'Use this secret for repositories belonging to one organization or user.',
						true
					),
					new WebhookScopeMetadata(
						'repository',
						'GitHub repository',
						true,
						'Repository',
						'organization-or-user/repository',
						'Use this secret only for one repository.'
					),
				),
				new ProviderSetupMetadata(
					'Public repositories need no token. For private repository browsing and archive reads, prefer a fine-grained personal access token: choose the resource owner, select only the repositories this site needs, and set Repository permissions → Contents to Read-only (Metadata: Read-only is automatic). A fine-grained token is limited to one user or organisation, so select the project repositories once and use its saved Booster profile for the packages that need it. Booster does not change that GitHub repository selection. Repository webhook management is different: classic tokens need admin:repo_hook, while fine-grained tokens need Repository permissions → Webhooks: Read and write. Release-workflow automation writes repository files and needs Contents: Read and write, Workflows: Read and write, and Pull requests: Read and write. Keep those elevated capabilities on a separate saved credential from ordinary read access where possible. A classic personal access token with repo scope is inherently broad and cannot be limited to selected repositories or read-only access. For organisation repositories, the token owner also needs repository access and any required SSO authorisation or organisation approval.',
					array(
						array(
							'label' => 'Create and manage GitHub personal access tokens',
							'url'   => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
						),
						array(
							'label' => 'Required fine-grained token permissions',
							'url'   => 'https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens',
						),
						array(
							'label' => 'Organisation token policies and approval',
							'url'   => 'https://docs.github.com/en/organizations/managing-programmatic-access-to-your-organization/setting-a-personal-access-token-policy-for-your-organization',
						),
					),
					'Repository Settings → Webhooks → Add webhook',
					'Just the push event',
					'https://docs.github.com/en/webhooks/using-webhooks/creating-webhooks#creating-a-repository-webhook',
					'https://docs.github.com/en/webhooks/testing-and-troubleshooting-webhooks/viewing-webhook-deliveries'
				),
				new ProviderNavigationPlacement(
					ProviderNavigationPlacement::GIT_HOST,
					100
				),
				'owner/repository'
			)
		);
	}

	public function get_metadata(): ProviderMetadata {
		return $this->metadata;
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function workflow_status( RepositoryReleaseWorkflowTarget $target ): RepositoryReleaseWorkflowStatus {
		return $this->release_workflow->status( $target );
	}

	public function workflow_preview( RepositoryReleaseWorkflowTarget $target, string $key ): ?RepositoryReleaseWorkflowPreview {
		return $this->release_workflow->preview( $target, $key );
	}

	public function workflow_inspect( RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		return $this->release_workflow->inspect( $target, $channel, $preflight, $credential_id );
	}

	public function workflow_setup( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		return $this->release_workflow->setup( $target, $key, $confirmation, $preflight, $credential_id );
	}

	public function workflow_outcome( RepositoryReleaseWorkflowTarget $target, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		return $this->release_workflow->outcome( $target, $credential_id );
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->credential_policy;
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return $this->webhooks->get_webhook_policy();
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		return $this->webhooks->diagnose_webhook_readiness();
	}

	public function validate_credential( string $credential_id ): CredentialValidationResult {
		return $this->browser->validate_credential( $credential_id );
	}

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		return $this->browser->browse( $request );
	}

	public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->browser->repository( $request->locator, $request->credential_id );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$repository = $request->repository;

		$ref             = $request->ref;
		$expected_branch = $request->expected_branch;
		$repository_id   = $repository->provider_repository_id;

		if ( null === $repository_id ) {
			throw new RuntimeException( 'The managed GitHub repository does not have a stable provider identity.', 409 );
		}

		if ( null !== $expected_branch ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
				throw new RuntimeException( 'The GitHub deployment event does not contain a valid commit.', 400 );
			}
			$ref  = strtolower( $ref );
			$head = $this->browser->branch_head(
				$repository->locator,
				$expected_branch,
				$repository_id,
				$repository->credential_id,
				$repository->private
			);

			if ( ! hash_equals( $ref, $head ) ) {
				throw new StaleDeployment( 'The GitHub deployment event is stale because the configured branch has moved.', 409 );
			}
		} else {
			$ref = $this->browser->immutable_ref(
				$repository->locator,
				$ref,
				$repository_id,
				$repository->credential_id,
				$repository->private
			);
		}

		$url = 'https://api.github.com/repos/'
			. $this->encode_repository_name( $repository->locator )
			. '/zipball/'
			. rawurlencode( $ref );

		$head_verifier = null;
		if ( null !== $expected_branch ) {
			$head_verifier = function () use ( $repository, $expected_branch, $ref ): void {
				$head = $this->browser->current_branch_head(
					$repository->locator,
					$expected_branch,
					$repository->credential_id,
					$repository->private
				);

				if ( ! hash_equals( $ref, $head ) ) {
					throw new StaleDeployment( 'The GitHub deployment event is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new AuthenticatedPreparedArchive(
			$url,
			$ref,
			$this->archive_authorizer( $repository ),
			$head_verifier
		);
	}

	public function repository_path_exists( RepositoryReference $repository, string $ref, string $path ): bool {
		return $this->browser->path_exists(
			$repository->locator,
			$ref,
			$path,
			$repository->credential_id,
			$repository->private
		);
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return $this->webhooks->normalize_webhook( $request );
	}

	public function repository_webhook_settings_url( string $locator ): string {
		return 'https://github.com/' . $this->encode_repository_name( $locator ) . '/settings/hooks';
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}\z/D', $repository->locator ) ) {
			return '';
		}

		return 'https://github.com/' . $this->encode_repository_name( $repository->locator );
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		$update_uri = $this->expected_update_uri( $repository );
		if ( '' === $update_uri || '' === $tag || strlen( $tag ) > 100 ) {
			return '';
		}

		return $update_uri . '/releases/tag/' . rawurlencode( $tag );
	}

	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
		if ( ! in_array( $package_type, array( 'plugin', 'theme' ), true )
			|| '' === $installed_identifier ) {
			return false;
		}
		$key = self::native_target_key( $package_type, $installed_identifier );

		return isset( $this->native_targets[ $key ] ) && $this->native_targets[ $key ]->status()->active;
	}

	public function create_native_target(
		string $package_type,
		RepositoryReference $repository,
		string $metadata_file,
		string $package_root,
		string $installed_identifier,
		string $channel,
		string $deployment_policy
	): RepositoryReleaseNativeTarget {
		$repository_id = $repository->provider_repository_id;
		if ( '' === $this->expected_update_uri( $repository ) || null === $repository_id ) {
			throw new RuntimeException( 'The GitHub release native target repository is invalid.' );
		}

		$target = new GitHubReleaseNativeTarget(
			$this->registrar,
			$package_type,
			$metadata_file,
			$repository->locator,
			$repository_id,
			$this->release_access_token( $repository ),
			$channel,
			$deployment_policy,
			$this->maximum_artifact_bytes
		);
		$this->native_targets[ self::native_target_key( $package_type, $installed_identifier ) ] = $target;

		return $target;
	}

	private static function native_target_key( string $package_type, string $installed_identifier ): string {
		$identity = strtolower( str_replace( '\\', '/', $installed_identifier ) );

		return $package_type . ':' . ( 'plugin' === $package_type ? ltrim( $identity, '/' ) : $identity );
	}

	public function list_release_candidates(
		string $package_type,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		try {
			if ( ! $this->ensure_direct_filesystem() ) {
				throw new RuntimeException();
			}
			$result = $this->release_source( $package_type, $repository, $channel )->list();
		} catch ( \Throwable ) {
			throw new RuntimeException( 'GitHub release candidate listing is unavailable.', 503 );
		}
		if ( $this->read_unavailable( $result, 'list' ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release candidate access is unavailable.', 502 );
		}
		if ( ! $this->success( $result, 'releases_listed', 'not_applicable' )
			|| ! is_array( $result['value']['candidates'] ?? null )
			|| ! empty( $result['value']['not_modified'] ) ) {
			throw new RuntimeException( 'GitHub returned invalid release candidates.', 502 );
		}

		$candidates = array();
		foreach ( $result['value']['candidates'] as $release ) {
			if ( ! is_array( $release )
				|| ! is_string( $release['release_identity'] ?? null )
				|| ! is_string( $release['tag'] ?? null )
				|| ! is_string( $release['version'] ?? null )
				|| ! is_bool( $release['prerelease'] ?? null )
				|| ! is_string( $release['published_at'] ?? null )
				|| ! is_array( $release['expected_asset_names'] ?? null )
				|| ! is_string( $release['details_url'] ?? null )
				|| ! hash_equals( $this->release_details_url( $repository, $release['tag'] ), $release['details_url'] ) ) {
				throw new RuntimeException( 'GitHub returned invalid release candidates.', 502 );
			}
			$candidates[] = new RepositoryReleaseCandidate(
				$release['release_identity'],
				$release['tag'],
				$release['version'],
				$release['prerelease'],
				$release['published_at'],
				$release['expected_asset_names']
			);
		}

		return new RepositoryReleaseCandidateList( $candidates );
	}

	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		if ( ! $this->bounded_opaque_value( $provider_release_id, 191 )
			|| ! $this->bounded_opaque_value( $tag, 100 ) ) {
			throw RepositoryReleaseInspectionRejected::invalid_release();
		}

		try {
			if ( ! $this->ensure_direct_filesystem() ) {
				throw new RuntimeException();
			}
			$result = $this->release_source( $package_type, $repository, $channel )->inspect( $provider_release_id, $tag );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'GitHub release inspection is unavailable.', 503 );
		}
		if ( $this->read_unavailable( $result, 'inspect' ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release inspection access is unavailable.', 502 );
		}
		if ( ! $this->success( $result, 'release_inspected', 'complete' ) || ! is_array( $result['value'] ) ) {
			if ( $this->failure( $result, 'inspect', 'invalid_release' ) ) {
				throw RepositoryReleaseInspectionRejected::invalid_release();
			}
			if ( $this->failure( $result, 'inspect', 'package_incompatible' ) ) {
				throw RepositoryReleaseInspectionRejected::incompatible();
			}
			throw new RuntimeException( 'GitHub could not inspect the selected release.', 502 );
		}
		try {
			$maximum_artifact_bytes = $this->maximum_artifact_bytes();
			$facts                  = $result['value'];
			if ( ! hash_equals( $provider_release_id, $facts['release_identity'] ?? '' )
				|| ! hash_equals( $tag, $facts['tag'] ?? '' )
				|| ! hash_equals( $package_type, $facts['target_type'] ?? '' )
				|| ! hash_equals( $channel, $facts['channel'] ?? '' )
				|| ! hash_equals( $this->expected_update_uri( $repository ), $facts['canonical_update_uri'] ?? '' )
				|| ! hash_equals( $repository->locator, $facts['repository_locator'] ?? '' )
				|| ! hash_equals( (string) $repository->provider_repository_id, $facts['repository_identity'] ?? '' )
				|| ( null !== $maximum_artifact_bytes && ( $facts['maximum_artifact_bytes'] ?? null ) !== $maximum_artifact_bytes ) ) {
				throw new RuntimeException();
			}
			return new RepositoryReleaseInspection(
				$facts['release_identity'],
				$facts['tag'],
				$facts['version'],
				$facts['commit_identity'],
				$facts['package_root'],
				$facts['main_file'],
				$facts['fingerprint']
			);
		} catch ( \Throwable ) {
			throw new RuntimeException( 'GitHub returned invalid release inspection evidence.', 502 );
		}
	}

	public function acquire_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $expected_fingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		if ( ! $this->bounded_opaque_value( $provider_release_id, 191 )
			|| ! $this->bounded_opaque_value( $tag, 100 )
			|| 1 !== preg_match( '/\Av2:[a-f0-9]{64}\z/D', $expected_fingerprint ) ) {
			throw RepositoryReleaseAcquisitionRejected::invalid_release();
		}

		try {
			if ( ! $this->ensure_direct_filesystem() ) {
				throw new RuntimeException();
			}
			$result = $this->release_source( $package_type, $repository, $channel )->acquire( $provider_release_id, $tag, $expected_fingerprint );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'GitHub release acquisition is unavailable.', 503 );
		}
		if ( is_array( $result ) && 'failed' === ( $result['cleanup_status'] ?? null ) ) {
			throw RepositoryReleaseAcquisitionRejected::cleanup_failed();
		}
		if ( $this->read_unavailable( $result, 'acquire' ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release acquisition access is unavailable.', 502 );
		}
		if ( ! $this->success( $result, 'release_acquired', 'retained' )
			|| ! is_array( $result['value'] ?? null )
			|| 2 !== count( $result['value'] )
			|| array_diff( array_keys( $result['value'] ), array( 'inspection', 'artifact' ) ) !== array()
			|| ! is_array( $result['value']['inspection'] ?? null )
			|| ! is_object( $result['value']['artifact'] ?? null ) ) {
			if ( ! $this->discard_rejected_artifact( $result ) ) {
				throw RepositoryReleaseAcquisitionRejected::cleanup_failed();
			}
			if ( $this->failure( $result, 'acquire', 'invalid_release' )
				|| $this->failure( $result, 'acquire', 'package_incompatible' )
				|| $this->failure( $result, 'acquire', 'release_changed' ) ) {
				throw RepositoryReleaseAcquisitionRejected::invalid_release();
			}
			throw new RuntimeException( 'GitHub could not acquire the selected release.', 502 );
		}
		try {
			$maximum_artifact_bytes = $this->maximum_artifact_bytes();
			$facts                  = $result['value']['inspection'];
			if ( ! hash_equals( $provider_release_id, $facts['release_identity'] )
				|| ! hash_equals( $tag, $facts['tag'] )
				|| ! hash_equals( $package_type, $facts['target_type'] )
				|| ! hash_equals( $expected_fingerprint, $facts['fingerprint'] )
				|| ! hash_equals( $channel, $facts['channel'] ?? '' )
				|| ! hash_equals( $this->expected_update_uri( $repository ), $facts['canonical_update_uri'] ?? '' )
				|| ! hash_equals( $repository->locator, $facts['repository_locator'] ?? '' )
				|| ! hash_equals( (string) $repository->provider_repository_id, $facts['repository_identity'] ?? '' )
				|| ( null !== $maximum_artifact_bytes && ( $facts['maximum_artifact_bytes'] ?? null ) !== $maximum_artifact_bytes ) ) {
				throw new RuntimeException();
			}

			return new GitHubReleaseArtifact(
				$result['value']['artifact'],
				$facts['version'],
				$facts['commit_identity'],
				$facts['package_root'],
				$facts['main_file'],
				$facts['artifact_size'],
				$facts['maximum_artifact_bytes'],
				$facts['artifact_sha256']
			);
		} catch ( \Throwable ) {
			try {
				$cleanup_failed = ! $result['value']['artifact']->discard();
			} catch ( \Throwable ) {
				$cleanup_failed = true;
			}
			if ( $cleanup_failed ) {
				throw RepositoryReleaseAcquisitionRejected::cleanup_failed();
			}
			throw new RuntimeException( 'GitHub returned invalid release acquisition evidence.', 502 );
		}
	}

	public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult {
		return $this->webhook_client->assess_setup( $repository_id, $repository, $this->credential( $credential_profile_id ) );
	}

	public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		$this->assert_hook_id( $hook_id );

		return $this->webhook_client->assess_check( $repository_id, $repository, $this->credential( $credential_profile_id ) );
	}

	public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		$this->assert_hook_id( $hook_id );

		return $this->webhook_client->assess_reconfigure( $repository_id, $repository, $this->credential( $credential_profile_id ) );
	}

	public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		$this->assert_hook_id( $hook_id );

		return $this->webhook_client->assess_remove( $repository_id, $repository, $this->credential( $credential_profile_id ) );
	}

	public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		$this->assert_hook_id( $hook_id );

		return $this->webhook_client->assess_test( $repository_id, $repository, $this->credential( $credential_profile_id ) );
	}

	public function setup( string $repository_id, string $repository, string $callback_url, ?string $credential_profile_id, #[\SensitiveParameter] string $signing_secret ): RepositoryWebhookOperationResult {
		$this->assert_repository_id( $repository_id );

		return $this->webhook_client->setup( $repository, $callback_url, $this->credential( $credential_profile_id ), $signing_secret );
	}

	public function check( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		$this->assert_repository_id( $repository_id );

		return $this->webhook_client->check( $repository, $hook_id, $callback_url, $this->credential( $credential_profile_id ) );
	}

	public function reconfigure( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id, #[\SensitiveParameter] string $signing_secret ): RepositoryWebhookOperationResult {
		$this->assert_repository_id( $repository_id );

		return $this->webhook_client->reconfigure( $repository, $hook_id, $callback_url, $this->credential( $credential_profile_id ), $signing_secret );
	}

	public function remove( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		$this->assert_repository_id( $repository_id );

		return $this->webhook_client->remove( $repository, $hook_id, $callback_url, $this->credential( $credential_profile_id ) );
	}

	public function test( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		$this->assert_repository_id( $repository_id );
		$this->assert_hook_id( $hook_id );

		return $this->webhook_client->test( $repository, $hook_id, $callback_url, $this->credential( $credential_profile_id ) );
	}

	private function credential( ?string $credential_profile_id ): string {
		$credential_profile_id = null === $credential_profile_id ? null : trim( $credential_profile_id );
		if ( null === $credential_profile_id || '' === $credential_profile_id ) {
			throw new RuntimeException( 'Choose a saved GitHub credential.', 400 );
		}

		$material = $this->credentials->credential_material( $credential_profile_id );
		$secret   = is_array( $material ) && is_string( $material['secret'] ?? null ) ? trim( $material['secret'] ) : '';
		if ( '' === $secret ) {
			throw new RuntimeException( 'The selected GitHub credential is unavailable.', 400 );
		}

		return $secret;
	}

	private function assert_repository_id( string $repository_id ): void {
		if ( '' === trim( $repository_id ) || strlen( $repository_id ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repository_id ) ) {
			throw new RuntimeException( 'The GitHub repository identity is invalid.', 400 );
		}
	}

	private function assert_hook_id( string $hook_id ): void {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $hook_id ) ) {
			throw new RuntimeException( 'The GitHub hook identity is invalid.', 400 );
		}
	}

	private function encode_repository_name( string $full_name ): string {
		$parts = explode( '/', $full_name );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			throw new RuntimeException( 'GitHub repository names must use the owner/repository form.' );
		}

		return rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}

	private function archive_authorizer( RepositoryReference $repository ): ?Closure {
		if ( ! $repository->private ) {
			return null;
		}

		$credential_id = $repository->credential_id;
		$credential    = $this->credentials->credential_material( $credential_id );
		$token         = is_array( $credential ) ? $credential['secret'] : '';

		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			if ( null !== $credential_id ) {
				throw new RuntimeException( 'The selected GitHub credential is not configured.' );
			}

			throw new RuntimeException( 'RAN_BOOSTER_GITHUB_TOKEN or the Booster secrets file is not configured.' );
		}

		$authorization = 'Bearer ' . trim( $token );

		return static function ( array $arguments ) use ( $authorization ): array {
			$arguments['headers']['Authorization'] = $authorization;

			return $arguments;
		};
	}

	private function release_access_token( RepositoryReference $repository ): ?Closure {
		if ( ! $repository->private && null === $repository->credential_id ) {
			return null;
		}

		return function () use ( $repository ): string {
			$credential = $this->credentials->credential_material( $repository->credential_id );
			$token      = is_array( $credential ) ? $credential['secret'] ?? null : null;
			if ( ! is_string( $token ) || '' === trim( $token ) ) {
				throw new RuntimeException( 'The selected GitHub credential is unavailable.', 400 );
			}

			return trim( $token );
		};
	}

	private function release_source( string $package_type, RepositoryReference $repository, string $channel ): object {
		$repository_id = $repository->provider_repository_id;
		if ( null === $repository_id ) {
			throw new InvalidArgumentException( 'The GitHub release service configuration is unavailable.' );
		}
		$arguments              = array( 'github', $package_type, $repository->locator, $repository_id, $channel, $this->release_access_token( $repository ) );
		$maximum_artifact_bytes = $this->maximum_artifact_bytes();
		if ( null !== $maximum_artifact_bytes ) {
			$arguments[] = $maximum_artifact_bytes;
		}

		return $this->registrar->releases( ...$arguments );
	}

	private function maximum_artifact_bytes(): ?int {
		return null === $this->maximum_artifact_bytes ? null : ( $this->maximum_artifact_bytes )();
	}

	private function ensure_direct_filesystem(): bool {
		if ( ! function_exists( 'get_filesystem_method' ) || ! function_exists( 'WP_Filesystem' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				return false;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( defined( 'FS_METHOD' ) && 'direct' !== constant( 'FS_METHOD' ) ) {
			return false;
		}
		if ( $wp_filesystem instanceof \WP_Filesystem_Direct ) {
			return true;
		}
		if ( is_object( $wp_filesystem ) || 'direct' !== get_filesystem_method() ) {
			return false;
		}

		return WP_Filesystem() && $wp_filesystem instanceof \WP_Filesystem_Direct;
	}

	private function success( mixed $result, string $code, string $cleanup_status ): bool {
		return is_array( $result ) && 5 === count( $result )
			&& array_diff( array_keys( $result ), array( 'ok', 'code', 'value', 'retry_after', 'cleanup_status' ) ) === array()
			&& true === $result['ok'] && $code === $result['code'] && null === $result['retry_after'] && $cleanup_status === $result['cleanup_status'];
	}

	private function read_unavailable( mixed $result, string $operation ): bool {
		return $this->failure( $result, $operation, 'credential_unavailable' )
			|| $this->failure( $result, $operation, 'repository_access_unavailable' )
			|| $this->failure( $result, $operation, 'rate_limited' );
	}

	private function failure( mixed $result, string $operation, string $code ): bool {
		if ( ! is_array( $result ) || 5 !== count( $result )
			|| array_diff( array_keys( $result ), array( 'ok', 'code', 'value', 'retry_after', 'cleanup_status' ) ) !== array()
			|| false !== $result['ok'] || $code !== $result['code'] || null !== $result['value']
			|| ! in_array( $result['cleanup_status'], array( 'not_applicable', 'complete', 'failed' ), true )
			|| ( 'list' === $operation && 'not_applicable' !== $result['cleanup_status'] )
			|| 'failed' === $result['cleanup_status'] ) {
			return false;
		}

		return 'rate_limited' === $code
			? ( null === $result['retry_after'] || ( is_int( $result['retry_after'] ) && 1 <= $result['retry_after'] && 86400 >= $result['retry_after'] ) )
			: null === $result['retry_after'];
	}

	private function discard_rejected_artifact( mixed $result ): bool {
		$artifact = is_array( $result ) && is_array( $result['value'] ?? null ) ? $result['value']['artifact'] ?? null : null;
		if ( ! is_object( $artifact ) || ! method_exists( $artifact, 'discard' ) ) {
			return true;
		}
		try {
			return true === $artifact->discard();
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function bounded_opaque_value( string $value, int $maximum_bytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximum_bytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
