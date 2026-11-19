@extends('mail.subscriber.layout')
@section('message')
    @if ($incidentKind === 'incident_opened')
        @include('mail.partials.state', ['state' => 'down', 'label' => __('status.states.major_outage')])
        <p style="margin:0;">{{ __('status.subscribe_mail_incident_opened_body', ['components' => $components, 'started' => $startedAt, 'tz' => $timezone]) }}</p>
    @else
        @include('mail.partials.state', ['state' => 'ok', 'label' => __('status.states.operational')])
        <p style="margin:0;">{{ __('status.subscribe_mail_incident_resolved_body', ['components' => $components, 'resolved' => $resolvedAt, 'tz' => $timezone, 'duration' => $duration]) }}</p>
    @endif
@endsection
