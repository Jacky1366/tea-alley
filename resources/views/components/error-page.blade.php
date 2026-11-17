@props([
    'code',
    'en',  // [title, message], already translated to English
    'zh',  // [title, message], already translated to Traditional Chinese
])

{{--
    T3-3: every error page (resources/views/errors). Standalone on purpose:
    - no database, session, or logged-in user: a 500 may be the database being down, and a
      404 for an unknown URL or a 419 happens before the session and language are set up;
    - so it shows English and Traditional Chinese together instead of guessing the language;
    - it never shows the exception's message, a stack trace, or SQL.
    Each page translates literal keys itself, so TranslationsTest can check them.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ $code }} · {{ $en[0] }} · {{ config('app.name', 'Tea Alley') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-white text-zinc-800 antialiased dark:bg-zinc-800 dark:text-zinc-100">
        <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center gap-8 px-6 py-12" data-test="error-page" data-code="{{ $code }}">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold" aria-label="Tea Alley 茶巷">
                <span class="flex size-8 items-center justify-center rounded-md bg-zinc-800 text-white dark:bg-white dark:text-zinc-900">
                    <x-app-logo-icon class="size-5" />
                </span>
                Tea Alley 茶巷
            </a>

            <p class="text-6xl font-bold tracking-tight text-zinc-300 dark:text-zinc-600" aria-hidden="true">{{ $code }}</p>

            <section lang="en" class="flex flex-col gap-2" data-test="error-en">
                <h1 class="text-2xl font-semibold">{{ $en[0] }}</h1>
                <p class="text-zinc-600 dark:text-zinc-300">{{ $en[1] }}</p>
            </section>

            <section lang="zh-Hant" class="flex flex-col gap-2" data-test="error-zh">
                <h2 class="text-2xl font-semibold">{{ $zh[0] }}</h2>
                <p class="text-zinc-600 dark:text-zinc-300">{{ $zh[1] }}</p>
            </section>

            <div>
                <a href="{{ url('/') }}" class="inline-flex items-center rounded-lg bg-zinc-800 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200" data-test="error-home">
                    {{ __('Back to the menu', [], 'en') }} · <span lang="zh-Hant">{{ __('Back to the menu', [], 'zh_TW') }}</span>
                </a>
            </div>
        </main>
    </body>
</html>
