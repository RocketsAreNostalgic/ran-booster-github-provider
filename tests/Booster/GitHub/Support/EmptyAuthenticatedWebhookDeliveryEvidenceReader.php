<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\Support;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;

final class EmptyAuthenticatedWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve external Core interface method spelling pending coordinated contract migration.
	public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}
