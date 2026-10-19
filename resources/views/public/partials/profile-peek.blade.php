{{-- Visi & misi calon di bagian "Kenali calon" (bila dinyalakan panitia). Pakai <details>: tanpa JavaScript, aman untuk CSP ketat. --}}
@if ($candidate->hasProfile())
    <details class="profile-peek">
        <summary class="profile-peek__toggle">Visi &amp; misi</summary>
        <div class="profile-peek__body">
            @if (filled($candidate->vision))
                <p class="profile-peek__label">Visi</p>
                <p class="profile-peek__text">{{ $candidate->vision }}</p>
            @endif

            @if ($points = $candidate->missionPoints())
                <p class="profile-peek__label">Misi</p>
                <ol class="profile-peek__list">
                    @foreach ($points as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ol>
            @endif
        </div>
    </details>
@endif
