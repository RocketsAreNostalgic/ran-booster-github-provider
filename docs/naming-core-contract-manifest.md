# Provider/Core naming contract manifest — 2026-10-01

## Operative API13 methods-only scope — 1 October 2026

The current accepted tranche implements exactly 50 methods across GitHubProvider
(30), WebhookPolicy (8), CredentialPolicy (5), GitHubReleaseArtifact (4), and
WebhookNormalizer (3), paired with 47 Core declarations across 20 interfaces.
Only method declarations and their receiver-resolved callers/reflection names
change. Public parameter names and promotions in the historical broader proposal
below remain deferred; that proposal is not blanket implementation authority.
Core owns API13 admission and combined qualification. Bitbucket retains its
separate owner and must provide a matching migration. Source/host overlays are
preparation only; publication and real Core lock adoption remain separate gates.

The remaining sections preserve the earlier audit evidence and proposal.


Prepared read-only for Provider #25 and Core #167; proposed mappings are not an agreed contract change. Provider source baseline de9807f; current Core a8b635a8c9a40482ec5f125023f54e43f88bce58. Provider CI/AGENTS certifies candidate 18b0ec619174000a9a9dbc27b9d68b44b0265449, not current Core. The coordinator fetched the certified object into a separate worktree. Both exact hosts are qualified separately; no equivalence is assumed.

This inventory is exhaustive for the seven named Provider source classes directly implementing Core interfaces, including RepositoryBrowser inherited through CredentialedPublicRepositoryBrowser. ProviderCapability is a marker (no method declaration). The Core consumer appendix below is a lexical occurrence inventory, deliberately including same-spelling methods on other objects; it is not a claim that every hit dispatches to these interfaces. Dynamic names constructed without the literal symbol cannot be proved absent through lexical search.

## Declaration / implementation / proposed mapping

Parameter spellings are preserved exactly, including SensitiveParameter attributes. CamelCase method/parameter proposals use direct snake_case. Methods already compliant remain unchanged. Interface methods and their implementation parameters remain reserved to the coordinated contract cohort.

| Core declaration | Method and parameters | Provider implementation | Implementation parameters | Proposed method | Proposed parameter names |
|---|---|---|---|---|---|
| `core/RAN/RepositoryProvider/RepositoryProvider.php:9` | `getMetadata()` | `provider/src/GitHubProvider.php:221` | `` | `get_metadata` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryProvider.php:11` | `getProviderDiagnostics()` | `provider/src/GitHubProvider.php:225` | `` | `get_provider_diagnostics` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryProvider.php:13` | `resolveRepository(RepositoryLookupRequest $request)` | `provider/src/GitHubProvider.php:273` | `RepositoryLookupRequest $request` | `resolve_repository` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryProvider.php:23` | `prepareArchive(ArchiveRequest $request)` | `provider/src/GitHubProvider.php:277` | `ArchiveRequest $request` | `prepare_archive` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryPathInspector.php:12` | `repositoryPathExists(RepositoryReference $repository, string $ref, string $path)` | `provider/src/GitHubProvider.php:343` | `RepositoryReference $repository, string $ref, string $path` | `repository_path_exists` | unchanged |
| `core/RAN/RepositoryProvider/CredentialValidator.php:11` | `validateCredential(string $credentialId)` | `provider/src/GitHubProvider.php:261` | `string $credentialId` | `validate_credential` | `$credentialId → $credential_id` |
| `core/RAN/RepositoryProvider/CredentialedPublicRepositoryBrowser.php:16` | `getPublicRepositoryBrowseMetadata()` | `provider/src/GitHubProvider.php:269` | `` | `get_public_repository_browse_metadata` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryBrowser.php:11` | `browseRepositories(RepositoryBrowseRequest $request)` | `provider/src/GitHubProvider.php:265` | `RepositoryBrowseRequest $request` | `browse_repositories` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:11` | `getWebhookPolicy()` | `provider/src/GitHubProvider.php:253` | `` | `get_webhook_policy` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:17` | `diagnoseWebhookReadiness()` | `provider/src/GitHubProvider.php:257` | `` | `diagnose_webhook_readiness` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:19` | `normalizeWebhook(WebhookRequest $request)` | `provider/src/GitHubProvider.php:353` | `WebhookRequest $request` | `normalize_webhook` | unchanged |
| `core/RAN/RepositoryProvider/ProviderCredentialPolicySupplier.php:11` | `getCredentialPolicy()` | `provider/src/GitHubProvider.php:249` | `` | `get_credential_policy` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryWebhookSettingsLink.php:14` | `repositoryWebhookSettingsUrl(string $locator)` | `provider/src/GitHubProvider.php:357` | `string $locator` | `repository_webhook_settings_url` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryWebhookFitness.php:10` | `assessSetup(string $repositoryId, string $repository, ?string $credentialProfileId)` | `provider/src/GitHubProvider.php:613` | `string $repositoryId, string $repository, ?string $credentialProfileId` | `assess_setup` | `$repositoryId → $repository_id`, `$credentialProfileId → $credential_profile_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookFitness.php:11` | `assessCheck(string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId)` | `provider/src/GitHubProvider.php:617` | `string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId` | `assess_check` | `$repositoryId → $repository_id`, `$credentialProfileId → $credential_profile_id`, `$hookId → $hook_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookFitness.php:12` | `assessReconfigure(string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId)` | `provider/src/GitHubProvider.php:623` | `string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId` | `assess_reconfigure` | `$repositoryId → $repository_id`, `$credentialProfileId → $credential_profile_id`, `$hookId → $hook_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookFitness.php:13` | `assessRemove(string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId)` | `provider/src/GitHubProvider.php:629` | `string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId` | `assess_remove` | `$repositoryId → $repository_id`, `$credentialProfileId → $credential_profile_id`, `$hookId → $hook_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookFitness.php:14` | `assessTest(string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId)` | `provider/src/GitHubProvider.php:635` | `string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId` | `assess_test` | `$repositoryId → $repository_id`, `$credentialProfileId → $credential_profile_id`, `$hookId → $hook_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookManagement.php:10` | `setup(string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret)` | `provider/src/GitHubProvider.php:641` | `string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret` | `setup` | `$repositoryId → $repository_id`, `$callbackUrl → $callback_url`, `$credentialProfileId → $credential_profile_id`, `$signingSecret → $signing_secret` |
| `core/RAN/RepositoryProvider/RepositoryWebhookManagement.php:17` | `check(string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId)` | `provider/src/GitHubProvider.php:647` | `string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId` | `check` | `$repositoryId → $repository_id`, `$hookId → $hook_id`, `$callbackUrl → $callback_url`, `$credentialProfileId → $credential_profile_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookManagement.php:24` | `reconfigure(string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret)` | `provider/src/GitHubProvider.php:653` | `string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret` | `reconfigure` | `$repositoryId → $repository_id`, `$hookId → $hook_id`, `$callbackUrl → $callback_url`, `$credentialProfileId → $credential_profile_id`, `$signingSecret → $signing_secret` |
| `core/RAN/RepositoryProvider/RepositoryWebhookManagement.php:32` | `remove(string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId)` | `provider/src/GitHubProvider.php:659` | `string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId` | `remove` | `$repositoryId → $repository_id`, `$hookId → $hook_id`, `$callbackUrl → $callback_url`, `$credentialProfileId → $credential_profile_id` |
| `core/RAN/RepositoryProvider/RepositoryWebhookManagement.php:39` | `test(string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId)` | `provider/src/GitHubProvider.php:665` | `string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId` | `test` | `$repositoryId → $repository_id`, `$hookId → $hook_id`, `$callbackUrl → $callback_url`, `$credentialProfileId → $credential_profile_id` |
| `core/RAN/RepositoryProvider/RepositoryReleaseMetadata.php:10` | `expectedUpdateUri(RepositoryReference $repository)` | `provider/src/GitHubProvider.php:361` | `RepositoryReference $repository` | `expected_update_uri` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseMetadata.php:12` | `releaseDetailsUrl(RepositoryReference $repository, string $tag)` | `provider/src/GitHubProvider.php:369` | `RepositoryReference $repository, string $tag` | `release_details_url` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseCandidateListing.php:11` | `listReleaseCandidates(string $packageType, RepositoryReference $repository, string $channel)` | `provider/src/GitHubProvider.php:424` | `string $packageType, RepositoryReference $repository, string $channel` | `list_release_candidates` | `$packageType → $package_type` |
| `core/RAN/RepositoryProvider/RepositoryReleaseInspector.php:11` | `inspectRelease(string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $channel)` | `provider/src/GitHubProvider.php:472` | `string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $channel` | `inspect_release` | `$packageType → $package_type`, `$providerReleaseId → $provider_release_id` |
| `core/RAN/RepositoryProvider/RepositoryReleaseAcquirer.php:11` | `acquireRelease(string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $expectedFingerprint, string $channel)` | `provider/src/GitHubProvider.php:531` | `string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $expectedFingerprint, string $channel` | `acquire_release` | `$packageType → $package_type`, `$providerReleaseId → $provider_release_id`, `$expectedFingerprint → $expected_fingerprint` |
| `core/RAN/RepositoryProvider/RepositoryReleaseNativeTargets.php:10` | `hasRegisteredNativeTarget(string $packageType, string $installedIdentifier)` | `provider/src/GitHubProvider.php:378` | `string $packageType, string $installedIdentifier` | `has_registered_native_target` | `$packageType → $package_type`, `$installedIdentifier → $installed_identifier` |
| `core/RAN/RepositoryProvider/RepositoryReleaseNativeTargets.php:16` | `createNativeTarget(string $packageType, RepositoryReference $repository, string $metadataFile, string $packageRoot, string $installedIdentifier, string $channel, string $deploymentPolicy)` | `provider/src/GitHubProvider.php:388` | `string $packageType, RepositoryReference $repository, string $metadataFile, string $packageRoot, string $installedIdentifier, string $channel, string $deploymentPolicy` | `create_native_target` | `$packageType → $package_type`, `$metadataFile → $metadata_file`, `$packageRoot → $package_root`, `$installedIdentifier → $installed_identifier`, `$deploymentPolicy → $deployment_policy` |
| `core/RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:18` | `workflowStatus(RepositoryReleaseWorkflowTarget $target)` | `provider/src/GitHubProvider.php:229` | `RepositoryReleaseWorkflowTarget $status` | `workflow_status` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:20` | `workflowPreview(RepositoryReleaseWorkflowTarget $target, string $key)` | `provider/src/GitHubProvider.php:233` | `RepositoryReleaseWorkflowTarget $status, string $key` | `workflow_preview` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:22` | `workflowInspect(RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId)` | `provider/src/GitHubProvider.php:237` | `RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId` | `workflow_inspect` | `$credentialId → $credential_id` |
| `core/RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:24` | `workflowSetup(RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId)` | `provider/src/GitHubProvider.php:241` | `RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId` | `workflow_setup` | `$credentialId → $credential_id` |
| `core/RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:26` | `workflowOutcome(RepositoryReleaseWorkflowTarget $target, ?string $credentialId)` | `provider/src/GitHubProvider.php:245` | `RepositoryReleaseWorkflowTarget $status, ?string $credentialId` | `workflow_outcome` | `$credentialId → $credential_id` |
| `core/RAN/RepositoryProvider/ProviderDiagnostics.php:14` | `diagnose(ProviderDiagnosticRequest $request)` | `provider/src/Diagnostics.php:19` | `ProviderDiagnosticRequest $request` | `diagnose` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:9` | `getProvider()` | `provider/src/WebhookPolicy.php:15` | `` | `get_provider` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:14` | `getRetainedHeaders()` | `provider/src/WebhookPolicy.php:19` | `` | `get_retained_headers` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:16` | `getSignatureHeader()` | `provider/src/WebhookPolicy.php:23` | `` | `get_signature_header` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:22` | `normalizeWebhook(array $metadata, mixed $secret)` | `provider/src/WebhookPolicy.php:27` | `array $metadata, mixed $secret` | `normalize_webhook` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:27` | `getConstantNames()` | `provider/src/WebhookPolicy.php:64` | `` | `get_constant_names` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:33` | `webhookFromConstants(array $constants)` | `provider/src/WebhookPolicy.php:68` | `array $constants` | `webhook_from_constants` | unchanged |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:35` | `authorizeWebhook(SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository)` | `provider/src/WebhookPolicy.php:72` | `SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository` | `authorize_webhook` | `$repositoryAuthorityId → $repository_authority_id` |
| `core/RAN/RepositoryProvider/ProviderWebhookPolicy.php:41` | `repositoryTargetMatches(string $target, string $repositoryLocator)` | `provider/src/WebhookPolicy.php:98` | `string $target, string $repositoryLocator` | `repository_target_matches` | `$repositoryLocator → $repository_locator` |
| `core/RAN/RepositoryProvider/ProviderCredentialPolicy.php:9` | `getProvider()` | `provider/src/CredentialPolicy.php:19` | `` | `get_provider` | unchanged |
| `core/RAN/RepositoryProvider/ProviderCredentialPolicy.php:15` | `normalizeCredential(array $metadata, mixed $secret)` | `provider/src/CredentialPolicy.php:23` | `array $metadata, #[\SensitiveParameter] mixed $secret` | `normalize_credential` | unchanged |
| `core/RAN/RepositoryProvider/ProviderCredentialPolicy.php:20` | `getConstantNames()` | `provider/src/CredentialPolicy.php:93` | `` | `get_constant_names` | unchanged |
| `core/RAN/RepositoryProvider/ProviderCredentialPolicy.php:26` | `credentialFromConstants(array $constants)` | `provider/src/CredentialPolicy.php:97` | `array $constants` | `credential_from_constants` | unchanged |
| `core/RAN/RepositoryProvider/SubmittedCredentialValidator.php:19` | `validateSubmittedCredential(array $metadata, #[\SensitiveParameter] string $secret)` | `provider/src/CredentialPolicy.php:58` | `array $metadata, #[\SensitiveParameter] string $secret` | `validate_submitted_credential` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:11` | `getWebhookPolicy()` | `provider/src/WebhookNormalizer.php:32` | `` | `get_webhook_policy` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:17` | `diagnoseWebhookReadiness()` | `provider/src/WebhookNormalizer.php:36` | `` | `diagnose_webhook_readiness` | unchanged |
| `core/RAN/RepositoryProvider/WebhookNormalizer.php:19` | `normalizeWebhook(WebhookRequest $request)` | `provider/src/WebhookNormalizer.php:99` | `WebhookRequest $request` | `normalize_webhook` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:9` | `discard()` | `provider/src/GitHubReleaseArtifact.php:54` | `` | `discard` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:11` | `handoffToCore()` | `provider/src/GitHubReleaseArtifact.php:71` | `` | `handoff_to_core` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:13` | `version()` | `provider/src/GitHubReleaseArtifact.php:102` | `` | `version` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:15` | `packageRoot()` | `provider/src/GitHubReleaseArtifact.php:106` | `` | `package_root` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:17` | `mainFile()` | `provider/src/GitHubReleaseArtifact.php:110` | `` | `main_file` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifact.php:19` | `identifier(string $packageType)` | `provider/src/GitHubReleaseArtifact.php:114` | `string $packageType` | `identifier` | `$packageType → $package_type` |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:16` | `inspect(callable $inspection)` | `provider/src/GitHubReleaseArtifact.php:82` | `callable $inspection` | `inspect` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:18` | `discard()` | `provider/src/GitHubReleaseArtifact.php:54` | `` | `discard` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:20` | `resolvedRef()` | `provider/src/GitHubReleaseArtifact.php:90` | `` | `resolved_ref` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:22` | `version()` | `provider/src/GitHubReleaseArtifact.php:102` | `` | `version` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:24` | `size()` | `provider/src/GitHubReleaseArtifact.php:94` | `` | `size` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:26` | `sha256()` | `provider/src/GitHubReleaseArtifact.php:98` | `` | `sha256` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseNativeTarget.php:8` | `register()` | `provider/src/GitHubReleaseNativeTarget.php:46` | `` | `register` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseNativeTarget.php:10` | `status()` | `provider/src/GitHubReleaseNativeTarget.php:70` | `` | `status` | unchanged |
| `core/RAN/RepositoryProvider/RepositoryReleaseNativeTarget.php:12` | `refresh()` | `provider/src/GitHubReleaseNativeTarget.php:152` | `` | `refresh` | unchanged |

## Integration and compatibility obligations

1. Core #167 owns Core declarations, consumers, public API/version compatibility policy and Core tests. Provider #25 owns corresponding seven implementation files and Provider tests, subject to fresh claims; Provider #28 and the API 12/workflow V3 owner retain template/workflow integration ownership. Agree the exact mapping before changing interface implementations.
2. All five workflow methods currently declare `$target` in Core but implement `$status` in GitHubProvider. A named call using `target:` would not address that implementation parameter. Decide canonical parameter names and compatibility explicitly; a mechanical method rename must not silently resolve this pre-existing mismatch.
3. Method renaming breaks external call sites and implementations; parameter renaming can break PHP named arguments even without a method rename. Agree coordinated pre-release/API compatibility treatment, any deliberate API/version bump, and required other provider/add-on consumer updates with Core. No compatibility aliases or version changes are authorized by this manifest.
4. Prepare Core declarations plus every implementation and consumer together; qualify the exact combined Core/Provider tuple before adoption. Core bundles Provider while Provider consumes Core interfaces: an immutable Provider release/source tuple and corresponding Core composition update must be explicitly sequenced; neither an obsolete-host pass nor local source success certifies the new bundle.
5. Run independent Provider checks, exact candidate Core host-contract/static-analysis/implementation checks, PHP 8.2/8.5 required CI, and Core composer/pnpm gates plus applicable exact archive/installed proofs. Preserve release identity, errors/statuses, webhook/credential behavior, JSON/persisted keys, template contracts, and protocol semantics. Named constructor arguments into Core DTOs (e.g. `failureCode:`) are separate Core-owned contract inputs, not Provider-local variables.
6. Release Updater beta.9/protocol 5 adoption remains an explicit integration handoff. Its external method calls and constructor arguments cannot be mechanically snake-cased as Provider-owned symbols. No merges, releases, dependency updates, or adoption are authorized here.
7. Destination: coordinator's Provider #25 PR/commit and Core #167 contract handoff. Remaining gates: mapping/ownership/API decision, review of exact PR tuple, required checks, exact combined-host/archive/installed evidence, Ben's merge authorization, later publication and explicit connected adoption. This artifact is prepared only; it records no integration, merge, publication, or adoption.

## Confirmed production consumer loci

- Core `RAN/RepositoryProvider/ProviderRegistry.php` binds provider metadata, capabilities and credential/webhook policies.
- Core `RAN/Admin/RepositoryPickerController.php`, `PackageRepositoryRequestResolver.php`, `ProviderProfileAdminController.php`, `ProviderSettingsPresenter.php` browse, resolve, validate and prepare archives.
- Core `RAN/Deployment/AdmittedBranchHostAdapter.php` and `RAN/Portability/BlueprintRepositoryVerifier.php` prepare/resolve repositories.
- Core `RAN/Webhook/WebhookProcessor.php`, `RAN/Secrets/SecretsFile.php`, `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php` normalize, authorize, assess and execute webhook operations.
- Core `RAN/Internal/ReleaseManagement/ProspectiveReleaseCandidateReader.php`, `RAN/AddOn/ReleaseTracking/{NativeReleaseTrackingFacade,NativeProspectiveReleaseFacade}.php` list/inspect/acquire releases and claim artifact custody.
- Core `RAN/WordPress/ManagedReleaseTargetRegistrar.php` creates native targets.
- Core `RAN/Admin/ReleaseManagement/{ReleaseWorkflowRequestController,ReleaseWorkflowPresenter}.php` consumes all five workflow methods.

Core fixture/test implementations also require migration: `tests/fixtures/ran-booster-{fixture,release-capability}-provider/`, `tests/Support/WebhookManagementCapabilityProviders.php`, `tests/RepositoryProvider/Support/`, `tests/Admin/{CredentialValidationProvider.php,Support/ExpiryReminderProvider.php,ReleaseManagement/Support/}`, `tests/Portability/TemporaryCredentialProvider.php`, `tests/WordPress/RuntimeReleaseProvider.php`. The full lexical appendix additionally indexes anonymous doubles and matching method strings throughout Core/Provider tests and scripts.

## Provider-local call sites for interface names requiring rename

Calls with matching names can dispatch to package-local helpers or Core DTOs as well as these interfaces; inspect receiver type before editing.

### acquireRelease

- `provider/src/GitHubProvider.php:531`
- `provider/tests/Booster/GitHub/PublicReleaseResultMappingTest.php:43,70,83,95,101,166`
- `provider/tests/Booster/GitHub/ReleaseAcquisitionTest.php:41,70,90,113`

### assessCheck

- `provider/src/GitHubProvider.php:617,620`
- `provider/src/RepositoryWebhookClient.php:18`
- `provider/tests/Booster/GitHub/RepositoryWebhookClientTest.php:22`

### assessReconfigure

- `provider/src/GitHubProvider.php:623,626`
- `provider/src/RepositoryWebhookClient.php:21`
- `provider/tests/Booster/GitHub/RepositoryWebhookClientTest.php:23`

### assessRemove

- `provider/src/GitHubProvider.php:629,632`
- `provider/src/RepositoryWebhookClient.php:24`
- `provider/tests/Booster/GitHub/RepositoryWebhookClientTest.php:24`

### assessSetup

- `provider/src/GitHubProvider.php:613,614`
- `provider/src/RepositoryWebhookClient.php:15`
- `provider/tests/Booster/GitHub/RepositoryWebhookClientTest.php:21`

### assessTest

- `provider/src/GitHubProvider.php:635,638`
- `provider/src/RepositoryWebhookClient.php:27`

### authorizeWebhook

- `provider/src/WebhookNormalizer.php:120`
- `provider/src/WebhookPolicy.php:72`

### browseRepositories

- `provider/src/GitHubProvider.php:265`

### createNativeTarget

- `provider/src/GitHubProvider.php:388`
- `provider/tests/Booster/GitHub/ArchiveLimitBoundaryTest.php:232`

### credentialFromConstants

- `provider/src/CredentialPolicy.php:97`

### diagnoseWebhookReadiness

- `provider/src/GitHubProvider.php:257,258`
- `provider/src/WebhookNormalizer.php:36`
- `provider/tests/Booster/GitHub/WebhookNormalizerTest.php:506,514,530,544,559,571`

### expectedUpdateUri

- `provider/src/GitHubProvider.php:361,370,398,511,583`
- `provider/src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:225`
- `provider/src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:119,121,129,131,137,154`
- `provider/src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:212,216,228`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:181,184`

### getConstantNames

- `provider/src/CredentialPolicy.php:93`
- `provider/src/WebhookPolicy.php:64`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:188`

### getCredentialPolicy

- `provider/src/GitHubProvider.php:249`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:186`

### getMetadata

- `provider/src/GitHubProvider.php:221`
- `provider/tests/Booster/GitHub/ArchiveLimitBoundaryTest.php:138`
- `provider/tests/Booster/GitHub/GitHubProviderMetadataTest.php:15`
- `provider/tests/Booster/GitHub/RepositoryResolverTest.php:59`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:144`
- `provider/tests/host-contract.php:82`

### getProvider

- `provider/src/CredentialPolicy.php:19`
- `provider/src/WebhookNormalizer.php:100`
- `provider/src/WebhookPolicy.php:15,77`

### getProviderDiagnostics

- `provider/src/GitHubProvider.php:225`
- `provider/tests/host-contract.php:83`

### getPublicRepositoryBrowseMetadata

- `provider/src/GitHubProvider.php:269`

### getRetainedHeaders

- `provider/src/WebhookPolicy.php:19`

### getSignatureHeader

- `provider/src/WebhookPolicy.php:23`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:189`

### getWebhookPolicy

- `provider/src/GitHubProvider.php:253,254`
- `provider/src/WebhookNormalizer.php:32`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:187`

### handoffToCore

- `provider/src/GitHubReleaseArtifact.php:71`
- `provider/tests/Booster/GitHub/ReleaseAcquisitionTest.php:47`
- `provider/tests/Booster/GitHub/ReleaseArtifactClaimLifetimeTest.php:29,370`
- `provider/tests/host-contract.php:89`

### hasRegisteredNativeTarget

- `provider/src/GitHubProvider.php:378`

### inspectRelease

- `provider/src/GitHubProvider.php:472`
- `provider/tests/Booster/GitHub/ArchiveLimitBoundaryTest.php:76`
- `provider/tests/Booster/GitHub/PublicReleaseResultMappingTest.php:42,69,143`
- `provider/tests/Booster/GitHub/ReleaseAcquisitionTest.php:126`
- `provider/tests/Booster/GitHub/ReleaseInspectionTest.php:39,57,71,91,105`

### listReleaseCandidates

- `provider/src/GitHubProvider.php:424`
- `provider/tests/Booster/GitHub/ArchiveLimitBoundaryTest.php:120,142`
- `provider/tests/Booster/GitHub/PublicReleaseResultMappingTest.php:41,68,110,130`
- `provider/tests/Booster/GitHub/ReleaseCandidateListingTest.php:46,64,79,93,110,128,144,157,178`

### mainFile

- `provider/src/GitHubReleaseArtifact.php:27,35,110,111,120`
- `provider/tests/Booster/GitHub/ReleaseInspectionTest.php:46,66`
- `provider/tests/host-contract.php:92`

### normalizeCredential

- `provider/src/CredentialPolicy.php:23`
- `provider/tests/Booster/GitHub/CredentialPolicyTest.php:24,95,103`

### normalizeWebhook

- `provider/src/GitHubProvider.php:353,354`
- `provider/src/WebhookNormalizer.php:99`
- `provider/src/WebhookPolicy.php:27`
- `provider/tests/Booster/GitHub/WebhookNormalizerTest.php:34,54,62,71,79,92,112,128,159,190,230,258,288,309,328,355,366,412,446,464,486,490,594`

### packageRoot

- `provider/src/GitHubProvider.php:392`
- `provider/src/GitHubReleaseArtifact.php:26,34,106,107,120,121`
- `provider/src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:212`
- `provider/tests/Booster/GitHub/ReleaseInspectionTest.php:45,65`
- `provider/tests/host-contract.php:91`

### prepareArchive

- `provider/src/GitHubProvider.php:277`
- `provider/tests/host-contract.php:85`

### releaseDetailsUrl

- `provider/src/GitHubProvider.php:369,456`
- `provider/tests/Booster/GitHub/ReleaseInspectionTest.php:48`
- `provider/tests/Booster/GitHub/VendorConformanceTest.php:182,183`

### repositoryPathExists

- `provider/src/GitHubProvider.php:343`

### repositoryTargetMatches

- `provider/src/WebhookPolicy.php:98`

### repositoryWebhookSettingsUrl

- `provider/src/GitHubProvider.php:357`

### resolveRepository

- `provider/src/GitHubProvider.php:273`
- `provider/tests/host-contract.php:84`

### resolvedRef

- `provider/src/GitHubReleaseArtifact.php:90`
- `provider/tests/host-contract.php:98`

### validateCredential

- `provider/src/Diagnostics.php:38`
- `provider/src/GitHubProvider.php:261,262`
- `provider/src/RepositoryBrowser.php:357`
- `provider/tests/Booster/GitHub/CredentialExpiryValidationTest.php:35,62,82`
- `provider/tests/Booster/GitHub/DiagnosticsTest.php:264`
- `provider/tests/Booster/GitHub/RepositoryResolverTest.php:640`

### validateSubmittedCredential

- `provider/src/CredentialPolicy.php:58`
- `provider/tests/Booster/GitHub/CredentialPolicyTest.php:58,81`

### webhookFromConstants

- `provider/src/WebhookPolicy.php:68`

### workflowInspect

- `provider/src/GitHubProvider.php:237`
- `provider/tests/host-contract.php:106`

### workflowOutcome

- `provider/src/GitHubProvider.php:245`
- `provider/tests/host-contract.php:108`

### workflowPreview

- `provider/src/GitHubProvider.php:233`
- `provider/tests/host-contract.php:105`

### workflowSetup

- `provider/src/GitHubProvider.php:241`
- `provider/tests/host-contract.php:107`

### workflowStatus

- `provider/src/GitHubProvider.php:229`
- `provider/tests/host-contract.php:104`



# Core consumer references for coordinated Provider naming

Core a8b635a8c9a40482ec5f125023f54e43f88bce58. Compact lexical occurrence index for 44 renamed interface methods: declarations, implementations, direct calls, strings/callbacks, mocks and documentation. Same-name unrelated receivers are possible; inspect receiver before editing. All paths relative to Core.

## acquireRelease

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:274`
- `RAN/RepositoryProvider/RepositoryReleaseAcquirer.php:11`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1701,1908,1916`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:66`
- `tests/RepositoryProvider/ProviderContractsTest.php:231,233`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:179`

## assessCheck

- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php:189,440`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php:22`
- `RAN/RepositoryProvider/RepositoryWebhookFitness.php:11`
- `docs/characterization/core-v3-compatibility-and-backend-baseline.md:126`
- `docs/characterization/provider-owned-repository-webhook-fitness.md:18,109`
- `docs/custom-git-vendors.md:209`
- `docs/provider-extension-contract.md:116`
- `docs/secret-boundary-characterization.md:187`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:568`
- `tests/Admin/WebhookManagement/WebhookManagementControllerTest.php:1631,1633`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:82,83`
- `tests/RepositoryProvider/ProviderContractsTest.php:408`
- `tests/Support/WebhookManagementCapabilityProviders.php:72`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:185`

## assessReconfigure

- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php:195,441`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php:24`
- `RAN/RepositoryProvider/RepositoryWebhookFitness.php:12`
- `docs/characterization/core-v3-compatibility-and-backend-baseline.md:126`
- `docs/characterization/provider-owned-repository-webhook-fitness.md:19,110`
- `docs/custom-git-vendors.md:210`
- `docs/provider-extension-contract.md:116`
- `docs/secret-boundary-characterization.md:188`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:572`
- `tests/Admin/WebhookManagement/WebhookManagementControllerTest.php:1640,1642`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:86,87`
- `tests/RepositoryProvider/ProviderContractsTest.php:408`
- `tests/Support/WebhookManagementCapabilityProviders.php:76`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:189`

## assessRemove

- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php:201,442`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php:26`
- `RAN/RepositoryProvider/RepositoryWebhookFitness.php:13`
- `docs/characterization/core-v3-compatibility-and-backend-baseline.md:126`
- `docs/characterization/provider-owned-repository-webhook-fitness.md:19,111`
- `docs/custom-git-vendors.md:210`
- `docs/provider-extension-contract.md:117`
- `docs/secret-boundary-characterization.md:188`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:576`
- `tests/Admin/WebhookManagement/WebhookManagementControllerTest.php:1649,1651`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:90,91`
- `tests/RepositoryProvider/ProviderContractsTest.php:408,420`
- `tests/Support/WebhookManagementCapabilityProviders.php:80`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:193,198`

## assessSetup

- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php:183,439`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php:20`
- `RAN/RepositoryProvider/RepositoryWebhookFitness.php:10`
- `docs/characterization/core-v3-compatibility-and-backend-baseline.md:126`
- `docs/characterization/provider-owned-repository-webhook-fitness.md:18,108`
- `docs/custom-git-vendors.md:209`
- `docs/provider-extension-contract.md:116`
- `docs/secret-boundary-characterization.md:187`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:130,476,479,490,564`
- `tests/Admin/WebhookManagement/WebhookManagementControllerTest.php:1622,1624`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:78,79,256`
- `tests/RepositoryProvider/ProviderContractsTest.php:408,416`
- `tests/Support/WebhookManagementCapabilityProviders.php:68`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:181`

## assessTest

- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php:207,443`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php:28`
- `RAN/RepositoryProvider/RepositoryWebhookFitness.php:14`
- `docs/characterization/provider-owned-repository-webhook-fitness.md:19`
- `docs/custom-git-vendors.md:210`
- `docs/provider-extension-contract.md:117`
- `docs/secret-boundary-characterization.md:188,204`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:580`
- `tests/Admin/WebhookManagement/WebhookManagementControllerTest.php:1658,1660`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:94,95`
- `tests/RepositoryProvider/ProviderContractsTest.php:408`
- `tests/Support/WebhookManagementCapabilityProviders.php:84`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:197`

## authorizeWebhook

- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:35`
- `RAN/Webhook/WebhookProcessor.php:58`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:310`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:216`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:705`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:878`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:42`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:60`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1128`
- `tests/Secrets/WebhookProfileStorageTest.php:256`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:427`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:54`

## browseRepositories

- `RAN/Admin/RepositoryPickerController.php:72,83`
- `RAN/RepositoryProvider/RepositoryBrowser.php:11`
- `tests/Admin/DashboardIndexRoutingTest.php:2941,3014`
- `tests/Admin/PackageAdminControllerDispatcherTest.php:440`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:432`
- `tests/Admin/PublicLookupProfileHtmxDispatcherTest.php:183`
- `tests/Admin/RepositoryPickerControllerTest.php:403,438,476,516,563`
- `tests/RepositoryProvider/GitHubExternalExtensionParityTest.php:326`
- `tests/RepositoryProvider/ProviderContractsTest.php:395`

## createNativeTarget

- `RAN/RepositoryProvider/RepositoryReleaseNativeTargets.php:16`
- `RAN/WordPress/ManagedReleaseTargetRegistrar.php:474`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1616`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:72`
- `tests/RepositoryProvider/ProviderContractsTest.php:41,44`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1084,1991`
- `tests/WordPress/RuntimeReleaseProvider.php:88`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:113,216`

## credentialFromConstants

- `RAN/RepositoryProvider/ProviderCredentialPolicy.php:26`
- `RAN/Secrets/SecretsFile.php:1042`
- `docs/canonical-secret-policy-characterization.md:212`
- `tests/Portability/TemporaryCredentialProvider.php:110`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:669`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:792,847`
- `tests/RepositoryProvider/Support/ExternalFixtureCredentialPolicy.php:48`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:38`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1050`
- `tests/fixtures/ran-booster-fixture-provider/src/CredentialPolicy.php:58`

## diagnoseWebhookReadiness

- `RAN/RepositoryProvider/WebhookNormalizer.php:17`
- `RAN/Troubleshooting/TroubleshootingService.php:195`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:546`
- `tests/Admin/DashboardIndexRoutingTest.php:2725`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:406`
- `tests/Deployment/WebhookV1ExecutionBoundaryTest.php:183`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:226`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:761`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:710`
- `tests/Support/WebhookManagementCapabilityProviders.php:132`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:437`
- `tests/Webhook/WebhookControllerTest.php:232`
- `tests/Webhook/WebhookProcessorTest.php:553`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:97`

## expectedUpdateUri

- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:872`
- `RAN/AddOn/ReleaseTracking/ReleaseTrackingEligibility.php:25,41,55,56`
- `RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php:52,1071`
- `RAN/Admin/ReleaseManagement/ReleaseWorkflowProviderProjection.php:22`
- `RAN/RepositoryProvider/RepositoryReleaseMetadata.php:10`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowTarget.php:23,36,74,76`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:874,1731,1833,1834`
- `tests/AddOn/ReleaseTracking/ReleaseTrackingContractTest.php:58`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:56`
- `tests/RepositoryProvider/ProviderContractsTest.php:361,366`
- `tests/RepositoryProvider/RepositoryReleaseWorkflowInputTest.php:31,38`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:150,1970,1975,3008,3013`
- `tests/WordPress/RuntimeReleaseProvider.php:60,65`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:91,96,139,144`

## getConstantNames

- `RAN/RepositoryProvider/ProviderCredentialPolicy.php:20`
- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:27`
- `RAN/Secrets/SecretsFile.php:1042,1086`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:302`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:209`
- `tests/Portability/TemporaryCredentialProvider.php:106`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:665,697`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:788,843,870`
- `tests/RepositoryProvider/Support/ExternalFixtureCredentialPolicy.php:44`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:34`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:36,56`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1043,1107`
- `tests/Secrets/WebhookProfileStorageTest.php:164,248`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:419`
- `tests/WordPress/github-provider-installed-readback.php:76`
- `tests/fixtures/ran-booster-fixture-provider/src/CredentialPolicy.php:43`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:44`

## getCredentialPolicy

- `RAN/RepositoryProvider/ProviderCredentialPolicySupplier.php:11`
- `RAN/RepositoryProvider/ProviderRegistry.php:179`
- `tests/Admin/CredentialProfileInteractionDispatcherTest.php:1061`
- `tests/Admin/DashboardIndexRoutingTest.php:1568,2717`
- `tests/Admin/Support/ExpiryReminderProvider.php:27`
- `tests/Portability/TemporaryCredentialProvider.php:43`
- `tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php:84`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:749`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:702,764,828`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:65`
- `tests/WordPress/github-provider-installed-readback.php:73`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:83`

## getMetadata

- `RAN/Admin/BackgroundDeploymentFailureMonitor.php:79`
- `RAN/Admin/CredentialExpiryReminder.php:55`
- `RAN/Admin/ProviderProfileAdminController.php:527`
- `RAN/Admin/ProviderSettingsPresenter.php:105,778,1147`
- `RAN/RepositoryProvider/ProviderRegistry.php:291`
- `RAN/RepositoryProvider/RepositoryProvider.php:9`
- `docs/custom-git-vendors.md:103`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1591,1767,1768,1799,1800,1845,1892,1893,1924`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:530`
- `tests/Admin/AdminTabRegistryTest.php:94,130`
- `tests/Admin/CredentialProfileInteractionDispatcherTest.php:1040`
- `tests/Admin/CredentialValidationProvider.php:23`
- `tests/Admin/DashboardIndexRoutingTest.php:1566,2700,2927,3000,3079`
- `tests/Admin/PackageAdminControllerDispatcherTest.php:432,472`
- `tests/Admin/PackageDeploymentPolicyRequestResolverTest.php:27,76`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:212,384,424`
- `tests/Admin/PublicLookupProfileHtmxDispatcherTest.php:175`
- `tests/Admin/ReleaseManagement/ReleaseWorkflowControlsTest.php:762,767,780`
- `tests/Admin/ReleaseManagement/ReleaseWorkflowRequestControllerTest.php:45,385`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:26`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:44`
- `tests/Admin/RepositoryPickerControllerTest.php:308,399,434,472,512,555,579`
- `tests/Admin/Support/ExpiryReminderProvider.php:18`
- `tests/Deployment/AdmittedBranchExecutionTest.php:308`
- `tests/Deployment/AdmittedBranchHostAdapterParityTest.php:669`
- `tests/Deployment/WebhookV1ExecutionBoundaryTest.php:166`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:174`
- `tests/Portability/TemporaryCredentialProvider.php:39`
- `tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php:68`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:62,63,99`
- `tests/RepositoryProvider/GitHubExternalExtensionParityTest.php:207`
- `tests/RepositoryProvider/ProviderCapabilityContractTest.php:94`
- `tests/RepositoryProvider/ProviderContractsTest.php:383,440,448,518`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:162,192,240,728`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:122,158,289,320,694,736,803`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:40`
- `tests/RepositoryProvider/Support/SuppliesProviderManualCapabilities.php:19`
- `tests/Support/WebhookManagementCapabilityProviders.php:40,129`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:363`
- `tests/Webhook/WebhookControllerTest.php:220`
- `tests/Webhook/WebhookProcessorTest.php:52,541`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1966,3004`
- `tests/WordPress/RuntimeReleaseProvider.php:56`
- `tests/WordPress/github-provider-installed-readback.php:31`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:58`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:43`

## getProvider

- `RAN/Admin/AdminTab.php:95`
- `RAN/Dashboard.php:236`
- `RAN/RepositoryProvider/ProviderCredentialPolicy.php:9`
- `RAN/RepositoryProvider/ProviderSecretPolicyCatalog.php:28,35`
- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:9`
- `RAN/RepositoryProvider/SignedWebhookVerification.php:23`
- `RAN/RepositoryProvider/WebhookRequest.php:95,106`
- `RAN/Webhook/SignedWebhookVerifier.php:26,62`
- `tests/Admin/AdminTabRegistryTest.php:43`
- `tests/Admin/CredentialProfileInteractionDispatcherTest.php:1021`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:286`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:196`
- `tests/Portability/TemporaryCredentialProvider.php:85`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:185,215,655,679`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:501,680,773,835,854`
- `tests/RepositoryProvider/Support/ExternalFixtureCredentialPolicy.php:16`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:18`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:27,42`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1022,1077`
- `tests/Secrets/WebhookProfileStorageTest.php:232`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:397`
- `tests/WordPress/github-provider-installed-readback.php:75,77`
- `tests/fixtures/ran-booster-fixture-provider/src/CredentialPolicy.php:15`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:107`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:13,55`

## getProviderDiagnostics

- `RAN/RepositoryProvider/ProviderRegistry.php:157`
- `RAN/RepositoryProvider/RepositoryProvider.php:11`
- `RAN/Troubleshooting/TroubleshootingService.php:130`
- `docs/custom-git-vendors.md:104`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1600,1771,1772,1803,1804,1854,1896,1897,1933`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:534`
- `tests/Admin/PackageDeploymentPolicyRequestResolverTest.php:31,80`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:28`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:46`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:178`
- `tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php:83`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:66,67,216`
- `tests/RepositoryProvider/ProviderContractsTest.php:383`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:148,196,226,246,743`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:135,698,760,824`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:61`
- `tests/RepositoryProvider/Support/SuppliesProviderDiagnostics.php:13`
- `tests/Support/WebhookManagementCapabilityProviders.php:44`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:367`
- `tests/WordPress/fixture-provider-smoke.php:88`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:79`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:47`

## getPublicRepositoryBrowseMetadata

- `RAN/Admin/PackageRepositoryRequestResolver.php:108`
- `RAN/Admin/ProviderProfileAdminController.php:149`
- `RAN/Admin/ProviderSettingsPresenter.php:377,671,707,737,815`
- `RAN/Admin/RepositoryPickerController.php:217`
- `RAN/RepositoryProvider/CredentialedPublicRepositoryBrowser.php:16`
- `tests/Admin/DashboardIndexRoutingTest.php:2946,3019`
- `tests/Admin/PackageAdminControllerDispatcherTest.php:436`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:428`
- `tests/Admin/PublicLookupProfileHtmxDispatcherTest.php:179`
- `tests/Admin/RepositoryPickerControllerTest.php:559`
- `tests/RepositoryProvider/ProviderContractsTest.php:395`

## getRetainedHeaders

- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:14`
- `RAN/Webhook/WebhookProcessor.php:41`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:290`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:199`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:266,272,298`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:685`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:858`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:22`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:44`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1081`
- `tests/Secrets/WebhookProfileStorageTest.php:236`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:401`
- `tests/Webhook/SignedWebhookVerifierTest.php:79,120,157`
- `tests/WordPress/github-provider-installed-readback.php:78`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:17`

## getSignatureHeader

- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:16`
- `RAN/Webhook/SignedWebhookVerifier.php:66`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:294`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:202`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:689`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:862`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:26`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:46`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1085`
- `tests/Secrets/WebhookProfileStorageTest.php:240`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:405`
- `tests/WordPress/github-provider-installed-readback.php:79`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:21`

## getWebhookPolicy

- `RAN/Admin/ProviderProfileAdminController.php:342`
- `RAN/Deployment/DeploymentCoordinator.php:409`
- `RAN/RepositoryProvider/ProviderRegistry.php:188`
- `RAN/RepositoryProvider/WebhookNormalizer.php:11`
- `RAN/Webhook/WebhookProcessor.php:39`
- `docs/provider-extension-contract.md:667`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:542`
- `tests/Admin/CredentialProfileInteractionDispatcherTest.php:1063`
- `tests/Admin/DashboardIndexRoutingTest.php:1569,2721`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:402`
- `tests/Deployment/WebhookV1ExecutionBoundaryTest.php:170`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:194`
- `tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php:85`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:266,272,298`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:755`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:706`
- `tests/Support/WebhookManagementCapabilityProviders.php:128`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:392`
- `tests/Webhook/WebhookControllerTest.php:228`
- `tests/Webhook/WebhookProcessorTest.php:549`
- `tests/WordPress/github-provider-installed-readback.php:74`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:93`

## handoffToCore

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:350`
- `RAN/RepositoryProvider/RepositoryReleaseArtifact.php:11`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1999`
- `tests/RepositoryProvider/ProviderContractsTest.php:251,256`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:262`

## hasRegisteredNativeTarget

- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:937`
- `RAN/RepositoryProvider/RepositoryReleaseNativeTargets.php:10`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1610`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:69`
- `tests/RepositoryProvider/ProviderContractsTest.php:41`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1985`
- `tests/WordPress/RuntimeReleaseProvider.php:82`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:107,210`

## inspectRelease

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:180`
- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:425,1089`
- `RAN/RepositoryProvider/RepositoryReleaseInspector.php:11`
- `assets/ran-booster-release-management.js:1135,1308`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:436,891,1679,1823,1830`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:63`
- `tests/RepositoryProvider/ProviderContractsTest.php:213,215`
- `tests/WordPress/RuntimeReleaseProvider.php:72`
- `tests/assets/release-management-channel-state.test.mjs:219,295`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:155`

## listReleaseCandidates

- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:373,1076`
- `RAN/Internal/ReleaseManagement/ProspectiveReleaseCandidateReader.php:63`
- `RAN/RepositoryProvider/RepositoryReleaseCandidateListing.php:11`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:435,1665,1783,1788,1815,1820`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:60`
- `tests/RepositoryProvider/GitHubExternalExtensionParityTest.php:257,378`
- `tests/RepositoryProvider/ProviderContractsTest.php:119,121`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1978`
- `tests/WordPress/RuntimeReleaseProvider.php:68`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:99,147`

## mainFile

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:207,332`
- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:440,1123`
- `RAN/RepositoryProvider/RepositoryReleaseArtifact.php:17`
- `RAN/RepositoryProvider/RepositoryReleaseInspection.php:21,33`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:2040`
- `tests/RepositoryProvider/ProviderContractsTest.php:251,295`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:249,294,295,299`

## normalizeCredential

- `RAN/RepositoryProvider/ProviderCredentialPolicy.php:15`
- `RAN/Secrets/SecretsFile.php:1172`
- `tests/Portability/TemporaryCredentialProvider.php:89`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:661`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:779,839`
- `tests/RepositoryProvider/Support/ExternalFixtureCredentialPolicy.php:20`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:29`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1026`
- `tests/fixtures/ran-booster-fixture-provider/src/CredentialPolicy.php:19`

## normalizeWebhook

- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:22`
- `RAN/RepositoryProvider/WebhookNormalizer.php:19`
- `RAN/Secrets/SecretsFile.php:1307`
- `RAN/Webhook/WebhookProcessor.php:43`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:550`
- `tests/Admin/CredentialProfileInteractionDispatcherTest.php:1022`
- `tests/Admin/DashboardIndexRoutingTest.php:2729`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:298`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:398`
- `tests/Deployment/WebhookV1ExecutionBoundaryTest.php:176`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:205,230`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:275,293,301`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:693,765`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:719,866`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:30`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:48`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1089`
- `tests/Secrets/WebhookProfileStorageTest.php:244`
- `tests/Support/WebhookManagementCapabilityProviders.php:136`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:409,443`
- `tests/Webhook/WebhookControllerTest.php:224`
- `tests/Webhook/WebhookProcessorTest.php:348,545`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:106`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:25`

## packageRoot

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:205,331,352,353`
- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:200,338,438,452,558,1023,1121`
- `RAN/AddOn/ReleaseTracking/ReleaseTrackingEligibility.php:26,42,59,60`
- `RAN/AddOn/ReleaseTracking/ReleaseTrackingPreflight.php:27,55,88,90`
- `RAN/AddOn/ReleaseTracking/ReleaseTrackingStatus.php:23,46,93,94`
- `RAN/Admin/ReleaseManagement/ReleaseWorkflowProviderProjection.php:20`
- `RAN/RepositoryProvider/RepositoryReleaseArtifact.php:15`
- `RAN/RepositoryProvider/RepositoryReleaseInspection.php:19,31`
- `RAN/RepositoryProvider/RepositoryReleaseNativeTargets.php:20`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowTarget.php:19,32,62,64`
- `RAN/Storage/AbstractPackageRepository.php:546,547`
- `RAN/WordPress/ManagedReleaseConfiguration.php:15,20,24,31,72,73,88`
- `RAN/WordPress/ManagedReleaseStore.php:240`
- `RAN/WordPress/ManagedReleaseTargetRegistrar.php:478`
- `docs/admin-composition-contract.md:159`
- `scripts/build-release.sh:320,325`
- `scripts/verify-runtime-dependencies.php:257,258,264,288`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:779,1620,1625,2036`
- `tests/AddOn/ReleaseTracking/ReleaseTrackingContractTest.php:39`
- `tests/AddOn/ReleaseTracking/ReleaseTrackingEligibilityTest.php:21,44`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:72,73`
- `tests/Integration/phase-4.4-core-disposable-harness.php:357,358,382,387,405`
- `tests/RepositoryProvider/ProviderContractsTest.php:47,251,295`
- `tests/RepositoryProvider/RepositoryReleaseWorkflowInputTest.php:29`
- `tests/RuntimePackagingPolicyNegativeTest.php:150,151,159`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1321,1995,2000,2613`
- `tests/WordPress/ManagedReleaseStoreTest.php:342`
- `tests/WordPress/RuntimeReleaseProvider.php:92,101`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:117,122,220,225,290`

## prepareArchive

- `RAN/Admin/ProviderSettingsPresenter.php:390`
- `RAN/Deployment/AdmittedBranchHostAdapter.php:176`
- `RAN/RepositoryProvider/RepositoryProvider.php:23`
- `docs/custom-git-vendors.md:106,177`
- `docs/provider-extension-contract.md:387`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1659,1779,1780,1811,1812,1878,1904,1905,1957`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:560`
- `tests/Admin/DashboardIndexRoutingTest.php:1571,2950,3023,3093`
- `tests/Admin/PackageAdminControllerDispatcherTest.php:459,490`
- `tests/Admin/PackageDeploymentPolicyRequestResolverTest.php:53,100`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:202,222,394,442`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:35`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:53`
- `tests/Deployment/AdmittedBranchExecutionTest.php:314`
- `tests/Deployment/AdmittedBranchHostAdapterParityTest.php:677`
- `tests/Portability/TemporaryCredentialProvider.php:74`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:74,75,239,244`
- `tests/RepositoryProvider/GitHubArchiveHostIntegrationTest.php:64,100,134,153,184,299,328,362,385,399,507,530`
- `tests/RepositoryProvider/GitHubExternalExtensionParityTest.php:354`
- `tests/RepositoryProvider/ProviderContractsTest.php:383,522,536`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:131`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:98`
- `tests/RepositoryProvider/Support/SuppliesProviderManualCapabilities.php:29`
- `tests/Support/WebhookManagementCapabilityProviders.php:60`
- `tests/WordPress/fixture-provider-smoke.php:65`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:137`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:69`

## releaseDetailsUrl

- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php:203`
- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php:443,1133,1262`
- `RAN/RepositoryProvider/RepositoryReleaseMetadata.php:12`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:881,1737,1837,1838`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:58`
- `tests/RepositoryProvider/ProviderContractsTest.php:361,373`
- `tests/WordPress/ManagedReleaseRuntimeTest.php:1974,3012`
- `tests/WordPress/RuntimeReleaseProvider.php:64`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:95,143`

## repositoryPathExists

- `RAN/Admin/ProviderSettingsPresenter.php:401`
- `RAN/RepositoryProvider/RepositoryPathInspector.php:12`
- `tests/Admin/DashboardIndexRoutingTest.php:2983`

## repositoryTargetMatches

- `RAN/Admin/ManagedPackageWebhookAuthorityResolver.php:36`
- `RAN/Deployment/DeploymentCoordinator.php:419`
- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:41`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:318`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:220`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:713`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:882`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:46`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:62`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1132`
- `tests/Secrets/WebhookProfileStorageTest.php:264`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:431`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:62,71`

## repositoryWebhookSettingsUrl

- `RAN/Admin/ProviderSettingsPresenter.php:1160`
- `RAN/Admin/WebhookManagement/RepositoryWebhookManagementControls.php:319`
- `RAN/RepositoryProvider/RepositoryWebhookSettingsLink.php:14`
- `tests/Admin/DashboardIndexRoutingTest.php:2734`
- `tests/Support/WebhookManagementCapabilityProviders.php:142`

## resolveRepository

- `RAN/Admin/PackageRepositoryRequestResolver.php:117`
- `RAN/Portability/BlueprintRepositoryVerifier.php:47,200`
- `RAN/RepositoryProvider/RepositoryProvider.php:13`
- `docs/custom-git-vendors.md:105,171`
- `docs/portability-briefcase.md:140`
- `docs/provider-extension-contract.md:343`
- `tests/AddOn/NativeProspectiveReleaseFacadeTest.php:1642,1775,1776,1807,1808,1864,1900,1901,1943`
- `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php:556`
- `tests/Admin/DashboardIndexRoutingTest.php:1570,2936,3009,3088`
- `tests/Admin/PackageAdminControllerDispatcherTest.php:445,476`
- `tests/Admin/PackageDeploymentPolicyRequestResolverTest.php:39,88`
- `tests/Admin/PackageRepositoryRequestResolverTest.php:201,216,388,436`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:33`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:51`
- `tests/Deployment/AdmittedBranchExecutionTest.php:311`
- `tests/Deployment/AdmittedBranchHostAdapterParityTest.php:673`
- `tests/Portability/TemporaryCredentialProvider.php:49`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:70,71`
- `tests/RepositoryProvider/ProviderContractsTest.php:383,446`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:79`
- `tests/RepositoryProvider/Support/SuppliesProviderManualCapabilities.php:17`
- `tests/Support/WebhookManagementCapabilityProviders.php:54`
- `tests/WordPress/fixture-provider-smoke.php:51`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:118`
- `tests/fixtures/ran-booster-release-capability-provider/src/Providers.php:57`

## resolvedRef

- `RAN/Deployment/DeploymentAttemptRepository.php:367,371,373`
- `RAN/Deployment/PreparedArtifact.php:21,35,37,39,64,87,89,119,133`
- `RAN/Deployment/ReleaseArtifactCustodian.php:25`
- `RAN/RepositoryProvider/AuthenticatedPreparedArchive.php:28,58`
- `RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php:20`
- `tests/Admin/UnavailableProviderPackageViewTest.php:629,652`
- `tests/Deployment/AdmittedBranchExecutionParityTest.php:233`
- `tests/Deployment/AdmittedBranchExecutionTest.php:288,296,398`
- `tests/Deployment/AdmittedBranchHostAdapterParityTest.php:637,644`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:240,241,242,244`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:133,134,135`
- `tests/RepositoryProvider/Support/ExternalFixturePreparedArchive.php:14,24`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:102,108,115,116,123,124`
- `tests/fixtures/ran-booster-fixture-provider/src/PreparedArchive.php:14,24`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:141,145,151,159,160,167,168`

## validateCredential

- `RAN/Admin/ProviderProfileAdminController.php:91`
- `RAN/RepositoryProvider/CredentialValidator.php:11`
- `docs/canonical-secret-policy-characterization.md:112`
- `tests/Admin/CredentialValidationProvider.php:32`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:85`
- `tests/RepositoryProvider/ExternalFixturePluginTest.php:172`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:96`
- `tests/RepositoryProvider/Support/ExternalFixtureProvider.php:69,81`
- `tests/fixtures/ran-booster-fixture-provider/src/Client.php:43`
- `tests/fixtures/ran-booster-fixture-provider/src/Diagnostics.php:37`
- `tests/fixtures/ran-booster-fixture-provider/src/Provider.php:87,88,120,236`

## validateSubmittedCredential

- `RAN/RepositoryProvider/SubmittedCredentialValidator.php:19`
- `RAN/Secrets/SecretsFile.php:1194,1743`
- `docs/provider-extension-contract.md:639`
- `tests/fixtures/ran-booster-fixture-provider/src/CredentialPolicy.php:47`

## webhookFromConstants

- `RAN/RepositoryProvider/ProviderWebhookPolicy.php:33`
- `RAN/Secrets/SecretsFile.php:1086`
- `docs/canonical-secret-policy-characterization.md:213`
- `tests/Admin/ManagedPackageWebhookAuthorityResolverTest.php:306`
- `tests/Logging/GitHubDiagnosticsLoggingTest.php:212`
- `tests/RepositoryProvider/ProviderDiagnosticsContractTest.php:701`
- `tests/RepositoryProvider/ProviderSecretPolicyContractTest.php:874`
- `tests/RepositoryProvider/Support/InertWebhookPolicy.php:38`
- `tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php:58`
- `tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php:1111`
- `tests/Secrets/WebhookProfileStorageTest.php:165,252`
- `tests/Troubleshooting/TroubleshootingServiceTest.php:423`
- `tests/fixtures/ran-booster-fixture-provider/src/WebhookPolicy.php:48`

## workflowInspect

- `RAN/Admin/ReleaseManagement/ReleaseWorkflowRequestController.php:163`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:22`
- `docs/provider-extension-contract.md:193`
- `docs/provider-release-workflow-api.md:44`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:43`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:99`
- `tests/RepositoryProvider/RepositoryReleaseWorkflowCompatibilityTest.php:47`

## workflowOutcome

- `RAN/Admin/ReleaseManagement/ReleaseWorkflowRequestController.php:165`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:26`
- `docs/provider-extension-contract.md:194`
- `docs/provider-release-workflow-api.md:44`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:49`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:112`

## workflowPreview

- `RAN/Admin/ReleaseManagement/ReleaseWorkflowPresenter.php:624`
- `RAN/Admin/ReleaseManagement/ReleaseWorkflowRequestController.php:140`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:20`
- `docs/characterization/release-workflow-controls-separation-baseline.md:94`
- `docs/provider-extension-contract.md:193`
- `docs/provider-release-workflow-api.md:43`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:40`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:90`

## workflowSetup

- `RAN/Admin/ReleaseManagement/ReleaseWorkflowRequestController.php:164`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:24`
- `docs/provider-extension-contract.md:194`
- `docs/provider-release-workflow-api.md:44`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:46`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:102`

## workflowStatus

- `RAN/Admin/ReleaseManagement/ReleaseWorkflowPresenter.php:705,823`
- `RAN/Admin/ReleaseManagement/ReleaseWorkflowRequestController.php:386`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowManagementV3.php:18`
- `docs/characterization/release-workflow-controls-separation-baseline.md:95`
- `docs/provider-extension-contract.md:192`
- `docs/provider-release-workflow-api.md:43`
- `tests/Admin/ReleaseManagement/Support/PartialRepositoryReleaseWorkflowProviderDouble.php:38`
- `tests/Admin/ReleaseManagement/Support/RepositoryReleaseWorkflowProviderDouble.php:75`
- `tests/RepositoryProvider/GitHubExternalExtensionParityTest.php:74,82,93,164`
- `tests/RepositoryProvider/RepositoryReleaseWorkflowCompatibilityTest.php:46`



# Bitbucket connected consumer handoff

Fresh default branch `RocketsAreNostalgic/ran-booster-bitbucket` at `e3bbadca96587d07f655df89bc9eda1515c6dd20`. Read-only inventory; no file reservations or changes.

Bitbucket remains explicitly Provider API 11 / Add-on API 16, certified against Core beta.29 tag target `ffc11fc8e40618624a785b7fca5193029c6d492e`, archive source `ff35be100a9f5c6cd77a84bcfc3734227106b0b8`. API 12/workflow V3 candidate coordination is a separate prerequisite; do not treat a renamed implementation against current Core as certified adoption. It deliberately does not implement webhook fitness/management or release workflow capabilities.

## Production implementation declarations

All paths relative to Bitbucket. Existing owned-method exceptions explicitly defer these names to Core #167. `BitbucketCredentialValidator::validateCredential` has additional optional `$timeout` and `$response_size` parameters beyond Core's `$credentialId`; preserve those caller contracts while agreeing `$credential_id`.

### src/Bitbucket/BitbucketCredentialPolicy.php

- Interface set: `ProviderCredentialPolicy` (line 11).
- `17`: `public function getProvider(): ProviderCode`
- `21`: `public function normalizeCredential( array $metadata, mixed $secret ): array`
- `63`: `public function getConstantNames(): array`
- `67`: `public function credentialFromConstants( array $constants ): ?array`

### src/Bitbucket/BitbucketCredentialValidator.php

- Interface set: `CredentialValidator` (line 10).
- `20`: `public function validateCredential( string $credentialId, float|int $timeout = 15, int $response_size = 262144 ): CredentialValidationResult`

### src/Bitbucket/BitbucketDiagnostics.php

- Interface set: `ProviderDiagnostics` (line 13).
- `21`: `public function diagnose( ProviderDiagnosticRequest $request ): array`

### src/Bitbucket/BitbucketProvider.php

- Interface set: `RepositoryProvider, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizer, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink` (line 37).
- `134`: `public function getMetadata(): ProviderMetadata`
- `138`: `public function getProviderDiagnostics(): ProviderDiagnostics`
- `142`: `public function getCredentialPolicy(): ProviderCredentialPolicy`
- `146`: `public function getWebhookPolicy(): ProviderWebhookPolicy`
- `150`: `public function diagnoseWebhookReadiness(): ProviderDiagnosticResult`
- `154`: `public function validateCredential( string $credentialId ): CredentialValidationResult`
- `158`: `public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult`
- `162`: `public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata`
- `166`: `public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor`
- `174`: `public function prepareArchive( ArchiveRequest $request ): PreparedArchive`
- `178`: `public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope`
- `182`: `public function repositoryWebhookSettingsUrl( string $locator ): string`

### src/Bitbucket/BitbucketWebhookNormalizer.php

- Interface set: `WebhookNormalizer` (line 21).
- `35`: `public function getWebhookPolicy(): ProviderWebhookPolicy`
- `39`: `public function diagnoseWebhookReadiness(): ProviderDiagnosticResult`
- `94`: `public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope`

### src/Bitbucket/BitbucketWebhookPolicy.php

- Interface set: `ProviderWebhookPolicy` (line 12).
- `14`: `public function getProvider(): ProviderCode`
- `18`: `public function getRetainedHeaders(): array`
- `22`: `public function getSignatureHeader(): string`
- `26`: `public function normalizeWebhook( array $metadata, mixed $secret ): array`
- `61`: `public function getConstantNames(): array`
- `65`: `public function webhookFromConstants( array $constants ): ?array`
- `69`: `public function authorizeWebhook(`
- `95`: `public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool`

## Exact consumer / test / documentation references

Lexical matching includes same-spelling methods; inspect receiver before editing.

### authorizeWebhook

- `src/Bitbucket/BitbucketWebhookNormalizer.php:112`
- `src/Bitbucket/BitbucketWebhookPolicy.php:69`

### browseRepositories

- `src/Bitbucket/BitbucketProvider.php:158`

### credentialFromConstants

- `src/Bitbucket/BitbucketCredentialPolicy.php:67`

### diagnoseWebhookReadiness

- `src/Bitbucket/BitbucketProvider.php:150,151`
- `src/Bitbucket/BitbucketWebhookNormalizer.php:39`
- `tests/RepositoryProvider/BitbucketWebhookDeliveryEvidenceTest.php:23,36,53`
- `tests/RepositoryProvider/BitbucketWebhookNormalizerTest.php:580,587,608`
- `tests/WordPress/bitbucket-installed-smoke.php:134`

### getConstantNames

- `src/Bitbucket/BitbucketCredentialPolicy.php:63`
- `src/Bitbucket/BitbucketWebhookPolicy.php:61`
- `tests/RepositoryProvider/BitbucketWebhookPolicyTest.php:75`

### getCredentialPolicy

- `src/Bitbucket/BitbucketProvider.php:142`

### getMetadata

- `src/Bitbucket/BitbucketProvider.php:134`
- `tests/WordPress/bitbucket-installed-smoke.php:121`
- `tests/fixtures/plugin-lifecycle.php:237`

### getProvider

- `src/Bitbucket/BitbucketCredentialPolicy.php:17`
- `src/Bitbucket/BitbucketWebhookNormalizer.php:95`
- `src/Bitbucket/BitbucketWebhookPolicy.php:14,74`

### getProviderDiagnostics

- `src/Bitbucket/BitbucketProvider.php:138`
- `tests/RepositoryProvider/BitbucketDiagnosticsBoundaryTest.php:54`

### getPublicRepositoryBrowseMetadata

- `src/Bitbucket/BitbucketProvider.php:162`
- `tests/RepositoryProvider/BitbucketRepositoryBrowserTest.php:79`

### getRetainedHeaders

- `src/Bitbucket/BitbucketWebhookPolicy.php:18`

### getSignatureHeader

- `src/Bitbucket/BitbucketWebhookPolicy.php:22`

### getWebhookPolicy

- `src/Bitbucket/BitbucketProvider.php:146,147`
- `src/Bitbucket/BitbucketWebhookNormalizer.php:35`

### normalizeCredential

- `src/Bitbucket/BitbucketCredentialPolicy.php:21,84`

### normalizeWebhook

- `src/Bitbucket/BitbucketProvider.php:178,179`
- `src/Bitbucket/BitbucketWebhookNormalizer.php:94`
- `src/Bitbucket/BitbucketWebhookPolicy.php:26`
- `tests/RepositoryProvider/BitbucketWebhookNormalizerTest.php:55,86,107,117,134,157,176,190,208,224,238,268,299,317,325,335,409,497,518,529,548,569,573`
- `tests/RepositoryProvider/BitbucketWebhookPolicyTest.php:37,61`

### prepareArchive

- `CONTRIBUTING.md:89`
- `src/Bitbucket/BitbucketProvider.php:174`

### repositoryTargetMatches

- `src/Bitbucket/BitbucketWebhookPolicy.php:95`

### repositoryWebhookSettingsUrl

- `src/Bitbucket/BitbucketProvider.php:182`

### resolveRepository

- `src/Bitbucket/BitbucketProvider.php:166`
- `tests/WordPress/bitbucket-installed-smoke.php:197`
- `tests/fixtures/plugin-lifecycle.php:250`

### validateCredential

- `src/Bitbucket/BitbucketCredentialValidator.php:20`
- `src/Bitbucket/BitbucketDiagnostics.php:40`
- `src/Bitbucket/BitbucketProvider.php:154,155`
- `tests/RepositoryProvider/BitbucketCredentialValidatorTest.php:47,74,87,88,110,133,155,201,232,255`

### webhookFromConstants

- `src/Bitbucket/BitbucketWebhookPolicy.php:65`
- `tests/RepositoryProvider/BitbucketWebhookPolicyTest.php:77`

## Remaining gates

Core #167 and Bitbucket's owner must agree mapping, public API generation, parameters and release/integration order. Requalify exact new Core plus Bitbucket tuple using its independent `composer check`, exact host `composer check:host`, candidate contract and archive/installed proofs. Preserve credentials, webhook semantics and existing unsupported capabilities. No publication/adoption claim is made. Destination: Provider #25 documentation/PR and Core #167 coordinated contract cohort.


## Certified-host comparison

The 23 inventoried interface declaration files are byte-identical between certified
Core `18b0ec619174000a9a9dbc27b9d68b44b0265449` and current
`a8b635a8c9a40482ec5f125023f54e43f88bce58`. Other DTO/helper/registry source
changed, so both exact hosts were checked independently. This does not certify
a released or installed composition. Historical Workbench proof doubles require
separate disposition before reuse; no fixture or retired add-on migration is
authorized merely by this lexical inventory.
