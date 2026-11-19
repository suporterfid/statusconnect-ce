<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\TelegramLinkCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Linking Telegram chats with one-time deep links, and the webhook (#190). */
class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAFakeTelegramTokenForTests_abcdefghij';

    private const SECRET = 'whsec_0123456789abcdefghijklmnopqrstuv';

    private ProvisionedAccount $account;

    private FakeTelegramApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.statusconnect.test',
            'telegram.bot_token' => self::TOKEN,
            'telegram.bot_username' => 'StatusConnectTestBot',
            'telegram.webhook_secret' => self::SECRET,
        ]);
        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return $hostname === 'api.telegram.org' ? ['149.154.167.220'] : [];
            }
        });
        $this->api = new FakeTelegramApi;
        $this->app->instance(PinnedHttpTransport::class, $this->api);

        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme', 'locale' => 'pt-BR',
        ]);
    }

    public function test_a_private_chat_links_with_its_start_code(): void
    {
        $link = $this->createLink()->assertCreated();
        $code = $this->codeFrom($link->json('data.start_url'));
        $this->assertSame('https://t.me/StatusConnectTestBot?start='.$code, $link->json('data.start_url'));
        $this->assertSame('https://t.me/StatusConnectTestBot?startgroup='.$code, $link->json('data.startgroup_url'));
        $this->assertSame(22, strlen($code));
        $this->assertDatabaseMissing('telegram_link_codes', ['code_hash' => $code]);

        $this->showLink($link->json('data.link_id'))->assertJsonPath('data.status', 'pending')->assertJsonPath('data.channel', null);

        $this->webhook($this->message('/start '.$code, ['id' => 5550001, 'type' => 'private', 'first_name' => 'Ana', 'last_name' => 'Souza']))
            ->assertOk();

        $channel = NotificationChannel::query()->where('type', 'telegram')->sole();
        $this->assertSame($this->account->environment->id, $channel->environment_id);
        $this->assertSame($this->account->tenant->id, $channel->tenant_id);
        $this->assertTrue($channel->enabled);
        $this->assertSame(['chat_id' => 5550001, 'chat_type' => 'private', 'chat_title' => 'Ana Souza'], $channel->config_json);
        $this->assertSame('Telegram — Ana Souza', $channel->name);

        $reply = $this->api->bodies()[0];
        $this->assertSame(5550001, $reply['chat_id']);
        $this->assertStringContainsString('Conectado ao ambiente Production de Acme', $reply['text']);

        $this->showLink($link->json('data.link_id'))
            ->assertJsonPath('data.status', 'linked')
            ->assertJsonPath('data.channel.public_id', $channel->public_id)
            ->assertJsonPath('data.channel.type', 'telegram');
    }

    public function test_a_group_links_with_the_bot_mention(): void
    {
        $code = $this->codeFrom($this->createLink()->json('data.start_url'));

        $this->webhook($this->message('/start@StatusConnectTestBot '.$code, ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Plantão Infra']))
            ->assertOk();

        $channel = NotificationChannel::query()->where('type', 'telegram')->sole();
        $this->assertSame(['chat_id' => -1001234567890, 'chat_type' => 'supergroup', 'chat_title' => 'Plantão Infra'], $channel->config_json);
    }

    public function test_a_start_for_another_bot_is_ignored(): void
    {
        $code = $this->codeFrom($this->createLink()->json('data.start_url'));

        $this->webhook($this->message('/start@SomeOtherBot '.$code, ['id' => -42, 'type' => 'group', 'title' => 'G']))->assertOk();

        $this->assertSame(0, NotificationChannel::query()->count());
        $this->assertCount(0, $this->api->requests);
    }

    public function test_expired_used_and_unknown_codes_create_nothing(): void
    {
        $first = $this->codeFrom($this->createLink()->json('data.start_url'));
        $this->webhook($this->message('/start '.$first, ['id' => 1, 'type' => 'private', 'first_name' => 'A']))->assertOk();

        $expired = $this->createLink();
        $this->travel(16)->minutes();

        $chat = ['id' => 2, 'type' => 'private', 'first_name' => 'B'];
        foreach ([$first, $this->codeFrom($expired->json('data.start_url')), 'not-a-real-code'] as $code) {
            $this->webhook($this->message('/start '.$code, $chat))->assertOk();
        }

        $this->assertSame(1, NotificationChannel::query()->count());
        $this->showLink($expired->json('data.link_id'))->assertJsonPath('data.status', 'expired');
        $replies = array_slice($this->api->bodies(), 1);
        $this->assertCount(3, $replies);
        foreach ($replies as $reply) {
            $this->assertStringContainsString('expirou ou já foi usado', $reply['text']);
        }
    }

    public function test_start_without_a_code_explains_how_to_link(): void
    {
        $this->webhook($this->message('/start', ['id' => 3, 'type' => 'private', 'first_name' => 'C'], 'en'))->assertOk();

        $this->assertSame(0, NotificationChannel::query()->count());
        $this->assertStringContainsString('Connect Telegram', $this->api->bodies()[0]['text']);
    }

    public function test_linking_the_same_chat_again_keeps_one_channel_and_enables_it(): void
    {
        $chat = ['id' => -77, 'type' => 'group', 'title' => 'Ops'];
        $this->webhook($this->message('/start '.$this->codeFrom($this->createLink()->json('data.start_url')), $chat));
        $channel = NotificationChannel::query()->sole();
        $channel->forceFill(['enabled' => false])->save();

        $this->webhook($this->message('/start '.$this->codeFrom($this->createLink()->json('data.start_url')), ['title' => 'Ops renamed'] + $chat));

        $this->assertSame(1, NotificationChannel::query()->count());
        $this->assertTrue($channel->fresh()->enabled);
        $this->assertSame('Ops renamed', $channel->fresh()->config_json['chat_title']);
    }

    public function test_the_webhook_needs_the_secret_in_the_path_and_the_header(): void
    {
        $code = $this->codeFrom($this->createLink()->json('data.start_url'));
        $update = $this->message('/start '.$code, ['id' => 9, 'type' => 'private', 'first_name' => 'X']);

        $this->postJson('/telegram/webhook/wrong-secret', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertNotFound();
        $this->postJson('/telegram/webhook/'.self::SECRET, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertNotFound();
        $this->postJson('/telegram/webhook/'.self::SECRET, $update)->assertNotFound();
        config(['telegram.webhook_secret' => '']);
        $this->postJson('/telegram/webhook/'.self::SECRET, $update, ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertNotFound();

        $this->assertSame(0, NotificationChannel::query()->count());
        $this->assertNull(TelegramLinkCode::query()->sole()->used_at);
    }

    public function test_removing_the_bot_disables_the_channels_of_that_chat_only(): void
    {
        $env2 = Environment::query()->create(['tenant_id' => $this->account->tenant->id, 'name' => 'Staging', 'slug' => 'staging']);
        $kicked = [$this->channel($this->account->environment, -500), $this->channel($env2, -500)];
        $other = $this->channel($this->account->environment, -600);

        $this->webhook(['update_id' => 10, 'my_chat_member' => [
            'chat' => ['id' => -500, 'type' => 'group', 'title' => 'G'],
            'from' => ['id' => 1],
            'date' => time(),
            'old_chat_member' => ['status' => 'member', 'user' => ['id' => 99]],
            'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 99]],
        ]])->assertOk();

        foreach ($kicked as $channel) {
            $this->assertFalse($channel->fresh()->enabled);
        }
        $this->assertTrue($other->fresh()->enabled);
    }

    public function test_link_status_is_scoped_to_the_environment(): void
    {
        $linkId = $this->createLink()->json('data.link_id');
        $env2 = Environment::query()->create(['tenant_id' => $this->account->tenant->id, 'name' => 'Staging', 'slug' => 'staging']);

        $this->actingAs($this->account->user)
            ->getJson('/v1/tenants/'.$this->account->tenant->public_id.'/environments/'.$env2->public_id.'/notification-channels/telegram-links/'.$linkId)
            ->assertNotFound();
    }

    public function test_permissions_and_configuration(): void
    {
        $service = app(ApiKeyService::class);
        $write = $service->create($this->account->tenant, $this->account->user, 'w', ['monitors:write'], $this->account->environment)['plaintext'];
        $secrets = $service->create($this->account->tenant, $this->account->user, 's', ['secrets:manage'], $this->account->environment)['plaintext'];

        $this->withHeaders(['Authorization' => 'Bearer '.$write])->postJson($this->linksUrl())->assertForbidden();
        $this->withHeaders(['Authorization' => 'Bearer '.$secrets])->postJson($this->linksUrl())->assertCreated();

        config(['telegram.bot_token' => null]);
        $this->withHeaders(['Authorization' => 'Bearer '.$secrets])->postJson($this->linksUrl())
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.telegram_not_configured'));
    }

    public function test_me_reports_whether_telegram_is_available(): void
    {
        $this->actingAs($this->account->user)->getJson('/v1/me')->assertJsonPath('meta.features.telegram', true);

        config(['telegram.bot_username' => '']);
        $this->actingAs($this->account->user)->getJson('/v1/me')->assertJsonPath('meta.features.telegram', false);
    }

    public function test_set_webhook_command(): void
    {
        $exit = Artisan::call('telegram:webhook', ['action' => 'set']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame('setWebhook', $this->api->method(0));
        $body = $this->api->bodies()[0];
        $this->assertSame('https://app.statusconnect.test/telegram/webhook/'.self::SECRET, $body['url']);
        $this->assertSame(self::SECRET, $body['secret_token']);
        $this->assertSame(['message', 'my_chat_member'], $body['allowed_updates']);
        $this->assertTrue($body['drop_pending_updates']);
        $this->assertStringNotContainsString(self::SECRET, $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);

        $this->api->queue(200, ['ok' => true, 'result' => ['url' => 'https://app.statusconnect.test/telegram/webhook/'.self::SECRET, 'pending_update_count' => 0, 'allowed_updates' => ['message', 'my_chat_member']]]);
        $this->assertSame(0, Artisan::call('telegram:webhook', ['action' => 'info']));
        $info = Artisan::output();
        $this->assertStringContainsString('/telegram/webhook/<secret>', $info);
        $this->assertStringNotContainsString(self::SECRET, $info);

        config(['telegram.webhook_secret' => 'short']);
        $this->assertSame(1, Artisan::call('telegram:webhook', ['action' => 'set']));
    }

    private function createLink(): TestResponse
    {
        return $this->actingAs($this->account->user)->postJson($this->linksUrl());
    }

    private function showLink(string $linkId): TestResponse
    {
        return $this->actingAs($this->account->user)->getJson($this->linksUrl().'/'.$linkId)->assertOk();
    }

    private function linksUrl(): string
    {
        return '/v1/tenants/'.$this->account->tenant->public_id.'/environments/'.$this->account->environment->public_id.'/notification-channels/telegram-links';
    }

    private function codeFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['start'] ?? $query['startgroup']);
    }

    /** @param array<string, mixed> $chat */
    private function message(string $text, array $chat, string $language = 'pt-br'): array
    {
        return ['update_id' => random_int(1, PHP_INT_MAX), 'message' => [
            'message_id' => 1,
            'from' => ['id' => 4242, 'is_bot' => false, 'first_name' => 'Ana', 'language_code' => $language],
            'chat' => $chat,
            'date' => time(),
            'text' => $text,
        ]];
    }

    /** @param array<string, mixed> $update */
    private function webhook(array $update): TestResponse
    {
        return $this->postJson('/telegram/webhook/'.self::SECRET, $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function channel(Environment $environment, int $chatId): NotificationChannel
    {
        return NotificationChannel::query()->create([
            'tenant_id' => $environment->tenant_id,
            'environment_id' => $environment->id,
            'name' => 'TG',
            'type' => 'telegram',
            'config_json' => ['chat_id' => $chatId, 'chat_type' => 'group', 'chat_title' => 'G'],
            'enabled' => true,
        ]);
    }
}
