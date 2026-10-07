@extends('public.layout')

@section('title', 'Hasil Pemilihan')
@section('heading', config('app.name'))

@section('content')
    @if ($notice = \App\Models\AppSetting::get(\App\Models\AppSetting::PUBLIC_NOTICE))
        <section class="card" role="note">
            <h1>Pengumuman</h1>
            <p>{!! nl2br(e($notice)) !!}</p>
        </section>
    @endif

    @if ($upcoming->isNotEmpty())
        <section class="card">
            <h1>Pemilihan berjalan</h1>
            <p class="muted">Cara memilih diumumkan langsung oleh panitia di lokasi.</p>
        </section>

        @foreach ($upcoming as $election)
            <a class="result" href="{{ route('public.candidates', $election->public_id) }}">
                <span class="result__name">{{ $election->name }}</span>
                <span class="result__detail">{{ $election->status->isLive() ? 'Sedang berlangsung' : 'Segera dimulai' }} · kenali calon &amp; visi-misi →</span>
            </a>
        @endforeach
    @endif

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
                @else
                    · hasil resmi · lihat →
                @endif
            </span>
        </a>
    @empty
        <section class="card center">
            <p class="muted">Belum ada hasil yang diumumkan.</p>
        </section>
    @endforelse
@endsection
