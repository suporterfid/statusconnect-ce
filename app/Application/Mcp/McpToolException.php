<?php

namespace App\Application\Mcp;

/**
 * A tool failure the caller should read (unknown id, missing permission, bad
 * argument). The server returns it as a tool result with `isError: true`,
 * not as a protocol error.
 */
final class McpToolException extends \RuntimeException {}
