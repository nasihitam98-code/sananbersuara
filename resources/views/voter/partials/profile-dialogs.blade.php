{{-- Jendela visi & misi per calon (dibuka tombol "Lihat visi & misi" di kartu calon; lihat voter.js). --}}
@foreach ($candidates as $candidate)
    @if ($candidate->hasProfile())
        <dialog class="profile" id="profile-{{ $candidate->public_id }}" aria-labelledby="profile-title-{{ $candidate->public_id }}">
            <div class="profile__head">
                @include('voter.partials.photo', ['candidate' => $candidate, 'size' => 'thumb'])
                <div>
                    <p class="candidate__number">Nomor {{ $candidate->displayNumber() }}</p>
                    <p class="profile__name" id="profile-title-{{ $candidate->public_id }}">{{ $candidate->name }}</p>
                    @if ($candidate->originLabel())
                        <p class="candidate__origin">Asal {{ $candidate->originLabel() }}</p>
                    @endif
                </div>
            </div>

            @if (filled($candidate->vision))
                <h2 class="profile__label">Visi</h2>
                <p class="profile__text">{{ $candidate->vision }}</p>
            @endif

            @if ($points = $candidate->missionPoints())
                <h2 class="profile__label">Misi</h2>
                <ol class="profile__list">
                    @foreach ($points as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ol>
            @endif

            <button type="button" class="btn btn--primary" data-profile-close>Tutup</button>
        </dialog>
    @endif
@endforeach
