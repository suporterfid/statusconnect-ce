@extends('status.layout')

@php
    $fmt = new \App\Http\Views\StatusPageFormat($snapshot);
    $componentIncidents = array_slice(array_values(array_filter(
        $snapshot['incidents'] ?? [],
        static fn (array $incident): bool => in_array($component['id'], $incident['components'] ?? [], true),
    )), 0, 10);
@endphp

@section('title'){{ $component['name'] }}@endsection

@section('content')
        <section aria-labelledby="component-heading" class="first">
            <p class="crumb"><a href="{{ ($base ?: '/') }}">← {{ __('status.back_to_status') }}</a></p>
            <div class="card component-card">
                <div class="component-head">
                    <h2 id="component-heading">{{ $component['name'] }}</h2>
                    @include('status.partials.state', ['state' => $component['state'], 'label' => __('status.states.' . $component['state'])])
                </div>
                @include('status.partials.bars', ['component' => $component])
            </div>
        </section>

        <section aria-labelledby="component-uptime-heading">
            <h2 id="component-uptime-heading" class="section-title">{{ __('status.uptime_heading') }}</h2>
            <div class="card stats">
                @foreach (['24h', '7d', '30d', '90d'] as $period)
                    <div class="stat">
                        <strong>{{ $fmt->pct($component['uptime'][$period] ?? 0) }}</strong>
                        <span>{{ __('status.periods.' . $period) }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="response-time-heading">
            <h2 id="response-time-heading" class="section-title">{{ __('status.response_time') }}</h2>
            <div class="card charts">
                @include('status.partials.chart', ['series' => $component['response_time']['24h'] ?? [], 'title' => __('status.periods.24h')])
                @include('status.partials.chart', ['series' => $component['response_time']['7d'] ?? [], 'title' => __('status.periods.7d')])
            </div>
        </section>

        <section aria-labelledby="incidents-heading">
            <h2 id="incidents-heading" class="section-title">{{ __('status.incidents') }}</h2>
            @include('status.partials.incidents', ['incidents' => $componentIncidents])
        </section>
@endsection
