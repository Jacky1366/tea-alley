<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
{{-- Echo (resources/js/echo.js) sends this with its /broadcasting/auth request. --}}
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{-- Page titles are English keys (#[Title('Menu')]) or already translated; translated here. --}}
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Tea Alley') : config('app.name', 'Tea Alley') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Customer layouts (#62) also load Fraunces for headings; staff pages only Instrument Sans, as before. --}}
@if ($customerTheme ?? false)
    <meta name="color-scheme" content="light" />
    @fonts
@else
    @fonts('instrument-sans')
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
