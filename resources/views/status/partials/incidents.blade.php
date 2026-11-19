<div class="card">
    @if ($incidents === [])
        <p class="empty">{{ __('status.no_incidents') }}</p>
    @else
        <ol class="list">
            @foreach ($incidents as $incident)
                @php $resolved = $incident['resolved_at'] !== null; @endphp
                <li>
                    <div class="title">
                        @include('status.partials.state', ['state' => $incident['state'].($resolved ? ' is-muted' : ''), 'label' => __('status.states.' . $incident['state'])])
                        @unless ($resolved)
                            <span class="meta">{{ __('status.incident_ongoing') }}</span>
                        @endunless
                    </div>
                    <div class="meta">
                        {{ __('status.incident_started', ['at' => $fmt->dateTime($incident['started_at'])]) }}
                        @if ($resolved)
                            · {{ __('status.incident_resolved', ['at' => $fmt->dateTime($incident['resolved_at'])]) }}
                        @endif
                    </div>
                    @if (! empty($incident['updates']))
                        <ol>
                            @foreach ($incident['updates'] as $update)
                                <li>{{ __('status.incident_update', ['status' => $fmt->label('update_statuses', $update['status'] ?? null), 'at' => $fmt->dateTime($update['published_at'])]) }}</li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
