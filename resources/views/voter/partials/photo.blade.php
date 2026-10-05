@php($photo = $candidate->photoUrl($size ?? 'thumb'))
<span class="photo">
    @if ($photo)
        <img src="{{ $photo }}" alt="Foto {{ $candidate->name }}" width="160" height="160" decoding="async">
    @else
        <span class="photo__initials" aria-hidden="true">{{ $candidate->initials() }}</span>
    @endif
    <span class="badge" aria-hidden="true">{{ $candidate->displayNumber() }}</span>
</span>
