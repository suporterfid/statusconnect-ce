@php $chart = new \App\Http\Views\ResponseTimeChart($series); @endphp
<div class="chart-block">
    <div class="chart-head">
        <h3>{{ $title }}</h3>
        @if ($chart->hasData())
            <dl class="chart-stats">
                <div><dt>{{ __('status.rt_avg') }}</dt><dd>{{ $chart->average() }} ms</dd></div>
                <div><dt>{{ __('status.rt_min') }}</dt><dd>{{ $chart->min() }} ms</dd></div>
                <div><dt>{{ __('status.rt_max') }}</dt><dd>{{ $chart->max() }} ms</dd></div>
            </dl>
        @endif
    </div>
    @if ($chart->hasData())
        <div class="chart">
            <span class="chart-ceiling" aria-hidden="true">{{ $chart->ceiling() }} ms</span>
            <svg viewBox="0 0 {{ \App\Http\Views\ResponseTimeChart::WIDTH }} {{ \App\Http\Views\ResponseTimeChart::HEIGHT }}" preserveAspectRatio="none" role="img"
                 aria-label="{{ __('status.rt_summary', ['title' => $title, 'avg' => $chart->average(), 'max' => $chart->max()]) }}">
                <line x1="0" y1="{{ \App\Http\Views\ResponseTimeChart::HEIGHT / 2 }}" x2="{{ \App\Http\Views\ResponseTimeChart::WIDTH }}" y2="{{ \App\Http\Views\ResponseTimeChart::HEIGHT / 2 }}" class="grid" />
                <path d="{{ $chart->areaPath() }}" class="area" />
                <path d="{{ $chart->linePath() }}" class="line" />
                @foreach ($chart->dots() as [$x, $y])
                    <path d="M{{ $x }},{{ $y }} h0.01" class="dot-point" />
                @endforeach
            </svg>
            <div class="chart-hits">
                @foreach ($chart->hoverPoints() as $point)
                    @php
                        $value = match (true) {
                            $point['avg_ms'] === null => __('status.rt_point_no_data'),
                            $point['min_ms'] !== null && $point['max_ms'] !== null && $point['min_ms'] !== $point['max_ms'] => __('status.rt_point_range', ['avg' => $point['avg_ms'], 'min' => $point['min_ms'], 'max' => $point['max_ms']]),
                            default => __('status.rt_point', ['avg' => $point['avg_ms']]),
                        };
                        $tip = $fmt->period($point['t'], $point['until'])."\n".$value;
                    @endphp
                    <span tabindex="0" class="{{ $point['edge'] }}" style="left: {{ $point['left'] }}%; width: {{ $point['width'] }}%; --cx: {{ $point['cx'] }}%{{ $point['y'] !== null ? '; --y: '.$point['y'].'%' : '' }}" data-tip="{{ $tip }}">@if ($point['y'] !== null)<i class="mark"></i>@endif<span class="sr-only">{{ $tip }}</span></span>
                @endforeach
            </div>
            <div class="bars-legend" aria-hidden="true">
                <span>{{ $fmt->dateTime($chart->firstTime()) }}</span>
                <span>{{ $fmt->dateTime($chart->lastTime()) }}</span>
            </div>
        </div>
    @else
        <p class="empty">{{ __('status.rt_no_data') }}</p>
    @endif
</div>
