@extends('status.layout')

@php($fmt = new \App\Http\Views\StatusPageFormat($snapshot))

@section('title'){{ __('status.updates_history') }}@endsection

@section('content')
        <section aria-labelledby="history-heading" class="first">
            <h2 id="history-heading" class="section-title">{{ __('status.updates_history') }}</h2>
            <div class="card">
                @include('status.partials.updates', ['items' => $snapshot['update_history'] ?? [], 'empty' => __('status.no_updates')])
            </div>
            <p class="card-link plain"><a href="{{ ($base ?: '/') }}">← {{ __('status.back_to_status') }}</a></p>
        </section>
@endsection
