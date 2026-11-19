<?php

namespace App\Console\Commands;

use App\Infrastructure\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

/**
 * Registers, shows or removes the Telegram webhook of this installation (#190).
 * Prints neither the bot token nor the webhook secret.
 */
final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action : set, info or delete}';

    protected $description = 'Register (set), show (info) or remove (delete) the Telegram bot webhook';

    public function handle(TelegramBotClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'set' => $this->setWebhook($client),
            'info' => $this->showInfo($client),
            'delete' => $this->deleteWebhook($client),
            default => $this->unknown(),
        };
    }

    private function setWebhook(TelegramBotClient $client): int
    {
        $secret = (string) config('telegram.webhook_secret', '');
        if (! preg_match('/^[A-Za-z0-9_-]{32,256}$/', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET must be 32 to 256 characters of A-Z, a-z, 0-9, _ and -.');

            return self::FAILURE;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if (! str_starts_with($appUrl, 'https://')) {
            $this->error('APP_URL must be https: Telegram only calls https webhooks.');

            return self::FAILURE;
        }

        $result = $client->call('setWebhook', [
            'url' => $appUrl.'/telegram/webhook/'.$secret,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member'],
            'drop_pending_updates' => true,
        ]);
        if (! $result->ok) {
            $this->error('setWebhook failed: '.$this->hide($result->description));

            return self::FAILURE;
        }

        $this->info('Webhook set to '.$appUrl.'/telegram/webhook/<secret>.');

        return self::SUCCESS;
    }

    private function showInfo(TelegramBotClient $client): int
    {
        $result = $client->call('getWebhookInfo');
        if (! $result->ok || ! is_array($result->result)) {
            $this->error('getWebhookInfo failed: '.$this->hide($result->description));

            return self::FAILURE;
        }

        $info = $result->result;
        $lastError = isset($info['last_error_date']) ? date('c', (int) $info['last_error_date']).' '.($info['last_error_message'] ?? '') : '-';
        $this->table(['Field', 'Value'], [
            ['url', $this->hide((string) ($info['url'] ?? '')) ?: '(none)'],
            ['pending_update_count', (string) ($info['pending_update_count'] ?? 0)],
            ['allowed_updates', implode(', ', $info['allowed_updates'] ?? [])],
            ['last_error', $this->hide($lastError)],
        ]);

        return self::SUCCESS;
    }

    private function deleteWebhook(TelegramBotClient $client): int
    {
        $result = $client->call('deleteWebhook', ['drop_pending_updates' => true]);
        if (! $result->ok) {
            $this->error('deleteWebhook failed: '.$this->hide($result->description));

            return self::FAILURE;
        }
        $this->info('Webhook removed.');

        return self::SUCCESS;
    }

    private function unknown(): int
    {
        $this->error('Unknown action. Use set, info or delete.');

        return self::FAILURE;
    }

    private function hide(string $text): string
    {
        $secret = (string) config('telegram.webhook_secret', '');

        return $secret === '' ? $text : str_replace($secret, '<secret>', $text);
    }
}
