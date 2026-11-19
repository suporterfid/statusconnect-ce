@php
    $fmt = new \App\Http\Views\StatusPageFormat($snapshot);
    $feed = new \App\Http\Views\StatusPageFeed($snapshot, $slug, $fmt, $origin.$base);
    $entries = $feed->entries();
@endphp
{!! '<'.'?xml version="1.0" encoding="utf-8"?'.'>' !!}
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="{{ str_replace('_', '-', $snapshot['locale']) }}">
    <title>{{ $snapshot['title'] }}</title>
    <subtitle>{{ __('status.feed_subtitle') }}</subtitle>
    <id>{{ $feed->feedId() }}</id>
    <link rel="alternate" type="text/html" href="{{ $origin.($base ?: '/') }}"/>
    <link rel="self" type="application/atom+xml" href="{{ $origin.$base.'/feed' }}"/>
    <updated>{{ $feed->updated($entries) }}</updated>
    <author><name>{{ $snapshot['title'] }}</name></author>
    <generator uri="{{ rtrim((string) config('app.url'), '/').'/' }}">StatusConnect</generator>
@foreach ($entries as $entry)
    <entry>
        <id>{{ $entry['id'] }}</id>
        <title>{{ $entry['title'] }}</title>
        <link rel="alternate" type="text/html" href="{{ $entry['link'] }}"/>
        <published>{{ $entry['published'] }}</published>
        <updated>{{ $entry['updated'] }}</updated>
@if ($entry['content'] !== '')
        <content type="text">{{ $entry['content'] }}</content>
@endif
    </entry>
@endforeach
</feed>
