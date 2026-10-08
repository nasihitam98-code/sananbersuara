{{-- ============ CALON ============ --}}
<section id="calon" class="anchor section" aria-labelledby="calon-judul">
    <div class="section__head reveal">
        <div>
            <p class="section__kicker">Kenali calon</p>
            <h2 class="section__title" id="calon-judul">{{ $focus?->name ?? 'Calon' }}</h2>
        </div>
        @if ($focus)
            <a class="section__link" href="{{ route('public.candidates', $focus->public_id) }}">Halaman calon →</a>
        @endif
    </div>

    @if ($focus && $ballots->isNotEmpty())
        <div class="reveal">
            @include('public.partials.candidate-grid', ['ballots' => $ballots])
        </div>
    @else
        <div class="empty reveal">
            <p>Daftar calon tampil di sini setelah pemilihan disiapkan panitia.</p>
        </div>
    @endif
</section>
