<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{-- Page titles are English keys (#[Title('Menu')]) or already translated; translated here. --}}
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Tea Alley') : config('app.name', 'Tea Alley') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
