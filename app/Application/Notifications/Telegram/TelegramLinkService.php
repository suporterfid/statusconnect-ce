<?php

namespace App\Application\Notifications\Telegram;

use App\Domain\StatusPage\StatusPageLocale;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\TelegramLinkCode;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Infrastructure\Telegram\TelegramBotClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Links Telegram chats to environments (#190).
 *
 * The operator asks for a one-time code and opens t.me/<bot>?start=<code>
 * (private chat) or ?startgroup=<code> (group). Telegram then delivers
 * `/start <code>` to the webhook, which creates the channel in the code's
 * tenant and environment. Nothing that arrives from Telegram chooses the
 * tenant or the environment.
 */
final class TelegramLinkService
{
    public const TTL_MINUTES = 15;

    /** Chat member statuses that mean the bot is no longer in the chat. */
    private const GONE = ['left', 'kicked'];

    public function __construct(private readonly TelegramBotClient $client)
    {
    }

    /** True when the installation has a bot to link chats to. */
    public function available(): bool
    {
        return $this->client->isConfigured() && $this->botUsername() !== '';
    }

    /**
     * @return array{link: TelegramLinkCode, code: string}
     */
    public function createLink(Tenant $tenant, Environment $environment, ?User $user): array
    {
        // 128 bits, base64url without padding: 22 characters, within the 64
        // characters [A-Za-z0-9_-] Telegram accepts in a start parameter.
        $code = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        $link = TelegramLinkCode::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'created_by_user_id' => $user?->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return ['link' => $link, 'code' => $code];
    }

    /** @return array{start_url: string, startgroup_url: string} */
    public function urls(string $code): array
    {
        $base = 'https://t.me/'.rawurlencode($this->botUsername());

        return ['start_url' => $base.'?start='.$code, 'startgroup_url' => $base.'?startgroup='.$code];
    }

    /** @throws ModelNotFoundException when the link is not in this environment. */
    public function find(Tenant $tenant, Environment $environment, string $publicId): TelegramLinkCode
    {
        return TelegramLinkCode::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->with('channel')
            ->firstOrFail();
    }

    /**
     * Handles one update from the Telegram webhook. Updates it does not
     * understand are ignored.
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
    {
        if (is_array($update['my_chat_member'] ?? null)) {
            $this->handleMembership($update['my_chat_member']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! is_array($message) || ! is_string($message['text'] ?? null) || ! is_array($message['chat'] ?? null)) {
            return;
        }

        $code = $this->startCode($message['text']);
        if ($code === null) {
            return;
        }

        $chat = $message['chat'];
        if (! is_int($chat['id'] ?? null) || ! in_array($chat['type'] ?? null, ['private', 'group', 'supergroup'], true)) {
            return;
        }

        $language = str_starts_with((string) ($message['from']['language_code'] ?? ''), 'pt') ? 'pt_BR' : 'en';
        $channel = $code === '' ? null : $this->link($code, $chat);

        if ($channel === null) {
            $this->client->sendMessage($chat['id'], e(__($code === '' ? 'telegram.start_without_code' : 'telegram.link_expired', [], $language)));

            return;
        }

        $environment = $channel->environment;
        $locale = StatusPageLocale::normalize(DB::table('status_pages')->where('environment_id', $channel->environment_id)->value('locale'));
        $this->client->sendMessage($chat['id'], e(__('telegram.linked', [
            'environment' => $environment?->name ?? '',
            'organization' => $channel->tenant?->name ?? '',
        ], $locale)));
    }

    /**
     * The code of a `/start <code>` or `/start@<bot> <code>` addressed to this
     * bot: '' when it has no code, null when the text is something else.
     */
    private function startCode(string $text): ?string
    {
        if (! preg_match('/^\/start(?:@([A-Za-z0-9_]{1,64}))?(?:\s+(\S{1,64}))?\s*$/', trim($text), $m)) {
            return null;
        }
        if (($m[1] ?? '') !== '' && strcasecmp($m[1], $this->botUsername()) !== 0) {
            return null;
        }

        return $m[2] ?? '';
    }

    /**
     * Uses the code once and returns the channel of this chat, or null when the
     * code is unknown, used or expired.
     *
     * @param  array<string, mixed>  $chat
     */
    private function link(string $code, array $chat): ?NotificationChannel
    {
        return DB::transaction(function () use ($code, $chat): ?NotificationChannel {
            $link = TelegramLinkCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if ($link === null || $link->used_at !== null || $link->expires_at->isPast()) {
                return null;
            }

            $config = [
                'chat_id' => $chat['id'],
                'chat_type' => $chat['type'],
                'chat_title' => $this->chatTitle($chat),
            ];

            // The same chat linked again to the same environment: keep one channel.
            $channel = NotificationChannel::query()
                ->where('environment_id', $link->environment_id)
                ->where('type', 'telegram')
                ->whereNull('archived_at')
                ->get()
                ->first(fn (NotificationChannel $c): bool => ($c->config_json['chat_id'] ?? null) === $chat['id']);

            if ($channel !== null) {
                $channel->forceFill(['enabled' => true, 'config_json' => array_merge($channel->config_json ?? [], $config)])->save();
            } else {
                $channel = NotificationChannel::query()->create([
                    'tenant_id' => $link->tenant_id,
                    'environment_id' => $link->environment_id,
                    'name' => mb_substr('Telegram — '.$config['chat_title'], 0, 255),
                    'type' => 'telegram',
                    'config_json' => $config,
                    'enabled' => true,
                ]);
            }

            $link->forceFill(['used_at' => now(), 'channel_id' => $channel->id])->save();

            return $channel;
        });
    }

    /** @param  array<string, mixed>  $membership */
    private function handleMembership(array $membership): void
    {
        $chatId = $membership['chat']['id'] ?? null;
        $status = $membership['new_chat_member']['status'] ?? null;
        if (! is_int($chatId) || ! in_array($status, self::GONE, true)) {
            return;
        }

        NotificationChannel::query()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->get()
            ->filter(fn (NotificationChannel $c): bool => ($c->config_json['chat_id'] ?? null) === $chatId)
            ->each(fn (NotificationChannel $c) => $c->forceFill(['enabled' => false])->save());
    }

    /** @param  array<string, mixed>  $chat */
    private function chatTitle(array $chat): string
    {
        foreach ([$chat['title'] ?? null, trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')), $chat['username'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 200);
            }
        }

        return (string) $chat['id'];
    }

    private function botUsername(): string
    {
        return ltrim(trim((string) config('telegram.bot_username', '')), '@');
    }
}
