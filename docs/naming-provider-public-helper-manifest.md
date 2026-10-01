# Proposed Provider-owned public-helper mapping

Provider source: `7f6a064664b4730d81eeb2cdaff7e5aaa8244e8c`. Read-only preparation for Provider #25; destination is this documentation-only Provider PR. The documentation claim is recorded below; no runtime claim, Core agreement, implementation, merge or release is recorded here.

## Scope and compatibility gate

The proposed cut comprises **52 public method declarations**, **64 camelCase public-parameter occurrences**, **three promoted properties**, and **four reserved public parameters**. Method and camelCase parameter mappings are direct snake_case. `$private` is proposed as `$is_private`. Public parameter renames may break named arguments even where the method name already conforms. Methods are public package APIs, so absence of a local caller is not permission to assume no external consumers.

The 50 Core-interface implementation declaration occurrences are excluded. In particular, do not globally replace `validateCredential`, `assessSetup`, `assessCheck`, `assessReconfigure`, `assessRemove`, or `assessTest`: `GitHubProvider` implements Core methods with those names while delegating to distinct Provider-owned helpers. Preserve Core DTO members with colliding spellings such as `repositoryId` or `failureHistory`. All payload/JSON/persisted/template keys and runtime protocol semantics remain unchanged.

## Exact method declarations (52)

| Declaration | Existing method | Proposed method |
|---|---|---|
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:48` | `GitHubRepositoryClient::branchRef` | `branch_ref` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:168` | `GitHubRepositoryClient::gitCommit` | `git_commit` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:194` | `GitHubRepositoryClient::createBlob` | `create_blob` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:213` | `GitHubRepositoryClient::createTree` | `create_tree` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:249` | `GitHubRepositoryClient::createCommit` | `create_commit` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:271` | `GitHubRepositoryClient::createRef` | `create_ref` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:288` | `GitHubRepositoryClient::pullRequests` | `pull_requests` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:314` | `GitHubRepositoryClient::pullRequest` | `pull_request` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:325` | `GitHubRepositoryClient::pullRequestFileSet` | `pull_request_file_set` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:357` | `GitHubRepositoryClient::createDraftPullRequest` | `create_draft_pull_request` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:171` | `InitialReleaseBundle::packVersion` | `pack_version` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:177` | `InitialReleaseBundle::packIdentity` | `pack_identity` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:182` | `InitialReleaseBundle::manifestHash` | `manifest_hash` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:191` | `InitialReleaseBundle::changedPathHash` | `changed_path_hash` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:196` | `InitialReleaseBundle::allowlistHash` | `allowlist_hash` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:207` | `InitialReleaseBundle::expectedPullFiles` | `expected_pull_files` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:74` | `RepositorySnapshot::blobPrefix` | `blob_prefix` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:79` | `RepositorySnapshot::repositoryId` | `repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:87` | `RepositorySnapshot::defaultBranch` | `default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:106` | `RepositorySnapshot::documentPaths` | `document_paths` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:113` | `RepositorySnapshot::inspectedBlobCount` | `inspected_blob_count` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:107` | `SetupRecordStore::releaseClaim` | `release_claim` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:172` | `SetupRecordStore::refreshSourceRevision` | `refresh_source_revision` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:256` | `SetupRecordStore::saveAssessmentObservation` | `save_assessment_observation` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:304` | `SetupRecordStore::assessmentObservation` | `assessment_observation` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:325` | `SetupRecordStore::recordFailure` | `record_failure` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:369` | `SetupRecordStore::failureHistory` | `failure_history` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:102` | `SourceReadyAssessment::readyForBootstrap` | `ready_for_bootstrap` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:106` | `SourceReadyAssessment::phpVersion` | `php_version` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:116` | `SourceReadyAssessment::packageSlug` | `package_slug` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:120` | `SourceReadyAssessment::headerPath` | `header_path` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:128` | `SourceReadyAssessment::releaseFiles` | `release_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:133` | `SourceReadyAssessment::modifiedFiles` | `modified_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:138` | `SourceReadyAssessment::extraFiles` | `extra_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:107` | `SourceReadyAssessor::potentialRuntimeBlob` | `potential_runtime_blob` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:326` | `SourceReadyAssessor::hasCompetingReleaseAutomation` | `has_competing_release_automation` |
| `src/ReleaseDeployments/WorkflowAssistance/TemplatePack.php:93` | `TemplatePack::fromArchive` | `from_archive` |
| `src/ReleaseDeployments/WorkflowAssistance/TemplatePack.php:156` | `TemplatePack::packVersion` | `pack_version` |
| `src/ReleaseDeployments/WorkflowAssistance/TemplatePack.php:161` | `TemplatePack::manifestHash` | `manifest_hash` |
| `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:119` | `WorkflowApplicationCoordinator::hasCurrentRecord` | `has_current_record` |
| `src/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceState.php:15` | `WorkflowAssistanceState::claimLockName` | `claim_lock_name` |
| `src/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceState.php:25` | `WorkflowAssistanceState::removeDurableState` | `remove_durable_state` |
| `src/RepositoryBrowser.php:144` | `RepositoryBrowser::branchHead` | `branch_head` |
| `src/RepositoryBrowser.php:174` | `RepositoryBrowser::immutableRef` | `immutable_ref` |
| `src/RepositoryBrowser.php:255` | `RepositoryBrowser::currentBranchHead` | `current_branch_head` |
| `src/RepositoryBrowser.php:340` | `RepositoryBrowser::pathExists` | `path_exists` |
| `src/RepositoryBrowser.php:400` | `RepositoryBrowser::validateCredential` | `validate_credential` |
| `src/RepositoryWebhookClient.php:16` | `RepositoryWebhookClient::assessSetup` | `assess_setup` |
| `src/RepositoryWebhookClient.php:21` | `RepositoryWebhookClient::assessCheck` | `assess_check` |
| `src/RepositoryWebhookClient.php:26` | `RepositoryWebhookClient::assessReconfigure` | `assess_reconfigure` |
| `src/RepositoryWebhookClient.php:31` | `RepositoryWebhookClient::assessRemove` | `assess_remove` |
| `src/RepositoryWebhookClient.php:36` | `RepositoryWebhookClient::assessTest` | `assess_test` |

## Exact camelCase public parameter occurrences (64)

Rows intentionally include already-compliant method names, factories and constructors. Each row is one parameter occurrence.

| Declaration | Method | Existing parameter | Proposed parameter |
|---|---|---|---|
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:65` | `GitHubRepositoryClient::snapshot` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:67` | `GitHubRepositoryClient::snapshot` | `$defaultBranch` | `$default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:213` | `GitHubRepositoryClient::createTree` | `$baseTreeSha` | `$base_tree_sha` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:249` | `GitHubRepositoryClient::createCommit` | `$treeSha` | `$tree_sha` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:249` | `GitHubRepositoryClient::createCommit` | `$parentSha` | `$parent_sha` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:271` | `GitHubRepositoryClient::createRef` | `$defaultBranch` | `$default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:357` | `GitHubRepositoryClient::createDraftPullRequest` | `$defaultBranch` | `$default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:87` | `GitHubRepositoryReleaseWorkflow::inspect` | `$credentialId` | `$credential_id` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:100` | `GitHubRepositoryReleaseWorkflow::setup` | `$credentialId` | `$credential_id` |
| `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:112` | `GitHubRepositoryReleaseWorkflow::outcome` | `$credentialId` | `$credential_id` |
| `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:66` | `InitialReleaseBundle::bootstrap` | `$updateUri` | `$update_uri` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:24` | `RepositorySnapshot::__construct` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:27` | `RepositorySnapshot::__construct` | `$defaultBranch` | `$default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:32` | `RepositorySnapshot::__construct` | `$blobPrefixes` | `$blob_prefixes` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:57` | `SetupRecordStore::find` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:69` | `SetupRecordStore::occupied` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:80` | `SetupRecordStore::claim` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:107` | `SetupRecordStore::releaseClaim` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:172` | `SetupRecordStore::refreshSourceRevision` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:304` | `SetupRecordStore::assessmentObservation` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:304` | `SetupRecordStore::assessmentObservation` | `$sourceRevision` | `$source_revision` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:369` | `SetupRecordStore::failureHistory` | `$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:369` | `SetupRecordStore::failureHistory` | `$sourceRevision` | `$source_revision` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:82` | `SourceReadyAssessment::ready` | `$packageSlug` | `$package_slug` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:84` | `SourceReadyAssessment::ready` | `$headerPath` | `$header_path` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:87` | `SourceReadyAssessment::ready` | `$releaseFiles` | `$release_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:89` | `SourceReadyAssessment::ready` | `$modifiedFiles` | `$modified_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:91` | `SourceReadyAssessment::ready` | `$extraFiles` | `$extra_files` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessment.php:93` | `SourceReadyAssessment::ready` | `$phpVersion` | `$php_version` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:119` | `SourceReadyAssessor::assess` | `$packageSlug` | `$package_slug` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:121` | `SourceReadyAssessor::assess` | `$installedVersion` | `$installed_version` |
| `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:123` | `SourceReadyAssessor::assess` | `$expectedUpdateUri` | `$expected_update_uri` |
| `src/ReleaseDeployments/WorkflowAssistance/StarterSecurityCheck.php:26` | `StarterSecurityCheck::check` | `$originBytes` | `$origin_bytes` |
| `src/ReleaseDeployments/WorkflowAssistance/TemplatePack.php:177` | `TemplatePack::render` | `$logicalId` | `$logical_id` |
| `src/ReleaseDeployments/WorkflowAssistance/TemplatePackRepositoryClient.php:81` | `TemplatePackRepositoryClient::exact` | `$expectedIdentity` | `$expected_identity` |
| `src/RepositoryBrowser.php:52` | `RepositoryBrowser::repository` | `$fullName` | `$full_name` |
| `src/RepositoryBrowser.php:54` | `RepositoryBrowser::repository` | `$credentialId` | `$credential_id` |
| `src/RepositoryBrowser.php:57` | `RepositoryBrowser::repository` | `$responseSize` | `$response_size` |
| `src/RepositoryBrowser.php:59` | `RepositoryBrowser::repository` | `$authenticateDefault` | `$authenticate_default` |
| `src/RepositoryBrowser.php:146` | `RepositoryBrowser::branchHead` | `$fullName` | `$full_name` |
| `src/RepositoryBrowser.php:149` | `RepositoryBrowser::branchHead` | `$expectedRepositoryId` | `$expected_repository_id` |
| `src/RepositoryBrowser.php:151` | `RepositoryBrowser::branchHead` | `$credentialId` | `$credential_id` |
| `src/RepositoryBrowser.php:176` | `RepositoryBrowser::immutableRef` | `$fullName` | `$full_name` |
| `src/RepositoryBrowser.php:179` | `RepositoryBrowser::immutableRef` | `$expectedRepositoryId` | `$expected_repository_id` |
| `src/RepositoryBrowser.php:181` | `RepositoryBrowser::immutableRef` | `$credentialId` | `$credential_id` |
| `src/RepositoryBrowser.php:257` | `RepositoryBrowser::currentBranchHead` | `$fullName` | `$full_name` |
| `src/RepositoryBrowser.php:260` | `RepositoryBrowser::currentBranchHead` | `$credentialId` | `$credential_id` |
| `src/RepositoryBrowser.php:340` | `RepositoryBrowser::pathExists` | `$fullName` | `$full_name` |
| `src/RepositoryBrowser.php:340` | `RepositoryBrowser::pathExists` | `$credentialId` | `$credential_id` |
| `src/RepositoryBrowser.php:400` | `RepositoryBrowser::validateCredential` | `$credentialId` | `$credential_id` |
| `src/RepositoryWebhookClient.php:16` | `RepositoryWebhookClient::assessSetup` | `$repositoryId` | `$repository_id` |
| `src/RepositoryWebhookClient.php:21` | `RepositoryWebhookClient::assessCheck` | `$repositoryId` | `$repository_id` |
| `src/RepositoryWebhookClient.php:26` | `RepositoryWebhookClient::assessReconfigure` | `$repositoryId` | `$repository_id` |
| `src/RepositoryWebhookClient.php:31` | `RepositoryWebhookClient::assessRemove` | `$repositoryId` | `$repository_id` |
| `src/RepositoryWebhookClient.php:36` | `RepositoryWebhookClient::assessTest` | `$repositoryId` | `$repository_id` |
| `src/RepositoryWebhookClient.php:41` | `RepositoryWebhookClient::setup` | `$callbackUrl` | `$callback_url` |
| `src/RepositoryWebhookClient.php:97` | `RepositoryWebhookClient::check` | `$hookId` | `$hook_id` |
| `src/RepositoryWebhookClient.php:97` | `RepositoryWebhookClient::check` | `$callbackUrl` | `$callback_url` |
| `src/RepositoryWebhookClient.php:127` | `RepositoryWebhookClient::test` | `$hookId` | `$hook_id` |
| `src/RepositoryWebhookClient.php:127` | `RepositoryWebhookClient::test` | `$callbackUrl` | `$callback_url` |
| `src/RepositoryWebhookClient.php:161` | `RepositoryWebhookClient::reconfigure` | `$hookId` | `$hook_id` |
| `src/RepositoryWebhookClient.php:161` | `RepositoryWebhookClient::reconfigure` | `$callbackUrl` | `$callback_url` |
| `src/RepositoryWebhookClient.php:197` | `RepositoryWebhookClient::remove` | `$hookId` | `$hook_id` |
| `src/RepositoryWebhookClient.php:197` | `RepositoryWebhookClient::remove` | `$callbackUrl` | `$callback_url` |

## Private properties promoted by a public constructor (3; already included in the 64 parameters)

| Declaration | Existing property | Proposed property |
|---|---|---|
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:24` | `RepositorySnapshot::$repositoryId` | `$repository_id` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:27` | `RepositorySnapshot::$defaultBranch` | `$default_branch` |
| `src/ReleaseDeployments/WorkflowAssistance/RepositorySnapshot.php:32` | `RepositorySnapshot::$blobPrefixes` | `$blob_prefixes` |

Update corresponding `$this` property references and any named constructor arguments together. These three promotions are the only properties in this helper cohort still requiring this change; do not double-count them as additional parameter occurrences.

## Reserved public parameters (4; additional to the 64)

| Declaration | Method | Existing parameter | Proposed parameter |
|---|---|---|---|
| `src/RepositoryBrowser.php:153` | `RepositoryBrowser::branchHead` | `$private` | `$is_private` |
| `src/RepositoryBrowser.php:183` | `RepositoryBrowser::immutableRef` | `$private` | `$is_private` |
| `src/RepositoryBrowser.php:262` | `RepositoryBrowser::currentBranchHead` | `$private` | `$is_private` |
| `src/RepositoryBrowser.php:340` | `RepositoryBrowser::pathExists` | `$private` | `$is_private` |

## Provider direct-call inventory

The direct `->method(...)` / `::method(...)` occurrences below resolve to the mapped Provider helper classes and should follow their method mapping. Receiver resolution checked the declaring type, constructor or helper-return type, including `$this->browser`, `$this->webhook_client`, workflow collaborators, snapshots and pack/bundle values. The Core-facing `GitHubProvider` declarations are not call sites and remain unchanged. Dynamic test dispatch is handled separately below. This is a bounded local inventory, not proof against dynamically constructed external calls.

### allowlistHash

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:422,442`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:42,63`

### assessCheck

- `src/GitHubProvider.php:697`

### assessReconfigure

- `src/GitHubProvider.php:706`

### assessRemove

- `src/GitHubProvider.php:715`

### assessSetup

- `src/GitHubProvider.php:688`

### assessTest

- `src/GitHubProvider.php:724`

### assessmentObservation

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:25`
- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:300`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:320,321,334,349,357,363,379,380,408,409`

### blobPrefix

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:462`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:244,245,269`

### branchHead

- `src/GitHubProvider.php:319`

### branchRef

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:237,309,315,345,352,357`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:74`

### changedPathHash

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:421,441,476`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:41,62`

### claimLockName

- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:168`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceStateTest.php:65`

### createBlob

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:324`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:383,446`

### createCommit

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:343`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:399`

### createDraftPullRequest

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:287`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:401,449`

### createRef

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:351`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:400,447,448`

### createTree

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:341`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:386`

### currentBranchHead

- `src/GitHubProvider.php:350`
- `src/RepositoryBrowser.php:167`

### defaultBranch

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:138`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:31`

### documentPaths

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:212,301,334`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:186,240`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:25`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/RepositorySnapshotTest.php:22`

### expectedPullFiles

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:297,472`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:74`

### extraFiles

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:75`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:36,41`

### failureHistory

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:26`
- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:364`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:138,171,431,433,445,476,484,512`

### fromArchive

- `src/ReleaseDeployments/WorkflowAssistance/TemplatePackRepositoryClient.php:204`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:20,96,122,131`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:47`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/StarterSecurityCheckTest.php:20`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackApi3ContractTest.php:18,37,46,67,75,86,93,97,103,148,180`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackArchiveContractTest.php:22,49,57,65,69,73,78,83,86,89,95,100,105,122,134,155,163,173,181,189,196,203,211,216,257,282`

### gitCommit

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:238,358`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:75`

### hasCompetingReleaseAutomation

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:150`

### hasCurrentRecord

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:113`

### headerPath

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:100,110`

### immutableRef

- `src/GitHubProvider.php:332`

### inspectedBlobCount

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:145`

### manifestHash

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:159`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:418,440,471`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:52`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackArchiveContractTest.php:31`

### modifiedFiles

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:150`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:38,39,40,99,369`

### packIdentity

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:286,419,442,447`

### packVersion

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:159`
- `src/ReleaseDeployments/WorkflowAssistance/StarterGuidance.php:11`
- `src/ReleaseDeployments/WorkflowAssistance/StarterOrigin.php:28`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:286,417,440,474`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackArchiveContractTest.php:26`

### packageSlug

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:82,92,101,111,122`

### pathExists

- `src/GitHubProvider.php:374`
- `tests/Booster/GitHub/RepositoryResolverTest.php:681,700,703,716,724,735`

### phpVersion

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:123`

### potentialRuntimeBlob

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:110`

### pullRequest

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:95`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:434,445`

### pullRequestFileSet

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:112,295`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:435`

### pullRequests

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:373`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:433`

### readyForBootstrap

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:68`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:214`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:256`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:120`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:33,61,98,130,154,180,249,368`

### recordFailure

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:139`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:137,169,428,434,435,436,437,438,439,442,450,475,483,513`

### refreshSourceRevision

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:134`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:73,76,77,78,253,261`

### releaseClaim

- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:99`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:265`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:112,113,122,142,150,186,206,220,258,353,480`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:589,613`

### releaseFiles

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:131`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:124`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:35,37,100,251`

### removeDurableState

- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceStateTest.php:47,58`

### repositoryId

- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:29`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:128,152,178,197`

### saveAssessmentObservation

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:125`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:319,322,323,324,333,348,356,364,369,378,387,407`

### validateCredential

- `src/Diagnostics.php:38`
- `src/GitHubProvider.php:281`
- `tests/Booster/GitHub/CredentialExpiryValidationTest.php:35,62,82`
- `tests/Booster/GitHub/RepositoryResolverTest.php:657`

## Additional caller inventory for parameter-only signatures

These method names already conform but their parameters are proposed to change. All references are lexical candidates; repeated names on Core interfaces or other classes are explicitly outside automatic replacement. The declarations/parameter table defines the receiver ownership.

### __construct — RepositorySnapshot

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClient.php:139`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:28,145,171`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:118`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/RepositorySnapshotTest.php:53,61,73,81,95`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:128,152,178,197,522`

### assess — SourceReadyAssessor

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:213`
- `src/RepositoryWebhookClient.php:18,23,28,33,38`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:255`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:154`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:119`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:30,31,46,49,64,68,86,109,129,153,179,198,212,217,233,256,257,258,278,290,306,310,330,342,354,374,399`

### bootstrap — InitialReleaseBundle

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:217`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:36,37,99,125,133,149`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:121`

### check — RepositoryWebhookClient, StarterSecurityCheck

- `src/GitHubProvider.php:742`
- `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:83,108`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/StarterSecurityCheckTest.php:83,171`
- `tests/Booster/GitHub/RepositoryWebhookClientTest.php:310,319`

### claim — SetupRecordStore

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:252`
- `tests/Booster/GitHub/ReleaseAcquisitionTest.php:49`
- `tests/Booster/GitHub/ReleaseArtifactClaimLifetimeTest.php:29,370`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:109,111,114,119,147,177,182,190,213,218,248,269,270,271,272,288,341,456`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:587,611`

### exact — TemplatePackRepositoryClient

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:66`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackRepositoryClientTest.php:112,120,139,304`

### find — SetupRecordStore

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:20`
- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:182,187,236`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:125`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:34,36,60,63,79,207,208,254,263,278,286`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:150,290,327,331,347,503,520,538,682`

### inspect — GitHubRepositoryReleaseWorkflow

- `src/GitHubProvider.php:248,546`
- `src/GitHubReleaseArtifact.php:99`
- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:97`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflowTest.php:151,185,190`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:35,45,56,67,72,135,180,192,198,215,222,237,244,257,264,283,301,321,340,360,386,402,416,430,498,512,530,551,579,599,641,655,690`

### occupied — SetupRecordStore

- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:31`
- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:97`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:27`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SetupRecordStoreTest.php:61,100,287,312`

### outcome — GitHubRepositoryReleaseWorkflow

- `src/GitHubProvider.php:260`
- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:119`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflowTest.php:136,152`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:157,159,326,330,390,393,407`

### ready — SourceReadyAssessment

- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:194`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:468,488`

### reconfigure — RepositoryWebhookClient

- `src/GitHubProvider.php:751`
- `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:109`
- `tests/Booster/GitHub/RepositoryWebhookClientTest.php:226,243,262`

### remove — RepositoryWebhookClient

- `src/GitHubProvider.php:760`
- `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:110`
- `tests/Booster/GitHub/RepositoryWebhookClientTest.php:217,298`

### render — TemplatePack

- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:77,85,95,105,117,139,238`
- `src/ReleaseDeployments/WorkflowAssistance/StarterOrigin.php:17`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/ProducerExchangeTest.php:92`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackApi3ContractTest.php:25,27,107,120,133,152,166`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/TemplatePackArchiveContractTest.php:137,218,221,225,235,329`

### repository — RepositoryBrowser

- `src/Diagnostics.php:87`
- `src/GitHubProvider.php:297`
- `src/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundle.php:70`
- `src/ReleaseDeployments/WorkflowAssistance/SetupRecordStore.php:483`
- `src/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessor.php:142`
- `src/ReleaseDeployments/WorkflowAssistance/TemplatePackRepositoryClient.php:40,86`
- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:94,233,308,344`
- `src/RepositoryBrowser.php:159,189`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:73,450,462,463,464,465`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:30`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/RepositorySnapshotTest.php:23`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:128,152,178,197`
- `tests/Booster/GitHub/RepositoryResolverTest.php:82,132,160,185,197,218,241,506`

### setup — GitHubRepositoryReleaseWorkflow, RepositoryWebhookClient

- `src/GitHubProvider.php:254,733`
- `src/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflow.php:109`
- `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:107`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryReleaseWorkflowTest.php:192`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinatorTest.php:74,147,184,193,216,238,258,288,302,322,346,387,403,418,421,499,515,533,552,580,584,605,663,678`
- `tests/Booster/GitHub/RepositoryWebhookClientTest.php:71,103,120,136,156,178,195,205`

### snapshot — GitHubRepositoryClient

- `src/ReleaseDeployments/WorkflowAssistance/WorkflowApplicationCoordinator.php:239,359`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/GitHubRepositoryClientTest.php:76,132,182,236,252,267,296,322,352,357`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/InitialReleaseBundleTest.php:22,98,124,132`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/RepositorySnapshotTest.php:16,33`
- `tests/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/SourceReadyAssessorTest.php:30,31,46,50,64,69,87,109,115,135,162,188,212,218,234,256,257,259,279,291,302,311,331,343,355,375,400`

### test — RepositoryWebhookClient

- `src/GitHubProvider.php:771`
- `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:111`
- `tests/Booster/GitHub/RepositoryWebhookClientTest.php:359,385,416,442,469,479`

## Same-spelling declarations outside the helper classes

`DiagnosticsTest` contains the genuine `RepositoryBrowser` subclass override and must follow its helper signature. Core-interface and fixture contracts below remain unchanged unless independently coordinated.

- `src/GitHubProvider.php:279` — `public function validateCredential( string $credentialId ): CredentialValidationResult {`
- `tests/Booster/GitHub/DiagnosticsTest.php:265` — `public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {`

## Resolved receiver and compatibility instructions

| Location / receiver | Required action |
|---|---|
| `src/GitHubProvider.php` `$this->browser` | Rename its helper calls (`branchHead`, `currentBranchHead`, `immutableRef`, `pathExists`, `validateCredential`); preserve the enclosing Core-facing public method declarations and parameters. |
| `src/GitHubProvider.php` `$this->webhook_client` | Rename its five `assess*` helper calls; preserve the enclosing `GitHubProvider::assess*` Core interface implementations. `setup`/`check`/`reconfigure`/`remove`/`test` method names already conform; migrate only helper-owned parameter declarations, not facade/Core parameters. |
| `src/Diagnostics.php` `$this->browser` | Rename `validateCredential` dispatch to `validate_credential`. |
| `tests/Booster/GitHub/CredentialExpiryValidationTest.php` and `RepositoryResolverTest.php` `RepositoryBrowser` values | Rename direct helper `validateCredential`/`pathExists` calls. These are not `GitHubProvider` Core-interface calls. |
| `tests/Booster/GitHub/DiagnosticsTest.php` anonymous `extends RepositoryBrowser` | Rename override `validateCredential`, its `$credentialId` parameter, and the `repository` override parameters `$fullName`, `$credentialId`, `$responseSize`, `$authenticateDefault` consistently with the parent. Preserve remaining types/defaults and return behavior. |
| `tests/Booster/GitHub/GitHubProviderWebhookManagementTest.php:85,107-111` `$provider` | Retain these Core-facing `check` and management calls and their parameter contract. They do not target `RepositoryWebhookClient`. |
| Workflow call sites in the direct-call index | Rename helper calls to mapped classes. Same-named Core DTO properties are not method calls and remain unchanged. |
| `tests/host-contract.php` contract reflection and Core signatures | Retain every interface method/parameter string; these qualify Core-owned contracts, not the helper surface. |
| `VendorConformanceTest` reflection of `GitHubProvider::create` | Retain the facade factory and its public parameters; the helper cut does not own this factory. |
| `WorkflowApplicationCoordinatorTest.php:82` reflection of `result` | Retain; already compliant private helper unrelated to the public mapping. |

## Literal method-name / callback / reflection inventory

`tests/Booster/GitHub/RepositoryWebhookClientTest.php:21-24` contains four method strings. They dispatch on newly constructed `RepositoryWebhookClient` receivers at lines 32 and 49, so all four strings must migrate:

| Existing string | Proposed string |
|---|---|
| `assessSetup` | `assess_setup` |
| `assessCheck` | `assess_check` |
| `assessReconfigure` | `assess_reconfigure` |
| `assessRemove` | `assess_remove` |

The expected action at line 50 currently derives `strtolower(substr($method, strlen('assess')))`. After the method mapping it must consume the `assess_` prefix, or receive the unchanged action explicitly as separate dataset data. Otherwise the inferred action gains an unwanted leading underscore. Preserve expected status/error codes such as `setup_assessment_unavailable`; this is a test adapter correction, not a runtime protocol change. Dataset labels `setup`, `check`, `reconfigure`, `remove` remain unchanged.

No other exact quoted proposed method names were found in tracked PHP. Existing callback closures, hook names and reflective Core-contract checks remain unchanged.

## Named-argument inventory

No literal named-argument labels matching the 64 camelCase parameters or `$private` were found in tracked PHP at this revision. Positional calls still require full regression qualification. External named-argument users remain a compatibility gate.

## Connected external consumers and remaining gates

The refreshed Core audit below reconciles `WorkflowAssistanceState::removeDurableState` in Core uninstall and the `RepositoryBrowser::validateCredential` diagnostic-test override, including its `repository(...)` override parameters. Core use of `SetupRecordStore::find(...)` retains its already-compliant method name but is positional and needs no edit for the proposed `$repository_id` parameter migration. Do not broaden this into Core-owned interface migration.

Before implementation: independently review this exact manifest, agree Core consumer file ownership, public/named-argument compatibility and landing order. Then qualify the exact combined Provider/Core tuple, independent checks, named-argument/override regression cases, archive/installed proofs where applicable, and final immutable PR heads. Release Updater adoption and UI acceptance remain separate.

## Core consumer audit

### Exact sources and freshness

- Provider mapping baseline: `7f6a064664b4730d81eeb2cdaff7e5aaa8244e8c`.
- Fresh Core `origin/main`: `02859a4f79353bf97af58316e9247102ca185e76`, the landed administration-pipeline #216 cut.
- Previous Core main: `437e04db9e76d39a6b52a72126330eaf0a54a249`.
- Previously reviewed #216 candidate: `e153922db34622f823583b52b9d936fa407af1fb`; its tracked tree has no diff from the new main.
- The two consumer files below are unchanged from previous main and from #216. Core #167 acceptance remains absent; the absence of a file collision is not ownership or compatibility agreement.

The audit covers the proposed 52 Provider-owned public camelCase methods, 64 camelCase parameter positions, three `RepositorySnapshot` constructor promotions and four reserved `$private` parameter positions. It excludes the 50 implementations of Core-owned methods and does not imply an agreed shared API migration.

### Exact required Core changes

| Core file / line at new main | Existing Provider-owned symbol | Proposed change |
|---|---|---|
| `RAN/Uninstall/LocalDataRemover.php:92` | `(new WorkflowAssistanceState())->removeDurableState()` | Call `remove_durable_state()` only when paired to the corresponding Provider candidate. |
| `tests/Logging/GitHubDiagnosticsLoggingTest.php:85-86` | Anonymous `RepositoryBrowser` subclass `validateCredential(string $credentialId, float $timeout = 15.0)` and parameter body reference | Rename to `validate_credential`; rename `$credentialId` to `$credential_id`; preserve timeout/type/default and exception behavior. |
| `tests/Logging/GitHubDiagnosticsLoggingTest.php:90-98` | Same subclass `repository(string $fullName, ?string $credentialId = null, float\|int $timeout = 15, ?int $responseSize = null, bool $authenticateDefault = false)` | Keep method `repository`; rename its four parameters and body references to `$full_name`, `$credential_id`, `$response_size`, `$authenticate_default`. Preserve `$timeout` and all types/defaults. |

The last row is an additional obligation from expanding the method-only cohort to parameter migration. It is in the same existing test file, not a third consumer file. Its role is to preserve override/named-argument consistency with the new parent declaration. Leaving only the old `validateCredential` override silently stops intercepting calls through the renamed parent API.

Exact current file blob IDs:

- `RAN/Uninstall/LocalDataRemover.php`: `0f849b19e7fc3b5ec6da91d59b066fa3b8d3aecc`.
- `tests/Logging/GitHubDiagnosticsLoggingTest.php`: `2dd8129d23d6d45340f13743307428cab3b11897`.

### Additional consumer checks

No additional Core production/test consumer of the proposed methods, named-argument labels, promoted properties or callback/reflection strings was found after resolving matching receiver types.

- Core has no `RepositorySnapshot` or `blobPrefixes` source references. The three Provider-private promotions `$repositoryId`, `$defaultBranch`, `$blobPrefixes` therefore have no observed Core constructor, property, callback or reflection consumer. Their public constructor parameter rename remains a compatibility change for consumers outside the inspected estate.
- Core has no affected `private:` named call to Provider `RepositoryBrowser::{branchHead,immutableRef,currentBranchHead,pathExists}`. Proposed `$private` → `$is_private` does not authorize changing JSON/array `private` keys or Core DTO properties.
- `tests/RepositoryProvider/GitHubAnonymousBrowserHostIntegrationTest.php:30` calls Provider `RepositoryBrowser::repository(...)` positionally and needs no source edit.
- `tests/Runtime/ReleaseManagementCutoverBootstrapTest.php:134` calls `SetupRecordStore::find(...)`, already compliant and outside the method mapping; no edit.
- `tests/Uninstall/LocalDataRemoverTest.php:72-74` consumes unchanged `WorkflowAssistanceState` constants; lines 162-174 cover refusal when bundled Provider state cannot be removed. These tests remain pertinent qualification evidence, not rename sites.
- Matching `credentialId:`, `private:`, `repositoryId:` and `sourceRevision:` calls in Core Admin/Portability/Deployment/WordPress tests target local package/fixture helpers, not the mapped Provider helpers. They must remain unchanged.
- Core `RepositoryReleaseWorkflowStatus::failureHistory()`, Core webhook facade/interface `assess*`, and Core credential-interface `validateCredential()` remain foreign signatures. The same-name standalone fixture-client `branchHead()` methods also remain unchanged.
- The targeted method-name scan includes direct references and quoted method/mock/callback/reflection strings. No extra relevant callable-string site was found; constructed dynamic names cannot be categorically ruled out by lexical scanning alone.
- Existing Core references to Provider `GitHubProvider::create` and other Core-interface implementations remain outside this helper inventory. Their parameter contracts must not be accidentally included by a blanket identifier replacement.

### Proposed scope and remaining gates

The full 52-method / 64-parameter / three-promotion / four-reserved-parameter helper cohort needs a bounded **two-file Core handoff**, with the extra `repository()` override parameters included. The Provider-owned work remains separable from the 50 Core-interface implementation declarations. A smaller independent tranche can defer the externally consumed signatures, but cannot claim the full public-helper mapping complete.

Before implementation: accept the exact manifest, file owners, public compatibility treatment and source/release/adoption sequence through Core #167. Coordinate the replacement of the old bundled Provider tuple explicitly; a Core consumer change paired with an old Provider package is not coherent. This documentation audit does not authorize implementation, aliases, version bumps, dependency/protocol adoption, merges or releases.

After agreement: implement connected declarations/callers/test overrides atomically within an exact candidate composition; retain schema/template/credential/webhook/runtime semantics; remove only obsolete scoped exceptions. Required evidence remains Provider independent/exact-host gates, full Core checks, focused logging and uninstall fail-closed proofs, applicable archive/installed composition proof, naming negative controls, repeated formatter stability, independent review of the actual PR base/head, native/hosted review policy and owner authorization. Report preparation, integration, merge, publication and adoption separately.

## Satellite consumer audit

### Result

No direct typed consumer of the proposed Provider-owned public helpers was found in the eight refreshed satellite default-branch trees below. No Provider namespace/package reference, helper-class import/qualified name/class string, or satellite subclass of any of the 11 helper classes was present. Accordingly, this audit identifies **zero satellite runtime or test edits** required for the proposed helper rename. This is bounded source evidence, not assurance about arbitrary third-party consumers or dynamically supplied objects in installed sites.

The 52 method declarations were independently recovered from the 11 classes in the method table above; duplicate spellings across classes remain distinct. The audit also considered named arguments, callback/reflection strings, public-property access and the RepositorySnapshot promotions. Its three camelCase promoted properties (`repositoryId`, `defaultBranch`, `blobPrefixes`) are **private** state exposed through public constructor parameter names, not public properties. They still require explicit public named-argument compatibility decisions. The RepositoryBrowser private-parameter subset has no direct satellite declaration consumer.

### Fresh exact default revisions

Existing clones were refreshed with `git fetch origin HEAD` (Bitbucket `origin main`); three missing repositories were shallow-cloned from their default branch. Searches used the immutable fetched SHA, not the existing worktree contents. Existing satellite worktrees were not checked out or edited.

| Repository | Exact refreshed revision | Typed Provider helper consumers | Method-name lexical lines |
|---|---|---:|---:|
| ran-booster-bitbucket | `e3bbadca96587d07f655df89bc9eda1515c6dd20` | 0 | 15 |
| ran-booster-wp-pusher-migrator | `83ab555135f624b26f1069f29084acb49bf83104` | 0 | 1 |
| ran-wp-branch-updater | `24aa6932c41059cf6b5ffaba749a0b6abc4ef1c1` | 0 | 40 |
| ran-wp-release-updater | `0649dbb106fdbe8428b66a4f1efa0c52a03ddd99` | 0 | 0 |
| ran-updater-support | `ea902004f5f11def976a6c1cae75303985ada302` | 0 | 0 |
| ran-booster-release-bootstrap-templates | `11bcf641b39ab5296055015404eea2bf4fe6b24c` | 0 | 11 |
| ran-starter-plugin | `3490bef147580e07b43ab0aa4691b24548adb159` | 0 | 0 |
| ran-admin-shell | `e7f3a479de0678e5f96cf37d13295cd3c1f6d5a7` | 0 | 1 |

Lexical counts are matching lines across tracked source, tests and documentation, excluding lock files. They are neither call counts nor migration debt counts.

### Concrete false positives and preserved contracts

- **Bitbucket**: `src/Bitbucket/BitbucketCredentialValidator.php:10,20` implements Core `RAN\RepositoryProvider\CredentialValidator::validateCredential`, not Provider's `RepositoryBrowser` helper. `src/Bitbucket/BitbucketProvider.php:154-155` and `BitbucketDiagnostics.php:40` call that Bitbucket validator. `tests/RepositoryProvider/BitbucketCredentialValidatorTest.php:74` explicitly supplies `credentialId:`, `timeout:` and `response_size:` to the Bitbucket-owned validator; these must not be rewritten as Provider helper named arguments. `tests/WordPress/bitbucket-installed-smoke.php:212` reads Core repository-descriptor `defaultBranch`, not Provider RepositorySnapshot state. No Provider helper subclass, alias, callback or reflection target was identified. The `call_user_func_array` sites in `BitbucketArchivePreparerTest.php:485,491` invoke its archive redirect closure, with by-reference header arguments; preserve them.
- **Branch Updater**: `src/Runtime/BranchUpdater.php:39-59` owns `plugin(... $repositoryId, ... $packageSlug ...)` and `theme(... $repositoryId ...)`. Its `BranchDeploymentDeclaration` and `ArchiveOffer` own their respective `repositoryId` properties. Named arguments in `tests/contract.php:41,48,66,166,170,174`, README/API examples and migrating documentation target those updater entry points. They are not Provider RepositorySnapshot constructor calls. Do not change these same-spelling identifiers in the Provider tranche.
- **Template producer**: `scripts/generate-manifest.mjs:17,25,98`, `scripts/validate-pack.mjs:21,23,28` and `tests/run.mjs:30,512,594,642,690` use producer JavaScript `repositoryId` data and CLI validation. These are not PHP method/parameter consumers. No generated member, JSON schema, manifest identity or template byte change follows from the proposed PHP helper rename.
- **Migrator and Admin Shell**: the sole lexical match in each is the PHPStan configuration key `phpVersion`, in `phpstan.neon.dist`, not a call to Provider SourceReadyAssessment.
- **Release Updater**: no method-name lexical match. Reflection and method-shape validation in `src/Runtime/RequestProtocolValidator.php:95-114` concern its own bounded runtime objects; no Provider helper identity enters the inspected source tree. Its runtime protocol/dependency composition remains a separate integration obligation.

### Scope and remaining gates

Searched the complete tracked trees at the recorded revisions for the Provider namespace/package, all 11 helper class names, all 52 method declarations' spellings, RepositorySnapshot names, and callback/reflection/alias mechanisms. Relevant positive hits were checked by receiver/declaration identity. No satellite named-argument or callback reference was established as targeting Provider helpers.

This audit did not inspect open satellite PRs, unpublished branches, untracked/vendor-installed sources, arbitrary third-party packages, database-persisted callback names or running sites. It does not certify an installed composition and it does not extend Core's separate agreement. Provider-to-updater calls are the inverse dependency direction: renaming Provider helpers does not authorize renaming updater APIs, changing protocol semantics or adopting a new updater release. Template owner coordination remains necessary for its separate consumer contract work, even though no producer-side PHP caller was found here.

The documentation PR can record no identified satellite patch requirement at these revisions. Implementation must still await Core #167 mappings, ownership, API compatibility and integration-order agreement; keep the public-parameter and method scopes explicit. Refresh consumers again before implementing or adopting a changed public surface. Root retains the documentation commit/PR destination, full independent and exact-host qualification, connected artifact proofs, review and owner approval gates.

## Ownership and implementation order — proposed, not accepted

This document is the reviewable mapping proposal requested in [Core #167](https://github.com/RocketsAreNostalgic/ran-booster/issues/167#issuecomment-5935170863), refreshed after Core #216 merged. It does not reserve implementation files. The documentation-only claim is [Provider #25 comment 5935375891](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/25#issuecomment-5935375891), linked from organisation #65; template owner #28 has been notified. The root coordinator owns this manifest and final integration. Source cohorts below are proposed destinations, not active claims.

| Proposed cohort | Provider-owned declarations | Connected work and owner |
| --- | --- | --- |
| Transport | `src/RepositoryBrowser.php`, `src/RepositoryWebhookClient.php` | Worker updates declarations and assigned transport tests. Root integrates `src/GitHubProvider.php` and `src/Diagnostics.php` helper dispatches. Core owner supplies diagnostic-test overrides. |
| Snapshot and assessment | WorkflowAssistance `GitHubRepositoryClient.php`, `RepositorySnapshot.php`, `SourceReadyAssessment.php`, `SourceReadyAssessor.php` | Worker updates declarations and assigned tests; root integrates intersections with bundle/application callers. |
| Bundle and template | WorkflowAssistance `InitialReleaseBundle.php`, `TemplatePack.php`, `TemplatePackRepositoryClient.php`, `StarterSecurityCheck.php` | Worker updates declarations and assigned archive/producer tests; template owner retains contracts and generated bytes. Root integrates `StarterGuidance.php` and `StarterOrigin.php`. |
| State and application | WorkflowAssistance `SetupRecordStore.php`, `WorkflowAssistanceState.php`, `WorkflowApplicationCoordinator.php`, `GitHubRepositoryReleaseWorkflow.php` | Worker updates declarations and assigned state/workflow tests. Root integrates shared application/assessment/bundle call sites; Core owner supplies uninstall caller. |
| Integration and qualification | Exact connected callers, exception removal, documentation, shared configuration, generated files and dependency composition | Root alone composes worker commits. Dependencies/protocol adoption remain a separately claimed integration cohort; they are not implicitly included in the naming workers' scope. |

WorkflowAssistance paths above are under `src/ReleaseDeployments/WorkflowAssistance/`. Before delegation, reserve exact full production/test paths after a new collision check. Assign shared caller files to one integrator; do not let workers independently edit the same coordinator. Each isolated worker must identify its source commit, receiving Provider PR/commit and uncompleted gates. Account for every source draft before closure.

1. Core #167 accepts exact mappings and named-argument compatibility, identifies the two Core file owners and agrees paired source/release/adoption order. Provider/template owner #28 confirms any active overlap. Proposed policy is direct snake_case during beta, with no aliases absent a demonstrated mixed-version requirement and removal condition. Any API/version compatibility declaration is an explicit joint decision, not inferred from this document.
2. Prepare paired Provider and Core source candidates on refreshed bases. Preserve the 50 Core-interface implementation method declarations. Update all mapped helper calls, test overrides, dynamic method strings, parameter references and PHPDoc together. The webhook fitness dataset must preserve action/status codes when its callable strings change.
3. Remove only exceptions rendered obsolete by completed mappings. Keep directory-wide `RANOwnedMethods` and WPCS variable enforcement; prove bad owned methods in inherited/implementing classes, mapped variables/promotions and the formerly reserved parameters fail. Run repeated formatter passes and verify stable tracked bytes.
4. Run Provider `composer check` independently and `composer check:host` with explicitly identified certified and new paired Core candidates. A successful old-host run does not certify the new composition. Core runs its required `composer check`, `pnpm check`, focused logging/uninstall regressions and applicable runtime/archive/installed proofs with the actual new Provider candidate. Record the exact source and dependency tuple used by each proof; do not relabel an old bundled Provider as the candidate.
5. Verify executable-token/AST changes are limited to agreed identifiers and callback strings; retain literals representing JSON/persisted keys, credentials, hook identifiers, template bytes, release identity and runtime protocol behavior. Preserve discovery/datasets and error/status semantics. Audit named arguments and external override consistency even where method names already conform.
6. Publish reviewable implementation PRs, independently review their actual base/head revisions, disposition all review findings and satisfy native matrix/terminal quality gates. Owner approval remains required per PR. Source integration is not package publication or consumer adoption.
7. A separately authorized integration cohort performs Provider publication, real Core dependency/lock adoption, compatibility declarations and exact released archive/installed qualification in the agreed order. Release Updater beta.9/protocol-5 adoption requires its existing explicit handoff; do not absorb it into this mechanical naming change. UI and owner-verified interactive acceptance stay deferred.

## Explicit residual public parameters outside this helper cut

These are RAN-owned debt, not permanent external-signature exceptions. The 16 parameter occurrences below include 11 private promoted properties; the promotions are not additional parameter occurrences. They are outside this manifest's 64 helper parameters and need their own receiver/named-argument/consumer agreement. The older [Core contract manifest](naming-core-contract-manifest.md) remains a separate proposal and must be refreshed before use.

| Declaration | Remaining camelCase parameters | Private promotions among them |
| --- | --- | --- |
| `src/GitHubProvider.php`, `GitHubProvider::create` | `deliveryEvidence`, `maximumArtifactBytes` | None |
| `src/GitHubReleaseArtifact.php`, `GitHubReleaseArtifact::__construct` | `providerCommitId`, `packageRoot`, `mainFile`, `artifactSize`, `maximumArtifactBytes`, `artifactSha256` | All listed except `maximumArtifactBytes` |
| `src/GitHubReleaseNativeTarget.php`, `GitHubReleaseNativeTarget::__construct` | `packageType`, `metadataFile`, `providerRepositoryId`, `accessToken`, `deploymentPolicy`, `maximumArtifactBytes` | `packageType`, `metadataFile`, `providerRepositoryId`, `deploymentPolicy` |
| `src/WebhookNormalizer.php`, `WebhookNormalizer::__construct` | `webhookProfiles`, `deliveryEvidence` | Both |

The proposed eventual mapping for these residual identifiers is direct snake_case, but this document does not include them in the helper implementation claim. Core-owned interface parameters, DTO property accesses and genuine external signatures are also outside this helper cohort. Constructor magic names themselves remain unchanged.

## Delivery state

Prepared: exact source inventory and reviewable mapping proposal. Integrated: documentation only. Runtime implementation, source merge, package publication and connected adoption: not performed by this proposal. Core compatibility/ownership/order acceptance is still outstanding. No runtime branch, Core consumer patch or release draft is closed by this work.
