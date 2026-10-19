{{--
    Jendela visi & misi calon (dibuka tombol "Detail" di kartu Kenali calon dan quick count; lihat portal.js).
    Dicetak sekali per calon di luar kartu, sehingga kartu RT yang disematkan (salinan kartu) tetap membuka jendela yang sama.
--}}
@foreach (collect($cards)->flatMap(fn (array $card): array => array_column($card['tally']['rows'], 'candidate'))->unique('id') as $candidate)
    @if ($candidate->hasProfile())
        <dialog class="profile-dialog" id="profil-{{ $candidate->public_id }}" aria-labelledby="profil-judul-{{ $candidate->public_id }}">
            <div class="profile-dialog__head">
                @include('public.partials.portrait', ['candidate' => $candidate, 'class' => 'portrait--blue'])
                <div>
                    <p class="profile-dialog__meta">Nomor {{ $candidate->displayNumber() }}@if ($candidate->originLabel()) · {{ $candidate->originLabel() }}@endif</p>
                    <p class="profile-dialog__name" id="profil-judul-{{ $candidate->public_id }}">{{ $candidate->name }}</p>
                </div>
            </div>

            @if (filled($candidate->vision))
                <h3 class="profile-dialog__label">Visi</h3>
                <p class="profile-dialog__text">{{ $candidate->vision }}</p>
            @endif

            @if ($points = $candidate->missionPoints())
                <h3 class="profile-dialog__label">Misi</h3>
                <ol class="profile-dialog__list">
                    @foreach ($points as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ol>
            @endif

            <button type="button" class="button button--primary profile-dialog__close" data-profile-close>Tutup</button>
        </dialog>
    @endif
@endforeach
