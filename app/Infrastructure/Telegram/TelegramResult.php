<?php

namespace App\Infrastructure\Telegram;

/**
 * The answer of one Telegram Bot API call. `description` never contains the
 * bot token. `errorCode` is the Telegram error code, the HTTP status when the
 * body was not a Bot API answer, or 0 when no answer arrived.
 */
final readonly class TelegramResult
{
    /**
     * @param  array<string, mixed>  $parameters  ResponseParameters (migrate_to_chat_id, retry_after).
     */
    public function __construct(
        public bool $ok,
        public int $errorCode = 0,
        public string $description = '',
        public array $parameters = [],
        public mixed $result = null,
    ) {
    }
}
