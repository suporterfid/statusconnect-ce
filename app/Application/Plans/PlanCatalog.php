<?php

namespace App\Application\Plans;

use App\Infrastructure\Persistence\Eloquent\Tenant;

/**
 * Reads the plan table in config/plans.php (#119).
 */
final class PlanCatalog
{
    public const UNLIMITED = 'unlimited';

    public function exists(string $plan): bool
    {
        return is_array(config("plans.plans.{$plan}"));
    }

    /**
     * @return array{name: string, price_cents: int, price_yearly_cents?: int, monitors: ?int, agent_monitors: ?int, min_interval_seconds: int, status_pages: ?int, custom_domain: bool, history_days: ?int, whatsapp_messages?: ?int, check_regions?: bool}
     */
    public function get(string $plan): array
    {
        $definition = config("plans.plans.{$plan}");
        if (! is_array($definition)) {
            // An unknown plan (removed from config) falls back to the default one
            // rather than lifting every limit.
            $definition = config('plans.plans.'.$this->defaultPlan());
        }

        return $definition;
    }

    public function forTenant(Tenant $tenant): array
    {
        return $this->get($this->planOf($tenant));
    }

    public function planOf(Tenant $tenant): string
    {
        $plan = (string) ($tenant->plan ?? '');

        return $this->exists($plan) ? $plan : $this->defaultPlan();
    }

    public function defaultPlan(): string
    {
        $plan = (string) config('plans.default', self::UNLIMITED);
        if ($this->exists($plan)) {
            return $plan;
        }

        // A misconfigured default: the free plan where the catalog has one, else the core's only plan (#250).
        return $this->exists('free') ? 'free' : self::UNLIMITED;
    }

    /** Plans a customer can pay for. */
    public function isPaid(string $plan): bool
    {
        return $this->exists($plan) && in_array($plan, $this->publicPlans(), true) && (int) $this->get($plan)['price_cents'] > 0;
    }

    /**
     * Price of one billing cycle (#217). Zero when the plan has no price for it
     * (no yearly price configured, for example).
     */
    public function price(string $plan, string $cycle): int
    {
        $definition = $this->get($plan);

        return (int) ($cycle === 'yearly' ? ($definition['price_yearly_cents'] ?? 0) : $definition['price_cents']);
    }

    /** @return list<string> */
    public function publicPlans(): array
    {
        return array_values(array_filter((array) config('plans.public', []), fn ($p) => $this->exists((string) $p)));
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys((array) config('plans.plans', []));
    }

    /** @return array<string, mixed> */
    public function present(string $plan): array
    {
        return ['id' => $plan] + $this->get($plan) + ['price_yearly_cents' => 0];
    }
}
