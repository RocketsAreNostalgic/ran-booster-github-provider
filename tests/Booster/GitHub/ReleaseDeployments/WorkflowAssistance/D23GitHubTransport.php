<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The Composer test autoloader owns this existing fixture namespace; keep its test discovery identity.
namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

final class D23GitHubTransport {
	/** @var list<array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @param list<mixed> $responses */
	public function __construct( private array $responses ) {}
	/** @param array<string,mixed> $args */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Callable transport signature is fixed.
	public function __invoke( string $method, string $url, array $args ): mixed {
		$this->requests[] = compact( 'method', 'url', 'args' );
		return array_shift( $this->responses );
	}
}
