<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1f1d5c">
    <title>{{ $election->name }} · {{ config('app.name') }}</title>
    @vite(['resources/css/voter.css', 'resources/js/voter.js'])
</head>
<body
    data-page="{{ $page }}"
    data-status-url="{{ \App\Services\Voting\StatusPublisher::urlFor($election) }}"
    data-status-fallback-url="{{ route('voter.status', $election->access_code) }}"
    data-start-url="{{ route('voter.show', $election->access_code) }}"
    data-poll-seconds="{{ config('voting.poll_seconds') }}"
    data-state="{{ $status['state'] ?? '' }}"
    data-remaining="{{ ($status['state'] ?? null) === 'open' ? $status['remaining'] : '' }}"
    @yield('body-attributes')
>
    @section('header')
        <header class="topbar">
            <p class="topbar__label">Pemungutan suara</p>
            <p class="topbar__title">{{ $election->name }}</p>
        </header>
    @show

    @yield('content')
</body>
</html>
