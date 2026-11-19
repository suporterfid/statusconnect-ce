<?php

/*
| Telegram notification channel (#188). One bot per installation: without a
| token the `telegram` channel type does not exist. See
| docs/integrations/telegram.md and docs/specs/telegram-notification-channel.md.
*/

return [
    // Token from BotFather. Never logged, stored or returned by the API.
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // The bot's @username without the @, used to build t.me deep links.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Shared secret of the webhook Telegram calls (path and secret_token header).
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),

    // Sending runs inside the checker's minute tick: keep it short.
    'timeout_seconds' => (int) env('TELEGRAM_TIMEOUT_SECONDS', 5),
];
