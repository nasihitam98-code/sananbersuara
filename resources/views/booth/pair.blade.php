@extends('booth.layout')

@section('page', 'booth-pair')
@section('title', 'Pasang '.$kind->getLabel())
@section('label', 'Pemasangan perangkat')
@section('heading', 'Pasang laptop '.$kind->getLabel())

@section('content')
    <main class="wrap">
        <section class="card">
            <h1>Masukkan token {{ strtolower($kind->getLabel()) }}</h1>
            <p class="muted">
                @if ($kind->value === 'MEJA')
                    Lebih cepat tanpa token: login akun RT di laptop ini, buka Meja Izin, klik <strong>Jadikan laptop ini Meja RT</strong>. Atau ketik token Meja dari Super Admin di sini.
                @else
                    Token bilik dibuat petugas di laptop Meja (menu Meja Izin → Bilik). Token berlaku 10 menit dan sekali pakai.
                @endif
            </p>

            {{-- Kenapa laptop ini diminta token lagi (dilepas, diganti, pemilihan ditutup); tanpa keterangan = belum pernah dipasang di browser ini. --}}
            @if (! empty($why))
                <p class="error" role="status">{{ $why }}</p>
            @elseif ($kind->value !== 'MEJA')
                <p class="muted">Sudah pernah dipasang tapi diminta token lagi? Biasanya karena memakai jendela Incognito/Tamu atau data browser terhapus. Pakai jendela Chrome biasa.</p>
            @endif

            <form method="POST" action="{{ route('booth.pair', $kind->value === 'MEJA' ? 'meja' : 'bilik') }}" class="stack" data-once>
                @csrf
                <div>
                    <label for="token">Token</label>
                    <input id="token" name="token" class="input input--pin" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" required autofocus placeholder="XXXX-XXXX">
                </div>
                <div>
                    <label for="label">Nama/label laptop</label>
                    <input id="label" name="label" class="input" maxlength="120" required value="{{ old('label') }}" placeholder="Contoh: Asus Vivobook Pak Budi">
                </div>

                @error('token')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror
                @error('label')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn btn--primary">PASANG</button>
            </form>
        </section>
    </main>
@endsection
