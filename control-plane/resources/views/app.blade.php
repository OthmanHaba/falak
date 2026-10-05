@php
    // Theme (dark default · light · system) persisted in the unencrypted `appearance` cookie so the very first
    // paint already has the right palette. "system" is resolved by the inline script before CSS applies.
    $appearance = in_array(request()->cookie('appearance'), ['dark', 'light', 'system'], true) ? request()->cookie('appearance') : 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $appearance === 'light' ? 'light' : 'dark' }}" data-appearance="{{ $appearance }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark light">
        @if (config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
            {{-- Runtime Reverb key for prebuilt images (VITE_REVERB_* are build-time); the key is public. --}}
            <meta name="falak-reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        @endif

        @if ($appearance === 'system')
            <script>
                (function () {
                    var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.classList.toggle('light', !dark);
                })();
            </script>
        @endif

        <title inertia>{{ config('app.name', 'Falak') }}</title>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
