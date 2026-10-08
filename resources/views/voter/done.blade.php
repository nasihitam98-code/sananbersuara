@extends('voter.layout', ['page' => 'done'])

@section('body-attributes')
    data-auto-return="15"
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

            {{-- HP boleh dipinjamkan: layar kembali ke awal sendiri agar orang berikutnya bisa langsung memilih. --}}
            <p class="done__return">Layar kembali ke awal dalam <strong data-return-seconds>15</strong> detik. HP ini boleh dipinjamkan ke warga berikutnya.</p>
        </div>
    </main>
@endsection
