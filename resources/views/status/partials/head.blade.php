{{-- Shared head for status pages. Root-relative paths, so they resolve on a parked custom domain too. --}}
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/status.css?v={{ @filemtime(public_path('css/status.css')) ?: 1 }}">
