@extends('voter.layout', ['page' => 'done'])

@section('body-attributes')
    data-auto-return="{{ $status['assisted'] ? '1' : '0' }}"
@endsection

@section('header')
@endsection

@section('content')
    <main class="done" role="status">
        <div>
            <div class="done__icon" aria-hidden="true">✓</div>

            @if ($already)
                <h1>Anda sudah memilih</h1>
                <p>Suara Anda sudah tersimpan sebelumnya. Terima kasih.</p>
            @else
                <h1>Sudah memilih</h1>
                <p>Suara Anda sudah tersimpan. Terima kasih.</p>
            @endif

            <a class="btn btn--ghost" href="{{ route('voter.show', $election->access_code) }}">Selesai</a>

            @if ($status['assisted'])
                <p>Layar akan kembali ke awal untuk pemilih berikutnya.</p>
            @endif
        </div>
    </main>
@endsection
