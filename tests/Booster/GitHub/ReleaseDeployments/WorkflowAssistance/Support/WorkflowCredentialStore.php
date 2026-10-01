<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class WorkflowCredentialStore implements ProviderCredentialStore {
	public int $profile_reads = 0;

	/** @var array<string,array{id:string,label:string,kind:string,source:string,immutable:bool,configured:bool}>|null */
	public ?array $profiles = null;

	/** @var list<string|null> */
	public array $material_reads = array();

	/** @var array{secret:string}|null */
	public ?array $eligible_material = array( 'secret' => 'test-token' );

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function credentialProfiles(): array {
		++$this->profile_reads;
		return $this->profiles ?? array(
			'eligible' => array(
				'id'         => 'eligible',
				'label'      => 'Repository access',
				'kind'       => 'classic',
				'source'     => 'file',
				'immutable'  => false,
				'configured' => true,
			),
			'constant' => array(
				'id'         => 'constant',
				'label'      => 'Constant',
				'kind'       => 'classic',
				'source'     => 'constant',
				'immutable'  => true,
				'configured' => true,
			),
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function credentialMaterial( ?string $id = null ): ?array {
		$this->material_reads[] = $id;
		return 'eligible' === $id ? $this->eligible_material : null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Core interface double preserves the declared method and named-parameter contract.
	public function hasWebhookProfile(): bool {
		return false;
	}
}
