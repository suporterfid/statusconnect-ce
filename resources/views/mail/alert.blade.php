{{-- Operator alert (email channel): incident opened / resolved / test. --}}
@extends('mail.layout')

@php
    $event = (string) ($payload['event'] ?? 'notification');
    [$state, $label] = match ($event) {
        'opened' => ['down', __('alerts.opened')],
        'resolved' => ['ok', __('alerts.resolved')],
        'test' => ['check', __('alerts.test')],
        default => ['check', __('alerts.other')],
    };
    $when = static function (?string $iso): ?string {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y H:i').' UTC';
        } catch (\Throwable) {
            return $iso;
        }
    };
    $rows = array_filter([
        __('alerts.started') => $when($payload['started_at'] ?? null),
        __('alerts.resolved_at') => $event === 'resolved' ? $when($payload['resolved_at'] ?? null) : null,
        __('alerts.duration') => ($event === 'resolved' && ! empty($payload['duration_seconds']))
            ? (($m = max(1, (int) round($payload['duration_seconds'] / 60))) < 60 ? $m.' min' : intdiv($m, 60).' h '.($m % 60).' min')
            : null,
    ]);
@endphp

@section('band_title', 'StatusConnect')

@section('body')
    @include('mail.partials.state', ['state' => $state, 'label' => $label])
    <p style="margin:0 0 4px;font-size:13px;color:#5F5F5F;">{{ __('alerts.monitor') }}</p>
    <p style="margin:0 0 12px;font-size:20px;line-height:1.3;font-weight:700;">{{ $payload['monitor_name'] ?? 'Monitor' }}</p>
    <p style="margin:0 0 16px;">{{ $payload['summary'] ?? '' }}</p>
    @if ($rows !== [])
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-top:1px solid #D9D7D3;">
            @foreach ($rows as $name => $value)
                <tr>
                    <td style="padding:8px 12px 8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;color:#5F5F5F;width:120px;">{{ $name }}</td>
                    <td style="padding:8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;font-weight:600;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @include('mail.partials.button', ['url' => rtrim((string) config('app.url'), '/').'/app', 'label' => __('alerts.open_app')])
@endsection

@section('footer')
    <p style="margin:0;">{{ __('alerts.footer') }}</p>
@endsection
