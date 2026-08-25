<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Finance Manager') }}</title>
    <meta name="description" content="Plan your salary, control your weekly spending and watch your debt come down.">

    {{-- Progressive web app --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Finance">
    <link rel="apple-touch-icon" href="/icons/icon-180.png">
    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg">

    {{-- Apply the saved theme before first paint so the page never flashes. --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('fm-theme') || 'system';
                var dark = stored === 'dark' || (stored === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {
                /* Private mode or storage disabled — fall back to light. */
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="h-full bg-surface text-ink antialiased">
    <div id="app" class="h-full"></div>

    <noscript>
        <div style="padding:2rem;font-family:system-ui;text-align:center">
            <h1>JavaScript is required</h1>
            <p>Finance Manager needs JavaScript enabled to run.</p>
        </div>
    </noscript>
</body>
</html>
