@if ($items === [])
    <p class="empty">{{ $empty }}</p>
@else
    <ol class="list updates">
        @foreach ($items as $item)
            @php($impact = in_array($item['impact'] ?? 'none', ['degraded', 'major_outage', 'maintenance'], true) ? $item['impact'] : 'none is-muted')
            <li>
                <div class="title">
                    @include('status.partials.state', ['state' => ($item['status'] ?? '') === 'resolved' ? 'operational' : $impact, 'label' => $fmt->label('update_statuses', $item['status'] ?? null)])
                    <span>{{ $item['title'] }}</span>
                </div>
                @if (! empty($item['body']))
                    <div class="update-body">{!! nl2br(e($item['body'])) !!}</div>
                @endif
                <div class="meta">
                    {{ $fmt->dateTime($item['published_at']) }}
                    @if (! empty($item['edited_at']))
                        · {{ __('status.update_edited', ['at' => $fmt->dateTime($item['edited_at'])]) }}
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
