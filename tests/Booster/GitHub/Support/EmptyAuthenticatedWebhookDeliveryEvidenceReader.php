<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub\Support;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;

final class EmptyAuthenticatedWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}
