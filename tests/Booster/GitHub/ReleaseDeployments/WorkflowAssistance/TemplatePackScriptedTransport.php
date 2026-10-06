<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

final class TemplatePackScriptedTransport {
	/** @var list<array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();

	/** @param list<mixed> $responses */
	public function __construct( private array $responses ) {
	}

	/** @param array<string, mixed> $args */
	public function __invoke( string $method, string $url, array $args ): mixed {
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);

		return array_shift( $this->responses );
	}
}
