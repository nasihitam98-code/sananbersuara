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
        <a class="topbar__action" href="{{ url('/admin') }}">Masuk Panitia</a>
    </header>

    <main class="wrap">
        @yield('content')
    </main>

    <footer class="portal-footer">
        <p>Panitia dan pengurus RT: <a href="{{ url('/admin') }}">masuk ke panel admin</a>.</p>
    </footer>
</body>
</html>
