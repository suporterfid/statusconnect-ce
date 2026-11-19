@extends('status.layout')

@php
    $fmt = new \App\Http\Views\StatusPageFormat($snapshot);
    $overallState = $snapshot['overall_state'] ?? 'no_data';
    $overallUptime = $snapshot['overall_uptime'] ?? null;
    $incidents = array_slice($snapshot['incidents'] ?? [], 0, 10);
    $maintenance = $snapshot['maintenance'] ?? [];
    $updates = $snapshot['updates'] ?? null;
@endphp

@section('banner')
    <div class="overall">
        <div class="wrap">
            <span class="roundel is-{{ $overallState }}" aria-hidden="true"></span>
            <h2 role="status">{{ __('status.overall.' . $overallState) }}</h2>
            <p class="sr-only">{{ __('status.states.' . $overallState) }}</p>
        </div>
    </div>
@endsection

@section('content')
        <section aria-labelledby="components-heading" class="first">
            <div class="section-head">
                <h2 id="components-heading">{{ __('status.uptime_heading') }} <small>{{ __('status.last_90_days') }}</small></h2>
                @if (! empty($snapshot['components']))
                    <a class="head-link" href="{{ $base.'/calendar' }}">{{ __('status.calendar') }}</a>
                @endif
            </div>
            <div class="card">
                @if (empty($snapshot['components']))
                    <p class="empty">{{ __('status.no_components') }}</p>
                @else
                    <ul class="components" aria-label="{{ __('status.components') }}">
                        @foreach ($snapshot['components'] as $component)
                            <li class="component">
                                <div class="component-head">
                                    <h3>
                                        @isset($component['id'])
                                            <a href="{{ ($base.'/components/'.$component['id']) }}">{{ $component['name'] }} <span aria-hidden="true">→</span></a>
                                        @else
                                            {{ $component['name'] }}
                                        @endisset
                                    </h3>
                                    <span class="figures">
                                        <span class="uptime">
                                            <span class="sr-only">{{ __('status.uptime_for', ['period' => '90d']) }}:</span>
                                            {{ $fmt->pct($component['uptime']['90d'] ?? 0) }}
                                        </span>
                                        @if (($component['latency_24h_ms'] ?? null) !== null)
                                            <span class="latency" title="{{ __('status.latency_24h') }}">
                                                <span class="sr-only">{{ __('status.latency_24h') }}:</span>
                                                {{ $fmt->latency((int) $component['latency_24h_ms']) }}
                                            </span>
                                        @endif
                                    </span>
                                    @include('status.partials.state', ['state' => $component['state'], 'label' => __('status.states.' . $component['state'])])
                                </div>
                                @include('status.partials.bars', ['component' => $component])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        @if (is_array($overallUptime))
            <section aria-labelledby="overall-uptime-heading">
                <h2 id="overall-uptime-heading" class="section-title">{{ __('status.overall_uptime') }}</h2>
                <div class="card stats">
                    @foreach (['24h', '7d', '30d', '90d'] as $period)
                        <div class="stat">
                            <strong>{{ $fmt->pct($overallUptime[$period] ?? 0) }}</strong>
                            <span>{{ __('status.periods.' . $period) }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (is_array($updates))
            <section aria-labelledby="updates-heading">
                <h2 id="updates-heading" class="section-title">{{ __('status.updates_heading') }} <small>{{ __('status.last_30_days') }}</small></h2>
                <div class="card">
                    @include('status.partials.updates', ['items' => $updates, 'empty' => __('status.no_updates_recent')])
                    @if (! empty($snapshot['update_history']))
                        <p class="card-link"><a href="{{ $base.'/history' }}">{{ __('status.updates_history_link') }}</a></p>
                    @endif
                </div>
            </section>
        @endif

        @if ($maintenance !== [])
            <section aria-labelledby="maintenance-heading">
                <h2 id="maintenance-heading" class="section-title">{{ __('status.maintenance') }}</h2>
                <ul class="card list">
                    @foreach ($maintenance as $window)
                        <li>
                            <div class="title">@include('status.partials.state', ['state' => 'maintenance', 'label' => $window['name']])</div>
                            <div class="meta">
                                {{ $fmt->dateTime($window['starts_at']) }} – {{ $fmt->dateTime($window['ends_at']) }}
                                @if (! empty($window['recurring_weekly']))
                                    · {{ __('status.maintenance_weekly') }}
                                @endif
                            </div>
                            @if (! empty($window['description']))
                                <div>{{ $window['description'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section aria-labelledby="incidents-heading">
            <h2 id="incidents-heading" class="section-title">{{ __('status.incidents') }}</h2>
            @include('status.partials.incidents', ['incidents' => $incidents])
        </section>

        <p class="note">{{ __('status.uptime_formula') }}</p>
@endsection
