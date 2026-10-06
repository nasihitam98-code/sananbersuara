@php($photo = $candidate->photoUrl($size ?? 'thumb'))
@php($responsive = $responsive ?? false)
<span class="photo">
    @if ($photo && $responsive)
        <img
            src="{{ $candidate->photoUrl('card') }}"
            srcset="{{ $candidate->photoUrl('thumb') }} 160w, {{ $candidate->photoUrl('card') }} 480w"
            sizes="(max-width: 640px) 46vw, 300px"
            alt="Foto {{ $candidate->name }}"
            width="480"
            height="480"
            loading="lazy"
            decoding="async"
        >
    @elseif ($photo)
        <img src="{{ $photo }}" alt="Foto {{ $candidate->name }}" width="160" height="160" decoding="async">
    @else
        <span class="photo__initials" aria-hidden="true">{{ $candidate->initials() }}</span>
    @endif
    <span class="badge" aria-hidden="true">{{ $candidate->displayNumber() }}</span>
</span>
