{{-- Potret calon: foto persegi membulat berbingkai; tanpa foto tampil siluet orang (bukan inisial). --}}
<span class="portrait {{ $class ?? '' }}">
    @if ($candidate->photoUrl('card'))
        <img class="portrait__img" src="{{ $candidate->photoUrl('card') }}" alt="Foto {{ $candidate->name }}" width="480" height="480" loading="lazy" decoding="async">
    @else
        <svg class="portrait__silhouette" viewBox="0 0 100 100" role="img" aria-label="Belum ada foto {{ $candidate->name }}">
            <circle cx="50" cy="38" r="19"></circle>
            <path d="M14 100c0-21 16-36 36-36s36 15 36 36z"></path>
        </svg>
    @endif
    <span class="portrait__number" aria-hidden="true">{{ $candidate->displayNumber() }}</span>
</span>
