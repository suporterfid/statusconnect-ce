@php
    $history = $component['history'] ?? [];
    $last = count($history) - 1;
@endphp
@if ($history !== [])
    <ol class="bars" aria-label="{{ __('status.history_for', ['component' => $component['name']]) }}">
        @foreach ($history as $i => $entry)
            @php
                $label = isset($entry['uptime']) && $entry['uptime'] !== null
                    ? __('status.day_uptime', ['date' => $fmt->day($entry['date']), 'uptime' => $fmt->pct($entry['uptime'])])
                    : ($entry['state'] === 'no_data'
                        ? __('status.day_no_data', ['date' => $fmt->day($entry['date'])])
                        : $fmt->day($entry['date']) . ': ' . __('status.states.' . $entry['state']));
                if (($entry['downtime_seconds'] ?? 0) > 0) {
                    $label .= "\n" . __('status.day_downtime', ['duration' => $fmt->duration((int) $entry['downtime_seconds'])]);
                }
                if (($entry['no_data_seconds'] ?? 0) >= 60) {
                    $label .= "\n" . __('status.day_gap', ['duration' => $fmt->duration((int) $entry['no_data_seconds'])]);
                }
                $edge = $i >= $last - 6 ? 'edge-end' : ($i <= 6 || $i === 60 ? 'edge-start' : '');
            @endphp
            <li tabindex="0" class="{{ $edge }}" style="--state: var(--{{ $entry['state'] }})" data-tip="{{ $label }}"><span class="sr-only">{{ $label }}</span></li>
        @endforeach
    </ol>
    <div class="bars-legend" aria-hidden="true">
        <span><span class="full">{{ $fmt->day($history[0]['date']) }}</span><span class="short" style="display:none">{{ $fmt->day($history[min(60, $last)]['date']) }}</span></span>
        <span>{{ $fmt->day($history[$last]['date']) }}</span>
    </div>
    <p class="sr-only">{{ __('status.history') }}</p>
@endif
