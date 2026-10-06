@extends('public.layout')

@section('title', 'Hasil Pemilihan')
@section('heading', config('app.name'))

@section('content')
    <section class="card">
        <h1>Hasil pemilihan</h1>
        <p class="muted">Halaman ini hanya menampilkan hasil resmi yang sudah diumumkan panitia.</p>
    </section>

    @forelse ($elections as $election)
        <a class="result" href="{{ route('public.show', $election->public_id) }}">
            <span class="result__name">{{ $election->name }}</span>
            <span class="result__detail">
                {{ $election->closed_at?->translatedFormat('d F Y') }}
                @if ($election->status === \App\Enums\ElectionStatus::Unpublished)
                    · sedang ditinjau ulang
                @endif
            </span>
        </a>
    @empty
        <section class="card center">
            <p class="muted">Belum ada hasil yang diumumkan.</p>
        </section>
    @endforelse
@endsection
