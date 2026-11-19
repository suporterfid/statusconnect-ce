<?php

namespace Tests\Unit;

use App\Http\Views\ResponseTimeChart;
use PHPUnit\Framework\TestCase;

class ResponseTimeChartTest extends TestCase
{
    public function test_null_points_split_the_line_and_isolated_points_become_dots(): void
    {
        $chart = new ResponseTimeChart([
            ['t' => '2026-11-07T00:00:00+00:00', 'avg_ms' => 100],
            ['t' => '2026-11-07T01:00:00+00:00', 'avg_ms' => 200],
            ['t' => '2026-11-07T02:00:00+00:00', 'avg_ms' => null],
            ['t' => '2026-11-07T03:00:00+00:00', 'avg_ms' => 150],
            ['t' => '2026-11-07T04:00:00+00:00', 'avg_ms' => null],
        ]);

        $this->assertTrue($chart->hasData());
        $this->assertSame(150, $chart->average());
        $this->assertSame(230, $chart->ceiling());
        $this->assertSame('M0,101.7 L200,23.5 M600,62.6', $chart->linePath());
        $this->assertSame([[600.0, 62.6]], $chart->dots());
        $this->assertSame('2026-11-07T00:00:00+00:00', $chart->firstTime());
        $this->assertSame('2026-11-07T04:00:00+00:00', $chart->lastTime());
    }

    public function test_empty_series_has_no_data(): void
    {
        $chart = new ResponseTimeChart([['t' => '2026-11-07T00:00:00+00:00', 'avg_ms' => null]]);

        $this->assertFalse($chart->hasData());
        $this->assertNull($chart->average());
        $this->assertSame('', $chart->linePath());
    }
}
