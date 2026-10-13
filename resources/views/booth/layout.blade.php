<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1f1d5c">
    <title>@yield('title', 'Bilik') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ \App\Models\AppSetting::faviconUrl() }}">
    @vite(['resources/css/voter.css', 'resources/js/voter.js'])
</head>
<body
    data-page="@yield('page')"
    data-status-url="{{ route('booth.status') }}"
    data-start-url="{{ route('booth.show') }}"
    data-ballot-url="{{ route('booth.ballot') }}"
    data-touch-url="{{ route('booth.touch') }}"
>
    @section('header')
        <header class="topbar topbar--brand">
            @include('voter.partials.brand-mark')
            <div>
                <p class="topbar__label">@yield('label', 'Bilik suara')</p>
                <p class="topbar__title">@yield('heading', config('app.name'))</p>
            </div>
        </header>
    @show

    @yield('content')
</body>
</html>
