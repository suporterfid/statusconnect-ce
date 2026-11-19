<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Why a line protocol payload was rejected as a whole. The message never
 * echoes payload content, only the line number.
 */
final readonly class LineProtocolError
{
    public const TOO_MANY_LINES = 'too_many_lines';

    public const LINE_TOO_LONG = 'line_too_long';

    public const MALFORMED = 'malformed';

    public function __construct(
        public string $code,
        public int $line,
        public string $message,
    ) {
    }
}
