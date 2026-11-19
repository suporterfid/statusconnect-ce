<?php

namespace App\Domain\Notifications\Transports;

use App\Domain\StatusPage\StatusPageLocale;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Telegram\TelegramBotClient;
use App\Infrastructure\Telegram\TelegramResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends incident notifications to a Telegram chat (#189).
 *
 * The channel holds only the chat (`config_json.chat_id`); the bot token is the
 * installation's (`config/telegram.php`). Messages are short HTML in the
 * language and time zone of the environment's status page.
 */
final class TelegramNotificationTransport implements NotificationTransportInterface
{
    public function __construct(private readonly TelegramBotClient $client)
    {
    }

    public function send(NotificationChannel $channel, array $payload): array
    {
        $chatId = $channel->config_json['chat_id'] ?? null;
        if (! is_int($chatId) && ! (is_string($chatId) && $chatId !== '')) {
            return ['status' => 'failed', 'response_code' => 400, 'error_excerpt' => 'No Telegram chat linked to this channel.'];
        }

        if (! $this->client->isConfigured()) {
            return ['status' => 'failed', 'response_code' => null, 'error_excerpt' => 'Telegram is not configured on this installation.'];
        }

        try {
            $text = $this->message($channel, $payload);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'response_code' => 500, 'error_excerpt' => $this->client->redact(mb_substr($e->getMessage(), 0, 300))];
        }

        $result = $this->client->sendMessage($chatId, $text);

        // A group that became a supergroup has a new chat id; follow it once.
        $migratedTo = $result->parameters['migrate_to_chat_id'] ?? null;
        if (! $result->ok && is_int($migratedTo)) {
            $channel->forceFill(['config_json' => array_merge($channel->config_json ?? [], ['chat_id' => $migratedTo, 'chat_type' => 'supergroup'])])->save();
            $result = $this->client->sendMessage($migratedTo, $text);
        }

        return $this->outcome($result);
    }

    /** @return array{status: string, response_code: ?int, error_excerpt: ?string} */
    private function outcome(TelegramResult $result): array
    {
        if ($result->ok) {
            return ['status' => 'sent', 'response_code' => 200, 'error_excerpt' => null];
        }

        $excerpt = 'Telegram '.($result->errorCode ?: 'error').': '.$result->description;
        if (isset($result->parameters['retry_after'])) {
            $excerpt .= ' (retry_after '.(int) $result->parameters['retry_after'].'s)';
        }

        return ['status' => 'failed', 'response_code' => $result->errorCode, 'error_excerpt' => mb_substr($excerpt, 0, 500)];
    }

    /** @param  array<string, mixed>  $payload */
    private function message(NotificationChannel $channel, array $payload): string
    {
        $page = DB::table('status_pages')->where('environment_id', $channel->environment_id)->first(['locale', 'timezone']);
        $locale = StatusPageLocale::normalize($page->locale ?? 'en');
        $timezone = $this->timezone($page->timezone ?? 'UTC');
        $t = fn (string $key, array $replace = []): string => (string) __('telegram.'.$key, $replace, $locale);
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $event = (string) ($payload['event'] ?? '');
        $monitor = $e($payload['monitor_name'] ?? 'Monitor');
        $link = '<a href="'.$e(rtrim((string) config('app.url'), '/').'/app/incidents').'">'.$e($t('open_app')).'</a>';

        if ($event === 'test') {
            return '🔔 '.$e($t('test', ['environment' => $channel->environment?->name ?? '']));
        }

        if ($event === 'opened') {
            $lines = ['🔴 <b>'.$t('opened', ['monitor' => $monitor]).'</b>'];
            $since = $t('since', [
                'time' => $e($this->formatTime($payload['started_at'] ?? null, $timezone, $t('date_format'))),
                'timezone' => $e($timezone),
            ]);
            if (! empty($payload['severity'])) {
                $since .= ' · '.$t('severity', ['severity' => $e($payload['severity'])]);
            }
            $lines[] = $since;
            if (! empty($payload['summary'])) {
                $lines[] = $e(mb_substr((string) $payload['summary'], 0, 500));
            }
            $lines[] = $link;

            return implode("\n", $lines);
        }

        if ($event === 'resolved') {
            return implode("\n", [
                '✅ <b>'.$t('resolved', ['monitor' => $monitor]).'</b>',
                $t('down_for', [
                    'duration' => $e($this->duration($payload)),
                    'start' => $e($this->formatTime($payload['started_at'] ?? null, $timezone, $t('time_format'))),
                    'end' => $e($this->formatTime($payload['resolved_at'] ?? null, $timezone, $t('time_format'))),
                ]),
                $link,
            ]);
        }

        return implode("\n", array_filter([
            'ℹ️ <b>'.$t('other', ['monitor' => $monitor]).'</b>',
            ! empty($payload['summary']) ? $e(mb_substr((string) $payload['summary'], 0, 500)) : null,
            $link,
        ]));
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function formatTime(mixed $iso, string $timezone, string $format): string
    {
        if (! is_string($iso) || $iso === '') {
            return '—';
        }

        return CarbonImmutable::parse($iso)->setTimezone($timezone)->format($format);
    }

    /** @param  array<string, mixed>  $payload */
    private function duration(array $payload): string
    {
        $seconds = (int) ($payload['duration_seconds'] ?? 0);
        if ($seconds <= 0 && is_string($payload['started_at'] ?? null) && is_string($payload['resolved_at'] ?? null)) {
            $seconds = max(0, CarbonImmutable::parse($payload['resolved_at'])->getTimestamp() - CarbonImmutable::parse($payload['started_at'])->getTimestamp());
        }

        if ($seconds < 60) {
            return $seconds.' s';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}
