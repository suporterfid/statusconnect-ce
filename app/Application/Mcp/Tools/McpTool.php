<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Application\Mcp\McpToolException;

interface McpTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema of the arguments, always of type object. */
    public function inputSchema(): array;

    /**
     * The API key permissions that allow the tool; any one of them is enough
     * (see EnforceTenantPermission::keyAllows).
     *
     * @return non-empty-list<string>
     */
    public function permissions(): array;

    /** @return array<string, bool> MCP tool annotations (readOnlyHint, destructiveHint, ...). */
    public function annotations(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> The structured result.
     *
     * @throws McpToolException
     */
    public function call(array $arguments, McpContext $context): array;
}
