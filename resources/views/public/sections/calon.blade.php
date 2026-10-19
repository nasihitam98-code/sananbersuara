{{-- ============ CALON (perkenalan, tanpa angka) ============ --}}
{{-- Sejak pemilihan Siap sampai quick count/hasil resmi tampil: foto, nomor, dan nama calon. Bentuk kartu sama dengan hasil. --}}
@php($showcaseCards = $showcase['cards'])
@php($showcaseUnitCards = $showcaseCards->filter(fn (array $card): bool => $card['unit'] !== null)->values())
<section id="calon" class="anchor section" aria-labelledby="calon-judul">
    <div class="section__head reveal">
        <div>
            <p class="section__kicker">Calon</p>
            <h2 class="section__title" id="calon-judul">Kenali calon</h2>
        </div>
    </div>

    <p class="quick-note quick-note--calm reveal" role="note">
        <strong>{{ $showcase['election']->status->isLive() ? 'Voting berlangsung' : 'Segera dimulai' }}</strong>
        <span>
            Calon {{ $showcase['election']->name }}, urut nomor.
            {{ $showcase['election']->setting('public_quick_count')
                ? 'Quick count tampil di sini setelah voting dimulai.'
                : 'Perolehan suara tampil setelah hasil resmi diumumkan panitia.' }}
        </span>
    </p>

    @include('public.partials.result-grid', ['cards' => $showcaseCards, 'unitCards' => $showcaseUnitCards])

    @if ($showcase['election']->setting('public_profiles'))
        @include('public.partials.profile-dialogs', ['cards' => $showcaseCards])
    @endif
</section>
