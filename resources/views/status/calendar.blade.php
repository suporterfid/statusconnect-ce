@extends('status.layout')

@php
    $fmt = new \App\Http\Views\StatusPageFormat($snapshot);
    $rank = ['no_data' => 0, 'operational' => 1, 'degraded' => 2, 'major_outage' => 3];
    $days = [];
    foreach ($snapshot['components'] ?? [] as $component) {
        foreach ($component['history'] ?? [] as $entry) {
            $current = $days[$entry['date']] ?? 'no_data';
            if (($rank[$entry['state']] ?? 0) > ($rank[$current] ?? 0)) {
                $current = $entry['state'];
            }
            $days[$entry['date']] = $current;
        }
    }
    ksort($days);
    $lastDate = array_key_last($days) ?? now()->format('Y-m-d');
    $end = \Carbon\Carbon::parse($lastDate)->startOfMonth();
    $months = [$end->copy()->subMonths(2), $end->copy()->subMonth(), $end];
    $weekdays = [];
    for ($i = 0; $i < 7; $i++) {
        $weekdays[] = \Carbon\Carbon::create(2024, 1, 1 + $i)->locale($snapshot['locale'])->isoFormat('dd');
    }
@endphp

@section('title'){{ __('status.calendar') }}@endsection

@section('content')
        <section aria-labelledby="calendar-heading" class="first">
            <h2 id="calendar-heading" class="section-title">{{ __('status.calendar') }}</h2>
            <div class="card calendar">
                @foreach ($months as $month)
                    <div class="month">
                        <h3>{{ ucfirst($month->copy()->locale($snapshot['locale'])->isoFormat('MMMM YYYY')) }}</h3>
                        <ol class="month-grid">
                            @foreach ($weekdays as $weekday)
                                <li class="weekday" aria-hidden="true">{{ $weekday }}</li>
                            @endforeach
                            @for ($pad = 1; $pad < $month->dayOfWeekIso; $pad++)
                                <li class="pad" aria-hidden="true"></li>
                            @endfor
                            @for ($d = 1; $d <= $month->daysInMonth; $d++)
                                @php
                                    $date = $month->copy()->day($d)->format('Y-m-d');
                                    $state = $days[$date] ?? null;
                                    $label = $fmt->day($date).': '.($state === null ? __('status.calendar_outside') : __('status.states.'.$state));
                                @endphp
                                <li class="cell {{ $state === null ? 'outside' : '' }}" style="--state: var(--{{ $state ?? 'no_data' }})" title="{{ $label }}">
                                    <span aria-hidden="true">{{ $d }}</span><span class="sr-only">{{ $label }}</span>
                                </li>
                            @endfor
                        </ol>
                    </div>
                @endforeach
            </div>
            <ul class="legend">
                @foreach (['operational', 'degraded', 'major_outage', 'no_data'] as $state)
                    <li>@include('status.partials.state', ['state' => $state, 'label' => __('status.states.'.$state)])</li>
                @endforeach
            </ul>
            <p class="card-link plain"><a href="{{ ($base ?: '/') }}">← {{ __('status.back_to_status') }}</a></p>
        </section>
@endsection
