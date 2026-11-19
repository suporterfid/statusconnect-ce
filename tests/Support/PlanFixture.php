<?php

namespace Tests\Support;

/**
 * A fixed plan table for the core's plan-limit tests (#250).
 *
 * The core ships one unlimited plan; the paid plans are part of the
 * enterprise edition. Plan enforcement is core, so its tests bring their own
 * catalog with limited plans and pass with or without app/Enterprise.
 */
trait PlanFixture
{
    protected function usePlanFixture(): void
    {
        $plan = fn (string $name, ?int $monitors, ?int $agents, int $interval, ?int $pages, bool $domain, ?int $history, bool $regions) => [
            'name' => $name, 'price_cents' => 0, 'price_yearly_cents' => 0,
            'monitors' => $monitors, 'agent_monitors' => $agents, 'min_interval_seconds' => $interval,
            'status_pages' => $pages, 'custom_domain' => $domain, 'history_days' => $history,
            'whatsapp_messages' => null, 'check_regions' => $regions,
        ];

        config(['plans' => [
            'default' => 'free',
            'public' => ['free', 'pro', 'business'],
            'plans' => [
                'free' => $plan('Free', 10, 1, 180, 1, false, 90, false),
                'pro' => $plan('Pro', 50, 10, 60, 5, true, 365, true),
                'business' => $plan('Business', 200, 30, 60, 20, true, 730, true),
                'unlimited' => $plan('Unlimited', null, null, 10, null, true, null, true),
            ],
            'currency' => 'BRL',
        ]]);
    }
}
