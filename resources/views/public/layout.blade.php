<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f1d5c">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite(['resources/css/voter.css'])
</head>
<body>
    <header class="topbar topbar--portal">
        <div>
            <p class="topbar__label"><a href="{{ route('public.index') }}">Portal pemilihan</a></p>
            <p class="topbar__title">@yield('heading')</p>
        </div>
        <a class="topbar__lock" href="{{ url('/admin') }}" aria-label="Masuk panitia dan pengurus" title="Masuk panitia dan pengurus">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
            </svg>
        </a>
    </header>

    <main class="wrap">
        @yield('content')
    </main>
</body>
</html>
