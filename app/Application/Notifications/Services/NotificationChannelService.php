<?php

namespace App\Application\Notifications\Services;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class NotificationChannelService
{
    /**
     * @return Collection<int, NotificationChannel>
     */
    public function listChannels(Tenant $tenant, Environment $environment): Collection
    {
        return NotificationChannel::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->whereNull('archived_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createChannel(Tenant $tenant, Environment $environment, array $data): NotificationChannel
    {
        return NotificationChannel::create([
            'public_id' => 'nc_'.Str::lower((string) Str::ulid()),
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'config_json' => $data['config_json'] ?? [],
            'enabled' => $data['enabled'] ?? true,
        ]);
    }

    public function getChannel(Tenant $tenant, Environment $environment, string $publicId): NotificationChannel
    {
        return NotificationChannel::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->whereNull('archived_at')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateChannel(Tenant $tenant, Environment $environment, string $publicId, array $data): NotificationChannel
    {
        $channel = $this->getChannel($tenant, $environment, $publicId);

        $payload = [];
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $payload['name'] = $data['name'];
        }
        if (array_key_exists('type', $data) && $data['type'] !== null) {
            $payload['type'] = $data['type'];
        }
        if (array_key_exists('config_json', $data) && $data['config_json'] !== null) {
            $payload['config_json'] = $data['config_json'];
        }
        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $payload['enabled'] = (bool) $data['enabled'];
        }

        $channel->update($payload);

        return $channel->fresh();
    }

    public function deleteChannel(Tenant $tenant, Environment $environment, string $publicId): void
    {
        $channel = $this->getChannel($tenant, $environment, $publicId);
        $channel->update(['archived_at' => now()]);
        if ($channel->type === 'whatsapp') {
            // The phone number is personal data (LGPD): the archived channel keeps its deliveries, not the number (#215).
            $channel->forceFill(['config_json' => []])->save();
        }
    }
}
