<!DOCTYPE html>
{{--
    The single server-rendered document. Everything else is React through
    Inertia.

    The theme is resolved in a blocking inline script BEFORE first paint —
    otherwise a user who chose dark gets a white flash on every navigation,
    which in a product people stare at all day is genuinely unpleasant.
--}}
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ $theme ?? 'system' }}"
    data-density="{{ $density ?? 'compact' }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- An accounting system has nothing to gain from being indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <title inertia>{{ config('app.name', 'My Books') }}</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <script>
        // Runs before paint. Reads the user's stored choice, falling back to
        // the OS. Wrapped because storage access throws outright in some
        // privacy configurations, and a thrown error here would leave the
        // page unstyled.
        (function () {
            try {
                var stored = localStorage.getItem('my-books:theme');
                var resolved = stored === 'dark' || stored === 'light'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', resolved);

                var density = localStorage.getItem('my-books:density');
                if (density === 'compact' || density === 'comfortable') {
                    document.documentElement.setAttribute('data-density', density);
                }
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-surface-sunken text-content antialiased">
    @inertia
</body>
</html>
