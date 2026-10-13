{{--
    Satu pilihan calon di surat suara (HP Mode Dadakan dan bilik Mode Resmi), bentuknya mengikuti kartu calon di portal:
    foto bulat berbingkai, nomor, nama, asal RT. Atribut data-* dibaca voter.js untuk layar "Periksa pilihan".
--}}
<label
    class="candidate"
    data-number="{{ $candidate->displayNumber() }}"
    data-name="{{ $candidate->name }}"
    data-origin="{{ $candidate->originLabel() }}"
    data-initials="{{ $candidate->initials() }}"
    data-photo-large="{{ $candidate->photoUrl('card') }}"
>
    <input type="radio" name="candidate" value="{{ $candidate->public_id }}" required>
    <span class="candidate__body">
        <span class="candidate__check" aria-hidden="true">✓</span>
        @include('voter.partials.photo', ['candidate' => $candidate, 'responsive' => true])
        <span class="candidate__text">
            <span class="candidate__number">Nomor {{ $candidate->displayNumber() }}</span>
            <span class="candidate__name">{{ $candidate->name }}</span>
            @if ($candidate->originLabel())
                <span class="candidate__origin">Asal {{ $candidate->originLabel() }}</span>
            @endif
            @include('voter.partials.profile-button')
            @if ($candidate->status->value === 'MUNDUR')
                <span class="tag">Mengundurkan diri</span>
            @endif
        </span>
    </span>
</label>
