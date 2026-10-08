{{-- Kartu satu pemilihan: status, cara, tanggal, dan tautan yang relevan. --}}
@php($status = $election->status)
@php($isAnnounced = in_array($status, [\App\Enums\ElectionStatus::Published, \App\Enums\ElectionStatus::Unpublished], true))
<article class="election-card">
    <div class="election-card__top">
        @if ($status === \App\Enums\ElectionStatus::Published)
            <span class="chip chip--done">Hasil resmi</span>
        @elseif ($status === \App\Enums\ElectionStatus::Unpublished)
            <span class="chip chip--warn">Sedang ditinjau ulang</span>
        @elseif ($status->isLive())
            <span class="chip chip--live"><span class="chip__dot" aria-hidden="true"></span>Sedang berlangsung</span>
        @else
            <span class="chip chip--soon"><span class="chip__dot" aria-hidden="true"></span>Segera dimulai</span>
        @endif
        <span class="election-card__mode">{{ $election->isDadakan() ? 'Di lokasi · lewat HP' : 'Di TPS · bilik suara' }}</span>
    </div>

    <h3 class="election-card__title">{{ $election->name }}</h3>

    <p class="election-card__date">
        @if ($isAnnounced && $election->closed_at)
            Dilaksanakan {{ ($election->started_at ?? $election->closed_at)->translatedFormat('d F Y') }}
        @elseif ($election->started_at)
            Dimulai {{ $election->started_at->translatedFormat('d F Y') }}
        @else
            Jadwal diumumkan panitia
        @endif
    </p>

    <div class="election-card__actions">
        @if ($status === \App\Enums\ElectionStatus::Published)
            <a class="button button--primary" href="{{ route('public.show', $election->public_id) }}">Lihat hasil</a>
        @elseif ($status === \App\Enums\ElectionStatus::Unpublished)
            <a class="button button--outline" href="{{ route('public.show', $election->public_id) }}">Lihat keterangan</a>
        @endif
        <a class="button {{ $isAnnounced ? 'button--outline' : 'button--primary' }}" href="{{ route('public.candidates', $election->public_id) }}">Kenali calon</a>
    </div>
</article>
