<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ $page->title }}</title>
    @include('status.partials.head')
</head>
<body class="notice-page">
<main>
    <div class="card">
        <p class="page">{{ $page->title }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
        @if ($form)
            <form method="post" action="{{ $form['action'] }}">
                @csrf
                <button type="submit" class="btn">{{ $form['button'] }}</button>
            </form>
        @endif
        <p class="back"><a href="{{ $pageUrl }}">{{ __('status.back_to_page') }}</a></p>
    </div>
</main>
</body>
</html>
