<?php

namespace App\Http\Views;

/**
 * Turns a response-time series from the status page snapshot into SVG path data,
 * so the public page draws its chart server-side with no JavaScript library.
 */
final class ResponseTimeChart
{
    public const WIDTH = 800;

    public const HEIGHT = 180;

    /** @var list<array{t: string, avg_ms: int|null}> */
    private readonly array $points;

    private readonly int $ceiling;

    public function __construct(array $series)
    {
        $this->points = array_values($series);
        $values = $this->values();
        $max = $values === [] ? 0 : max($values);
        $this->ceiling = max(10, (int) (ceil($max * 1.15 / 10) * 10));
    }

    public function hasData(): bool
    {
        return $this->values() !== [];
    }

    public function ceiling(): int
    {
        return $this->ceiling;
    }

    public function average(): ?int
    {
        $values = $this->values();

        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }

    public function min(): ?int
    {
        $values = array_filter(array_map(static fn (array $p) => $p['min_ms'] ?? $p['avg_ms'], $this->points), static fn ($v) => $v !== null);

        return $values === [] ? null : (int) min($values);
    }

    public function max(): ?int
    {
        $values = array_filter(array_map(static fn (array $p) => $p['max_ms'] ?? $p['avg_ms'], $this->points), static fn ($v) => $v !== null);

        return $values === [] ? null : (int) max($values);
    }

    public function firstTime(): ?string
    {
        return $this->points[0]['t'] ?? null;
    }

    public function lastTime(): ?string
    {
        return $this->points === [] ? null : $this->points[count($this->points) - 1]['t'];
    }

    /**
     * Line path for the averages. A null point breaks the line, leaving a gap.
     */
    public function linePath(): string
    {
        return implode(' ', array_map(
            static fn (array $segment): string => 'M'.implode(' L', array_map(static fn (array $xy): string => $xy[0].','.$xy[1], $segment)),
            $this->segments(),
        ));
    }

    /**
     * Closed area under each line segment.
     */
    public function areaPath(): string
    {
        $paths = [];
        foreach ($this->segments() as $segment) {
            $first = $segment[0];
            $last = $segment[count($segment) - 1];
            $line = implode(' L', array_map(static fn (array $xy): string => $xy[0].','.$xy[1], $segment));
            $paths[] = sprintf('M%s,%d L%s L%s,%d Z', $first[0], self::HEIGHT, $line, $last[0], self::HEIGHT);
        }

        return implode(' ', $paths);
    }

    /**
     * One hover target per point, for the tooltip overlay: a column around the
     * point, in percent of the chart width, and where the point sits inside it.
     *
     * @return list<array{left: float, width: float, cx: float, y: ?float, t: string, until: ?string, avg_ms: ?int, min_ms: ?int, max_ms: ?int, edge: string}>
     */
    public function hoverPoints(): array
    {
        $count = count($this->points);
        if ($count === 0) {
            return [];
        }
        $step = $count > 1 ? 100 / ($count - 1) : 0;
        // A point covers its own period, up to the next one (the last one: one step).
        $span = $count > 1 ? strtotime($this->points[1]['t']) - strtotime($this->points[0]['t']) : 3600;
        $hover = [];
        foreach ($this->points as $index => $point) {
            $center = $count > 1 ? $index * $step : 50.0;
            $left = $count > 1 ? max(0.0, $center - $step / 2) : 0.0;
            $right = $count > 1 ? min(100.0, $center + $step / 2) : 100.0;
            $width = max(0.0001, $right - $left);
            $avg = $point['avg_ms'] ?? null;
            $hover[] = [
                'left' => round($left, 3),
                'width' => round($width, 3),
                'cx' => round(($center - $left) / $width * 100, 2),
                'y' => $avg === null ? null : round(100 - ($avg / $this->ceiling) * 100, 2),
                't' => $point['t'],
                'until' => $this->points[$index + 1]['t'] ?? date(DATE_ATOM, strtotime($point['t']) + $span),
                'avg_ms' => $avg,
                'min_ms' => $avg === null ? null : ($point['min_ms'] ?? null),
                'max_ms' => $avg === null ? null : ($point['max_ms'] ?? null),
                'edge' => $index < 3 ? 'edge-start' : ($index >= $count - 3 ? 'edge-end' : ''),
            ];
        }

        return $hover;
    }

    /**
     * Single isolated points (no neighbour to draw a line to).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function dots(): array
    {
        return array_map(static fn (array $segment): array => $segment[0], array_values(array_filter($this->segments(), static fn (array $s): bool => count($s) === 1)));
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    private function segments(): array
    {
        $count = count($this->points);
        $step = $count > 1 ? self::WIDTH / ($count - 1) : 0;
        $segments = [];
        $current = [];
        foreach ($this->points as $index => $point) {
            if ($point['avg_ms'] === null) {
                if ($current !== []) {
                    $segments[] = $current;
                    $current = [];
                }

                continue;
            }
            $x = round($index * $step, 1);
            $y = round(self::HEIGHT - ($point['avg_ms'] / $this->ceiling) * self::HEIGHT, 1);
            $current[] = [$x, $y];
        }
        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @return list<int>
     */
    private function values(): array
    {
        return array_values(array_filter(array_map(static fn (array $p) => $p['avg_ms'] ?? null, $this->points), static fn ($v) => $v !== null));
    }
}
