<?php

namespace App\Http\Views;

use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * Locale- and timezone-aware formatting for the public status page views.
 */
final class StatusPageFormat
{
    private readonly bool $pt;

    private readonly string $timezone;

    public function __construct(array $snapshot)
    {
        $this->pt = ($snapshot['locale'] ?? 'en') === 'pt_BR';
        $this->timezone = $snapshot['timezone'] ?? 'UTC';
    }

    public function pct(float|int|null $value): string
    {
        return number_format((float) $value, 2, $this->pt ? ',' : '.', '').'%';
    }

    /**
     * A latency: "451 ms" below one second, "1.2 s" / "1,2 s" from there (#242).
     */
    public function latency(int $ms): string
    {
        if ($ms < 1000) {
            return $ms.' ms';
        }

        return number_format($ms / 1000, $ms < 10_000 ? 1 : 0, $this->pt ? ',' : '.', $this->pt ? '.' : ',').' s';
    }

    public function dateTime(string $value): string
    {
        return Carbon::parse($value)->timezone($this->timezone)->format($this->pt ? 'd/m/Y H:i' : 'm/d/Y h:i A');
    }

    /**
     * A period such as one chart point: "17/11/2026 10:00 – 11:00", with the end
     * date repeated only when the period crosses midnight in the page timezone.
     */
    public function period(string $from, string $until): string
    {
        $start = Carbon::parse($from)->timezone($this->timezone);
        $end = Carbon::parse($until)->timezone($this->timezone);
        $endFormat = $start->isSameDay($end) ? ($this->pt ? 'H:i' : 'h:i A') : ($this->pt ? 'd/m/Y H:i' : 'm/d/Y h:i A');

        return $this->dateTime($from).' – '.$end->format($endFormat);
    }

    public function day(string $value): string
    {
        return Carbon::parse($value)->format($this->pt ? 'd/m/Y' : 'M j, Y');
    }

    public function duration(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes < 60
            ? __('status.duration_minutes', ['minutes' => $minutes])
            : __('status.duration_hours', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60]);
    }

    /**
     * Translated label for a status keyword, falling back to the raw value.
     */
    public function label(string $group, ?string $value): string
    {
        $key = "status.{$group}.".($value ?? '');

        return Lang::has($key) ? __($key) : (string) $value;
    }
}
