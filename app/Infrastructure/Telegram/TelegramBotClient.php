<?php

namespace App\Infrastructure\Telegram;

use App\Domain\Outbound\EgressProfile;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use Throwable;

/**
 * Calls the Telegram Bot API (#189) through the outbound policy and the pinned
 * HTTP transport, like every other outbound request.
 *
 * The token is part of the request URL (`/bot<token>/<method>`), so every text
 * this class returns has it replaced before anyone can log or store it.
 */
final class TelegramBotClient
{
    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly PinnedHttpTransport $transport,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    public function sendMessage(int|string $chatId, string $html): TelegramResult
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /** @param  array<string, mixed>  $params */
    public function call(string $method, array $params = []): TelegramResult
    {
        $token = $this->token();
        if ($token === '') {
            return new TelegramResult(ok: false, description: 'Telegram is not configured on this installation.');
        }

        try {
            $base = rtrim((string) config('telegram.api_base_url', 'https://api.telegram.org'), '/');
            $endpoint = $this->policy->validateUrl($base.'/bot'.$token.'/'.$method, egressProfile: EgressProfile::Webhook);
            $timeout = max(1, (int) config('telegram.timeout_seconds', 5));

            $response = $this->transport->send(new PinnedHttpRequest(
                method: 'POST',
                endpoint: $endpoint,
                headers: ['Content-Type' => 'application/json', 'User-Agent' => 'StatusConnect-Telegram/1.0'],
                body: json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                followRedirects: false,
                connectTimeout: min(3, $timeout),
                totalTimeout: $timeout,
                egressProfile: EgressProfile::Webhook,
            ));
        } catch (OutboundPolicyViolation $violation) {
            return new TelegramResult(ok: false, description: $this->redact('Outbound policy violation: '.$violation->getMessage()));
        } catch (Throwable $e) {
            return new TelegramResult(ok: false, description: $this->redact(mb_substr($e->getMessage(), 0, 300)));
        }

        if ($response->transportError !== null) {
            return new TelegramResult(ok: false, errorCode: $response->statusCode, description: $this->redact(mb_substr($response->transportError, 0, 300)));
        }

        $body = json_decode($response->bodyTruncated, true);
        if (! is_array($body) || ! array_key_exists('ok', $body)) {
            return new TelegramResult(ok: false, errorCode: $response->statusCode, description: $this->redact('HTTP '.$response->statusCode.': '.mb_substr($response->bodyTruncated, 0, 200)));
        }

        if ($body['ok'] === true) {
            return new TelegramResult(ok: true, result: $body['result'] ?? null);
        }

        return new TelegramResult(
            ok: false,
            errorCode: (int) ($body['error_code'] ?? $response->statusCode),
            description: $this->redact((string) ($body['description'] ?? 'Unknown Telegram error')),
            parameters: is_array($body['parameters'] ?? null) ? $body['parameters'] : [],
        );
    }

    /** Removes the bot token from a text, wherever it appears. */
    public function redact(string $text): string
    {
        $token = $this->token();

        return $token === '' ? $text : str_replace($token, '<redacted>', $text);
    }

    private function token(): string
    {
        return trim((string) config('telegram.bot_token', ''));
    }
}
