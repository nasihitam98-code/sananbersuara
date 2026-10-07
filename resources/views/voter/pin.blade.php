@extends('voter.layout', ['page' => 'pin'])

@section('content')
    <main class="wrap">
        @if ($status['state'] === 'open')
            @include('voter.partials.timer')
        @endif

        <section class="card">
            <span class="step">Langkah 2 dari 3</span>
            <h1>Masukkan PIN</h1>
            <p class="muted">Nama yang Anda pilih:</p>
            <p class="result__name">{{ $attendee->name }}</p>
            <p class="muted">{{ $attendee->unit?->name ?? 'No. hadir '.$attendee->displayNumber() }}</p>

            <form method="POST" action="{{ route('voter.pin.verify', [$election->access_code, $attendee->public_id]) }}" class="stack" data-once>
                @csrf

                <div>
                    <label for="pin">PIN dari kertas Anda</label>
                    <input
                        id="pin"
                        name="pin"
                        class="input input--pin"
                        type="password"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="6"
                        autocomplete="off"
                        required
                        autofocus
                    >
                </div>

                @error('pin')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn btn--primary">MASUK</button>
            </form>

            @include('voter.partials.privacy-notice')
        </section>

        <a class="btn btn--ghost" href="{{ route('voter.show', $election->access_code) }}">Bukan nama saya, kembali</a>
    </main>
@endsection
