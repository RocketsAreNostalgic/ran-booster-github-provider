<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

final readonly class RepositoryResolverWpError {

	public function __construct( private string|int $code ) {
	}

	public function get_error_code(): string|int {
		return $this->code;
	}
}
