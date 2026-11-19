<?php

namespace App\Application\Mcp;

use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;

/**
 * Who is calling the MCP server (#184): an environment API key, with its
 * tenant and environment already resolved. Every tool reads only inside
 * this tenant and environment.
 */
final class McpContext
{
    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly Tenant $tenant,
        public readonly Environment $environment,
    ) {}
}
