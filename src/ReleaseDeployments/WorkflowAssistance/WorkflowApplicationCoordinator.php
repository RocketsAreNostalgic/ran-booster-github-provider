<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use Throwable;

/** GitHub initial-only API 3 assessment, preview, mutation, readback and outcome owner. */
final class WorkflowApplicationCoordinator {
	private const PREVIEW_PREFIX         = WorkflowAssistanceState::PREVIEW_PREFIX;
	private const PREVIEW_FIELDS         = array( 'schema_version', 'kind', 'user_id', 'type', 'identifier', 'revision', 'repo_id', 'repository', 'default_branch', 'base_sha', 'preflight_channel', 'profile_id', 'pack_version', 'manifest_hash', 'template_identity', 'bundle_hash', 'changed_path_hash', 'allowlist_hash', 'changes' );
	private const IDENTITY_FIELDS        = array( 'repository_name', 'repository_id', 'release_id', 'release_tag', 'release_commit', 'release_target', 'tag_target', 'release_draft', 'release_prerelease', 'release_immutable', 'asset_count', 'asset_id', 'asset_name', 'asset_state', 'asset_content_type', 'asset_size', 'asset_digest', 'asset_sha256' );
	private const PREFLIGHT_REASON_CODES = array( 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous' );

	public function __construct(
		private readonly GitHubRepositoryClient $github,
		private readonly TemplatePackRepositoryClient $templates,
		private readonly SourceReadyAssessor $assessor,
		private readonly SetupRecordStore $records
	) {
	}

	/**
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	public function inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, string $token ): array {
		if ( 'stable' !== $channel || $this->records->occupied( $status->provider_repository_id() ) ) {
			return $this->result( $status, 'invalid_request' );
		}
		if ( 'preflight_unavailable' === $preflight->code() ) {
			$reason = '' !== $preflight->reason_code() ? $preflight->reason_code() : 'provider_unavailable';
			return $this->result( $status, 'preflight_unavailable', false, '', 'release_preflight', $reason );
		}
		if ( ! $this->accepts_bootstrap_preflight( $preflight->code() ) ) {
			return $this->result( $status, $preflight->code(), 'ready' === $preflight->code(), '', 'release_preflight', $this->preflight_diagnostic( $preflight->code(), $preflight->reason_code() ) );
		}
		$remote = $this->bootstrap_bundle( $status, $token, null );
		if ( 'ok' !== $remote['code'] ) {
			return $this->result( $status, $remote['code'], 'release_automation_present' === $remote['code'] );
		}
		$preview = $this->preview_record( 'bootstrap', $status, $remote, $channel );
		try {
			$key = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return $this->result( $status, 'remote_unavailable', false, '', 'unexpected' );
		}
		return set_transient( self::PREVIEW_PREFIX . $key, $preview, 15 * MINUTE_IN_SECONDS )
			? $this->result( $status, 'inspected', true, $key ) : $this->result( $status, 'remote_unavailable', false, '', 'preview_storage' );
	}

	/**
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	public function setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, string $token ): array {
		$preview = $this->preview( $key, $status );
		if ( null === $preview || 'bootstrap' !== $preview['kind'] || '' === $token || ! hash_equals( $preview['repository'], trim( $confirmation ) ) ) {
			return $this->result( $status, 'invalid_request', false, $key );
		}
		if ( 'preflight_unavailable' === $preflight->code() ) {
			$reason = '' !== $preflight->reason_code() ? $preflight->reason_code() : 'provider_unavailable';
			return $this->result( $status, 'preflight_unavailable', false, $key, 'release_preflight', $reason );
		}
		if ( ! $this->accepts_bootstrap_preflight( $preflight->code() ) ) {
			return $this->result( $status, 'target_changed', false, $key, 'release_preflight', $this->preflight_diagnostic( $preflight->code(), $preflight->reason_code() ) );
		}
		$latest = $this->templates->discover( $token );
		$exact  = 'ok' === $latest['code'] && $latest['pack']->identity() === $preview['template_identity']
			? $this->templates->exact( $preview['template_identity'], $token ) : array( 'code' => 'template_superseded' );
		if ( 'ok' !== $exact['code'] ) {
			return $this->result( $status, $exact['code'], false, $key );
		}
		$remote = $this->bootstrap_bundle( $status, $token, $exact['pack'] );
		if ( 'ok' !== $remote['code'] || ! $this->preview_matches_bundle( $preview, $remote ) ) {
			return $this->result( $status, 'target_changed', false, $key );
		}
		return $this->open_draft( $status, $key, $remote, $token );
	}

	private function accepts_bootstrap_preflight( string $code ): bool {
		return in_array( $code, array( 'ready', 'release_unavailable' ), true );
	}

	private function preflight_diagnostic( string $code, string $reason ): string {
		if ( in_array( $reason, self::PREFLIGHT_REASON_CODES, true ) ) {
			return $reason;
		}

		return in_array( $code, self::PREFLIGHT_REASON_CODES, true ) ? $code : 'preflight_contract_unavailable';
	}

	/**
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	public function outcome( RepositoryReleaseWorkflowTarget $status, string $token ): array {
		$record = $this->current_record( $status );
		if ( null === $record ) {
			return $this->result( $status, 'invalid_request' );
		}
		$repo = $this->github->repository( $record['repository'], $token );
		$pull = $this->github->pull_request( $record['repository'], $record['pr_number'], $token );
		if ( 'ok' !== $repo['code'] || 'ok' !== $pull['code'] ) {
			return $this->result( $status, 'ok' !== $repo['code'] ? $repo['code'] : $pull['code'] );
		}
		$pr = $pull['pull'];
		if ( ! hash_equals( $record['repo_id'], $repo['repository_id'] ) || ! hash_equals( $record['default_branch'], $repo['default_branch'] )
			|| ! hash_equals( $record['setup_branch'], $pr['head'] ) || ! hash_equals( $record['head_sha'], $pr['head_sha'] ) || ! hash_equals( $record['default_branch'], $pr['base'] )
			|| ( ! $pr['merged'] && ! hash_equals( $record['base_sha'], $pr['base_sha'] ) ) ) {
			return $this->result( $status, 'target_changed' );
		}
		if ( 'open' === $pr['state'] ) {
			return $this->result( $status, 'pr_open', true );
		}
		if ( ! $pr['merged'] ) {
			return $this->result( $status, 'pr_closed' );
		}
		// Read back the original PR's exact changed blobs. Maintainer files are not managed after setup.
		$files = $this->github->pull_request_file_set( $record['repository'], $record['pr_number'], $token );
		$valid = 'ok' === $files['code'] && $files['files'] === $record['changed_files'];
		return $this->result( $status, $valid ? 'pr_merged' : 'target_changed', $valid );
	}

	/** Check stored workflow state before an adapter reads credential material. */
	public function has_current_record( RepositoryReleaseWorkflowTarget $status ): bool {
		return null !== $this->current_record( $status );
	}

	/** @return array<string,mixed>|null */
	private function current_record( RepositoryReleaseWorkflowTarget $status ): ?array {
		$record = $this->records->find( $status->provider_repository_id() );
		if ( null === $record || ! hash_equals( $status->type(), $record['package_type'] )
			|| ! hash_equals( $status->identifier(), $record['package_identifier'] ) ) {
			return null;
		}
		if ( $status->source_revision() === $record['source_revision'] ) {
			return $record;
		}

		return $this->records->refresh_source_revision(
			$status->provider_repository_id(),
			$status->type(),
			$status->identifier(),
			$status->source_revision()
		);
	}

	/**
	 * Return only a strict, current-user, current-package schema 3 preview.
	 * @return array<array-key,mixed>|null
	 */
	public function preview( string $key, RepositoryReleaseWorkflowTarget $status ): ?array {
		$preview        = get_transient( self::PREVIEW_PREFIX . $key );
		$valid_identity = static function ( mixed $identity ): bool {
			if ( ! is_array( $identity ) || array_keys( $identity ) !== self::IDENTITY_FIELDS
				|| 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' !== $identity['repository_name']
				|| '1322743261' !== $identity['repository_id'] || ! is_int( $identity['release_id'] ) || $identity['release_id'] < 1
				|| ! is_string( $identity['release_tag'] ) || 1 !== preg_match( '/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $identity['release_tag'] )
				|| ! is_string( $identity['release_commit'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $identity['release_commit'] )
				|| ! hash_equals( $identity['release_commit'], (string) $identity['release_target'] ) || ! hash_equals( $identity['release_commit'], (string) $identity['tag_target'] )
				|| false !== $identity['release_draft'] || false !== $identity['release_prerelease'] || true !== $identity['release_immutable']
				|| 1 !== $identity['asset_count'] || ! is_int( $identity['asset_id'] ) || $identity['asset_id'] < 1
				|| 'ran-booster-release-bootstrap-templates.zip' !== $identity['asset_name'] || 'uploaded' !== $identity['asset_state']
				|| ! in_array( $identity['asset_content_type'], array( 'application/zip', 'application/octet-stream' ), true ) || ! is_int( $identity['asset_size'] ) || $identity['asset_size'] < 1 || $identity['asset_size'] > 2097152
				|| ! is_string( $identity['asset_sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $identity['asset_sha256'] )
				|| ! hash_equals( 'sha256:' . $identity['asset_sha256'], (string) $identity['asset_digest'] ) ) {
				return false;
			}
			return true;
		};
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $key ) || ! is_array( $preview ) || array_keys( $preview ) !== self::PREVIEW_FIELDS || 3 !== $preview['schema_version']
			|| ! is_int( $preview['user_id'] ) || $preview['user_id'] < 1 || get_current_user_id() !== $preview['user_id']
			|| ! is_int( $preview['revision'] ) || $preview['revision'] < 1 || $status->source_revision() !== $preview['revision']
			|| ! is_string( $preview['repo_id'] ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $preview['repo_id'] ) || ! hash_equals( $status->provider_repository_id(), $preview['repo_id'] )
			|| ! in_array( $preview['type'], array( 'plugin', 'theme' ), true ) || ! hash_equals( $status->type(), $preview['type'] )
			|| ! is_string( $preview['identifier'] ) || '' === trim( $preview['identifier'] ) || strlen( $preview['identifier'] ) > 255
			|| 1 !== preg_match( '//u', $preview['identifier'] ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $preview['identifier'] )
			|| ! hash_equals( $status->identifier(), $preview['identifier'] ) || 'bootstrap' !== $preview['kind']
			|| ! is_string( $preview['repository'] ) || 1 !== preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $preview['repository'] )
			|| ! is_string( $preview['default_branch'] ) || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $preview['default_branch'] )
			|| str_contains( $preview['default_branch'], '..' ) || str_contains( $preview['default_branch'], '//' ) || str_contains( $preview['default_branch'], '@{' )
			|| ! is_string( $preview['base_sha'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $preview['base_sha'] )
			|| 'stable' !== $preview['preflight_channel']
			|| ! in_array( $preview['profile_id'], array( 'source-ready-wordpress-plugin/3', 'source-ready-wordpress-theme/3' ), true )
			|| ! hash_equals( 'source-ready-wordpress-' . $preview['type'] . '/3', $preview['profile_id'] )
			|| ! is_string( $preview['pack_version'] ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $preview['pack_version'] )
			|| ! is_string( $preview['manifest_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['manifest_hash'] )
			|| ! is_string( $preview['bundle_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['bundle_hash'] )
			|| ! is_string( $preview['changed_path_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['changed_path_hash'] )
			|| ! is_string( $preview['allowlist_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['allowlist_hash'] )
			|| ! $valid_identity( $preview['template_identity'] ) || ! hash_equals( 'v' . $preview['pack_version'], $preview['template_identity']['release_tag'] )
			|| ! is_array( $preview['changes'] ) || array() === $preview['changes'] || count( $preview['changes'] ) > 32 ) {
			return null;
		}
		$previous = '';
		foreach ( $preview['changes'] as $change ) {
			if ( ! is_array( $change ) || array_keys( $change ) !== array( 'path', 'operation', 'mode', 'sha256' )
				|| ! is_string( $change['path'] ) || '' === $change['path'] || strlen( $change['path'] ) > 512 || str_starts_with( $change['path'], '/' )
				|| str_contains( $change['path'], '\\' ) || str_contains( $change['path'], "\0" ) || 1 !== preg_match( '//u', $change['path'] )
				|| 1 === preg_match( '#(?:\A|/)\.\.?(/|\z)#', $change['path'] ) || ( '' !== $previous && strcmp( $previous, $change['path'] ) >= 0 )
				|| ! in_array( $change['operation'], array( 'added', 'modified' ), true )
				|| ! in_array( $change['mode'], array( '100644' ), true ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $change['sha256'] ) ) {
				return null;
			}
			$previous = $change['path'];
		}
		return $preview;
	}

	/**
	 * @return array<array-key,mixed>
	 */
	private function bootstrap_bundle( RepositoryReleaseWorkflowTarget $status, string $token, ?TemplatePack $pack = null ): array {
		$target = $this->target( $status, $token );
		if ( 'ok' !== $target['code'] ) {
			return $target;
		}
		$template = null === $pack ? $this->templates->discover( $token ) : array(
			'code' => 'ok',
			'pack' => $pack,
		);
		if ( 'ok' !== $template['code'] ) {
			return $template;
		}
		$assessment = $this->assessor->assess( $target['snapshot'], $status->type(), $status->package_root(), $status->installed_version(), $status->expected_update_uri() );
		if ( ! $assessment->ready_for_bootstrap() ) {
			return array( 'code' => $assessment->code() );
		}
		$made = InitialReleaseBundle::bootstrap( $template['pack'], $assessment, $target['snapshot'], $status->expected_update_uri() );
		return 'ok' === $made['code'] ? array_merge(
			$target,
			array(
				'code'   => 'ok',
				'pack'   => $template['pack'],
				'bundle' => $made['bundle'],
			)
		) : $made;
	}

	/**
	 * @return array<array-key,mixed>
	 */
	private function target( RepositoryReleaseWorkflowTarget $status, string $token ): array {
		$url = $status->expected_update_uri();
		if ( 1 !== preg_match( '#\Ahttps://github\.com/([A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99})/?\z#D', $url, $match ) ) {
			return array( 'code' => 'invalid_request' );
		}
		$repo = $this->github->repository( $match[1], $token );
		if ( 'ok' !== $repo['code'] || ! hash_equals( $status->provider_repository_id(), (string) ( $repo['repository_id'] ?? '' ) ) || 0 !== strcasecmp( $match[1], (string) ( $repo['full_name'] ?? '' ) ) ) {
			return array( 'code' => 'ok' === $repo['code'] ? 'target_changed' : $repo['code'] );
		}
		$base   = $this->github->branch_ref( $repo['full_name'], $repo['default_branch'], $token );
		$commit = 'ok' === $base['code'] ? $this->github->git_commit( $repo['full_name'], $base['sha'], $token ) : $base;
		$tree   = 'ok' === $commit['code'] ? $this->github->snapshot( $repo['full_name'], $repo['repository_id'], $repo['default_branch'], $base['sha'], $token ) : $commit;
		return 'ok' !== $tree['code'] ? $tree : array(
			'code'           => 'ok',
			'repository_id'  => $repo['repository_id'],
			'repository'     => $repo['full_name'],
			'default_branch' => $repo['default_branch'],
			'base_sha'       => $base['sha'],
			'base_tree_sha'  => $commit['tree_sha'],
			'snapshot'       => $tree['snapshot'],
		);
	}

	/**
	 * @param array<array-key,mixed> $remote
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function open_draft( RepositoryReleaseWorkflowTarget $status, string $preview_key, array $remote, string $token ): array {
		$claim = $this->records->claim( $status->provider_repository_id(), $status->type(), $status->identifier(), $status->source_revision() );
		if ( null === $claim ) {
			return $this->result( $status, 'invalid_request', false, $preview_key );
		}
		try {
			$outcome = $this->open_claimed_draft( $status, $preview_key, $remote, $token );
		} finally {
			$released = $this->release_claim( $status, $claim );
		}
		return $released || ! $outcome['successful'] ? $outcome : $this->result( $status, 'partial', false, '', 'local_persistence' );
	}

	private function release_claim( RepositoryReleaseWorkflowTarget $status, string $claim ): bool {
		return $this->records->release_claim( $status->provider_repository_id(), $claim );
	}

	/**
	 * @param array<array-key,mixed> $remote
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function open_claimed_draft( RepositoryReleaseWorkflowTarget $status, string $preview_key, array $remote, string $token ): array {
		if ( ! delete_transient( self::PREVIEW_PREFIX . $preview_key ) ) {
			return $this->result( $status, 'invalid_request', false, $preview_key );
		}
		$bundle = $remote['bundle'];
		$branch = sprintf( 'ran-booster/release-setup-v3-%s-%s', substr( $remote['base_sha'], 0, 12 ), substr( $bundle->hash(), 0, 8 ) );
		$lookup = $this->find_pull( $remote['repository'], $branch, $remote['default_branch'], $token );
		if ( 'ok' !== $lookup['code'] ) {
			return $this->result( $status, $lookup['code'], false, $preview_key );
		}
		$pull = $lookup['pull'];
		$head = null === $pull ? $this->create_atomic_commit( $remote, $bundle, $branch, $token ) : $pull['head_sha'];
		if ( null === $head || ! $this->verify_branch( $remote, $branch, $head, $bundle, $token ) ) {
			return $this->result( $status, 'partial', false, $preview_key, 'repository_mutation' );
		}
		$recovered = null !== $pull;
		if ( null === $pull ) {
			$title     = 'Bootstrap source-ready releases';
			$body      = sprintf( "Setup PR created is not execution readiness. Review RELEASE-STARTER.md and every generated file. Read-only Quality may run before merge; merging activates main workflows. Actions, runner access, bot PR permissions and immutable releases are owner-managed and not checked by Booster. You own these files after setup; Booster does not update or repair them.\n\nTemplate pack: `%s` (`%s`)\nConsumer API: `%d`\nBundle: `%s`\n", $bundle->pack_version(), $bundle->pack_identity()['release_tag'], TemplatePack::CONSUMER_API, $bundle->hash() );
			$created   = $this->github->create_draft_pull_request( $remote['repository'], $branch, $remote['default_branch'], $title, $body, $token );
			$recovered = 'ok' !== $created['code'];
			$lookup    = 'ok' === $created['code'] ? array(
				'code' => 'ok',
				'pull' => $created['pull'],
			) : $this->find_pull( $remote['repository'], $branch, $remote['default_branch'], $token );
			$pull      = 'ok' === $lookup['code'] ? $lookup['pull'] : null;
		}
		$files = null !== $pull ? $this->github->pull_request_file_set( $remote['repository'], $pull['number'], $token ) : array( 'code' => 'invalid_request' );
		if ( null === $pull || ! $pull['draft'] || 'open' !== $pull['state'] || ! hash_equals( $head, $pull['head_sha'] )
			|| ! hash_equals( $remote['base_sha'], $pull['base_sha'] ) || 'ok' !== $files['code'] || $files['files'] !== $bundle->expected_pull_files() ) {
			return $this->result( $status, 'partial', false, $preview_key, 'repository_mutation' );
		}
		$record = $this->record( $status, $remote, $bundle, $branch, $head, $pull['number'] );
		if ( ! $this->records->save( $record ) ) {
			return $this->result( $status, 'partial', false, $preview_key, 'local_persistence' );
		}
		return $this->result( $status, $recovered ? 'setup_recovered' : 'setup_open', true );
	}

	/**
	 * @param array<array-key,mixed> $remote
	 */
	private function create_atomic_commit( array $remote, InitialReleaseBundle $bundle, string $branch, string $token ): ?string {
		$repo = '' !== $token ? $this->github->repository( $remote['repository'], $token ) : array( 'code' => 'invalid_request' );
		$base = 'ok' === $repo['code'] ? $this->github->branch_ref( $remote['repository'], $remote['default_branch'], $token ) : $repo;
		if ( 'ok' !== $base['code'] || ! hash_equals( $remote['repository_id'], (string) ( $repo['repository_id'] ?? '' ) )
			|| ! hash_equals( $remote['repository'], (string) ( $repo['full_name'] ?? '' ) ) || ! hash_equals( $remote['default_branch'], (string) ( $repo['default_branch'] ?? '' ) )
			|| ! hash_equals( $remote['base_sha'], $base['sha'] ) ) {
			return null;
		}
		$existing = $this->github->branch_ref( $remote['repository'], $branch, $token );
		if ( 'ok' === $existing['code'] ) {
			return $this->verify_branch( $remote, $branch, $existing['sha'], $bundle, $token ) ? $existing['sha'] : null;
		}
		if ( 'missing' !== $existing['code'] ) {
			return null;
		}
		$entries = array();
		foreach ( $bundle->files() as $file ) {
			$blob = $this->github->create_blob( $remote['repository'], $file['content'], $token );
			if ( 'ok' !== $blob['code'] || ! hash_equals( $file['git_sha'], $blob['sha'] ) ) {
				$read = $this->github->blob( $remote['repository'], $file['git_sha'], $token );
				if ( 'ok' !== $read['code'] || ! hash_equals( hash( 'sha256', $file['content'] ), hash( 'sha256', $read['content'] ) ) ) {
					return null;
				}
				$blob = array(
					'code' => 'ok',
					'sha'  => $file['git_sha'],
				);
			}
			$entries[] = array(
				'path' => $file['path'],
				'sha'  => $blob['sha'],
				'mode' => $file['mode'],
			);
		}
		$tree    = $this->github->create_tree( $remote['repository'], $remote['base_tree_sha'], $entries, $token );
		$message = 'chore: bootstrap source-ready releases';
		$commit  = 'ok' === $tree['code'] ? $this->github->create_commit( $remote['repository'], $tree['sha'], $remote['base_sha'], $message, $token ) : $tree;
		$repo    = 'ok' === $commit['code'] ? $this->github->repository( $remote['repository'], $token ) : $commit;
		$base    = 'ok' === $repo['code'] ? $this->github->branch_ref( $remote['repository'], $remote['default_branch'], $token ) : $repo;
		if ( 'ok' !== $commit['code'] || 'ok' !== $base['code'] || ! hash_equals( $remote['repository_id'], (string) ( $repo['repository_id'] ?? '' ) )
			|| ! hash_equals( $remote['repository'], (string) ( $repo['full_name'] ?? '' ) ) || ! hash_equals( $remote['default_branch'], (string) ( $repo['default_branch'] ?? '' ) )
			|| ! hash_equals( $remote['base_sha'], $base['sha'] ) ) {
			return null;
		}
		$created = $this->github->create_ref( $remote['repository'], $branch, $remote['default_branch'], $commit['sha'], $token );
		$ref     = 'ok' === $created['code'] ? $created : $this->github->branch_ref( $remote['repository'], $branch, $token );
		return 'ok' === $ref['code'] && hash_equals( $commit['sha'], $ref['sha'] ) && $this->verify_branch( $remote, $branch, $commit['sha'], $bundle, $token ) ? $commit['sha'] : null;
	}

	/**
	 * @param array<array-key,mixed> $remote
	 */
	private function verify_branch( array $remote, string $branch, string $head, InitialReleaseBundle $bundle, string $token ): bool {
		$ref = $this->github->branch_ref( $remote['repository'], $branch, $token );
		if ( 'ok' !== $ref['code'] || ! hash_equals( $head, $ref['sha'] ) ) {
			return false;
		}
		$commit = $this->github->git_commit( $remote['repository'], $head, $token );
		if ( 'ok' !== $commit['code'] || array( $remote['base_sha'] ) !== $commit['parents'] ) {
			return false;
		}
		$tree = $this->github->snapshot( $remote['repository'], $remote['repository_id'], $remote['default_branch'], $head, $token );
		if ( 'ok' !== $tree['code'] ) {
			return false;
		}
		foreach ( $bundle->files() as $path => $file ) {
			$entry = $tree['snapshot']->entries()[ $path ] ?? null;
			if ( ! is_array( $entry ) || ! hash_equals( $file['git_sha'], $entry['sha'] ) || ! hash_equals( $file['mode'], $entry['mode'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<array-key,mixed>
	 */
	private function find_pull( string $repository, string $branch, string $base, string $token ): array {
		$result = $this->github->pull_requests( $repository, $branch, $token );
		if ( 'ok' !== $result['code'] ) {
			return $result;
		}
		if ( array() === $result['pulls'] ) {
			return array(
				'code' => 'ok',
				'pull' => null,
			);
		}
		if ( 1 !== count( $result['pulls'] ) || 'open' !== $result['pulls'][0]['state']
			|| ! hash_equals( $branch, $result['pulls'][0]['head'] ) || ! hash_equals( $base, $result['pulls'][0]['base'] ) ) {
			return array( 'code' => 'invalid_response' );
		}
		return array(
			'code' => 'ok',
			'pull' => $result['pulls'][0],
		);
	}

	/**
	 * @param array<array-key,mixed> $remote
	 * @return array<array-key,mixed>
	 */
	private function preview_record( string $kind, RepositoryReleaseWorkflowTarget $status, array $remote, string $channel ): array {
		$bundle  = $remote['bundle'];
		$changes = array();
		foreach ( $bundle->files() as $file ) {
			$changes[] = array(
				'path'      => $file['path'],
				'operation' => $file['operation'],
				'mode'      => $file['mode'],
				'sha256'    => $file['sha256'],
			);
		}
		return array(
			'schema_version'    => 3,
			'kind'              => $kind,
			'user_id'           => get_current_user_id(),
			'type'              => $status->type(),
			'identifier'        => $status->identifier(),
			'revision'          => $status->source_revision(),
			'repo_id'           => $status->provider_repository_id(),
			'repository'        => $remote['repository'],
			'default_branch'    => $remote['default_branch'],
			'base_sha'          => $remote['base_sha'],
			'preflight_channel' => $channel,
			'profile_id'        => $bundle->profile(),
			'pack_version'      => $bundle->pack_version(),
			'manifest_hash'     => $bundle->manifest_hash(),
			'template_identity' => $bundle->pack_identity(),
			'bundle_hash'       => $bundle->hash(),
			'changed_path_hash' => $bundle->changed_path_hash(),
			'allowlist_hash'    => $bundle->allowlist_hash(),
			'changes'           => $changes,
		);
	}

	/**
	 * @param array<array-key,mixed> $preview
	 * @param array<array-key,mixed> $remote
	 */
	private function preview_matches_bundle( array $preview, array $remote ): bool {
		$bundle  = $remote['bundle'];
		$changes = array();
		foreach ( $bundle->files() as $file ) {
			$changes[] = array(
				'path'      => $file['path'],
				'operation' => $file['operation'],
				'mode'      => $file['mode'],
				'sha256'    => $file['sha256'],
			);
		}
		return hash_equals( $preview['repository'], $remote['repository'] ) && hash_equals( $preview['default_branch'], $remote['default_branch'] )
			&& hash_equals( $preview['base_sha'], $remote['base_sha'] ) && hash_equals( $preview['profile_id'], $bundle->profile() )
			&& hash_equals( $preview['pack_version'], $bundle->pack_version() ) && hash_equals( $preview['manifest_hash'], $bundle->manifest_hash() )
			&& hash_equals( $preview['bundle_hash'], $bundle->hash() ) && hash_equals( $preview['changed_path_hash'], $bundle->changed_path_hash() )
			&& hash_equals( $preview['allowlist_hash'], $bundle->allowlist_hash() ) && $preview['template_identity'] === $bundle->pack_identity()
			&& $preview['changes'] === $changes;
	}

	/**
	 * @param array<array-key,mixed> $remote
	 * @return array<array-key,mixed>
	 */
	private function record( RepositoryReleaseWorkflowTarget $status, array $remote, InitialReleaseBundle $bundle, string $branch, string $head, int $pull ): array {
		$identity = $bundle->pack_identity();
		return array(
			'schema_version'        => 3,
			'operation'             => 'bootstrap',
			'repo_id'               => $status->provider_repository_id(),
			'repository'            => $remote['repository'],
			'package_type'          => $status->type(),
			'package_identifier'    => $status->identifier(),
			'source_revision'       => $status->source_revision(),
			'default_branch'        => $remote['default_branch'],
			'base_sha'              => $remote['base_sha'],
			'setup_branch'          => $branch,
			'head_sha'              => $head,
			'pr_number'             => $pull,
			'profile_id'            => $bundle->profile(),
			'template_repo_name'    => $identity['repository_name'],
			'template_repo_id'      => $identity['repository_id'],
			'template_release_id'   => $identity['release_id'],
			'template_tag'          => $identity['release_tag'],
			'template_commit'       => $identity['release_commit'],
			'template_asset_id'     => $identity['asset_id'],
			'template_asset_name'   => $identity['asset_name'],
			'template_asset_size'   => $identity['asset_size'],
			'template_asset_digest' => $identity['asset_sha256'],
			'manifest_digest'       => $bundle->manifest_hash(),
			'changed_files'         => $bundle->expected_pull_files(),
			'consumer_api'          => TemplatePack::CONSUMER_API,
			'pack_version'          => $bundle->pack_version(),
			'bundle_hash'           => $bundle->hash(),
			'changed_path_hash'     => $bundle->changed_path_hash(),
		);
	}

	/**
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string}
	 */
	private function result( RepositoryReleaseWorkflowTarget $status, string $code, bool $successful = false, string $preview = '', string $stage = '', string $diagnostic = '' ): array {
		$mapped = in_array( $code, self::PREFLIGHT_REASON_CODES, true ) ? 'workflow_' . $code : match ( $code ) {
			'ready', 'invalid_release_assets', 'release_version_mismatch', 'release_header_missing', 'release_header_invalid', 'release_archive_unreadable', 'preflight_unavailable' => 'workflow_' . ( 'ready' === $code ? 'release_ready' : $code ),
			'unauthorised' => 'workflow_unauthorised', 'template_superseded', 'template_pack_changed' => 'workflow_template_superseded',
			'target_changed' => 'workflow_target_changed', 'template_pack_unavailable' => 'workflow_template_unavailable',
			'remote_unavailable' => 'workflow_remote_unavailable', 'rate_limited' => 'workflow_rate_limited', 'invalid_response' => 'workflow_invalid_response',
			'template_pack_incompatible' => 'workflow_template_incompatible', 'template_pack_invalid' => 'workflow_template_invalid',
			'package_ambiguous', 'version_mismatch', 'version_contract_custom', 'runtime_paths_unknown', 'release_automation_conflict', 'release_automation_present', 'release_path_conflict', 'repository_unsupported' => 'workflow_' . $code,
			'inspected', 'setup_open', 'setup_recovered', 'partial', 'pr_open', 'pr_closed', 'pr_merged' => 'workflow_' . $code,
			default => 'workflow_invalid_request',
		};
		return array(
			'type'            => $status->type(),
			'identifier'      => $status->identifier(),
			'code'            => $mapped,
			'successful'      => $successful,
			'preview_key'     => $preview,
			'failure_stage'   => $successful ? '' : $this->failure_stage( $code, $stage ),
			'diagnostic_code' => $successful ? '' : $this->diagnostic_code( $code, $stage, $diagnostic ),
		);
	}

	private function diagnostic_code( string $code, string $stage, string $diagnostic ): string {
		$allowed = array( 'credential_authorisation_unavailable', 'preflight_contract_unavailable', ...self::PREFLIGHT_REASON_CODES, 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );
		if ( in_array( $diagnostic, $allowed, true ) ) {
			return $diagnostic;
		}
		$resolved_stage = '' !== $stage ? $stage : $this->failure_stage( $code, '' );
		if ( 'release_automation_conflict' === $code ) {
			return 'release_automation_detected';
		}
		return match ( $resolved_stage ) {
			'credential_authorisation' => 'credential_authorisation_unavailable',
			'release_preflight' => 'preflight_contract_unavailable',
			'repository_snapshot' => 'repository_snapshot_unavailable',
			'template_pack' => 'template_pack_unavailable',
			'preview_storage' => 'preview_storage_unavailable',
			'repository_mutation' => 'repository_mutation_unverified',
			'local_persistence' => 'local_persistence_unavailable',
			default => 'unexpected_runtime_failure',
		};
	}

	private function failure_stage( string $code, string $stage ): string {
		if ( in_array( $stage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ) {
			return $stage;
		}
		return match ( $code ) {
			'unauthorised' => 'credential_authorisation',
			'preflight_unavailable' => 'release_preflight',
			'release_automation_conflict' => 'repository_snapshot',
			'template_pack_unavailable', 'template_pack_invalid' => 'template_pack',
			'remote_unavailable', 'rate_limited', 'invalid_response' => 'repository_snapshot',
			default => '',
		};
	}
}
