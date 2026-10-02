<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\Support;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;

final class EmptyAuthenticatedWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}
