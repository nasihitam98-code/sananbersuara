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
    <header class="topbar">
        <p class="topbar__label">Hasil resmi</p>
        <p class="topbar__title">@yield('heading')</p>
    </header>

    <main class="wrap">
        @yield('content')
    </main>
</body>
</html>
