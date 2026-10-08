{{-- Grid "kenali calon" per surat suara, dengan pencarian bila calon banyak. Tanpa angka suara. --}}
@foreach ($ballots as $ballot)
    <div class="candidate-group" data-candidate-group>
        <div class="section__head section__head--sub">
            <div>
                <h3 class="section__title section__title--sm">{{ $ballot['title'] }}</h3>
                <p class="section__sub">{{ $ballot['candidates']->count() }} calon</p>
            </div>
            @if ($ballot['candidates']->count() > 8)
                <label class="search">
                    <span class="sr-only">Cari nama atau nomor calon</span>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
                    <input type="search" placeholder="Cari nama atau nomor" autocomplete="off" data-candidate-search>
                </label>
            @endif
        </div>

        <div class="grid grid--people">
            @forelse ($ballot['candidates'] as $candidate)
                <article class="profile" data-candidate data-search="{{ \Illuminate\Support\Str::lower($candidate->name.' '.$candidate->displayNumber()) }}">
                    @include('public.partials.portrait', ['candidate' => $candidate])
                    <div class="profile__body">
                        <p class="person__meta">Nomor {{ $candidate->displayNumber() }}@if ($candidate->unit) · {{ $candidate->unit->name }}@endif</p>
                        <h4 class="person__name">{{ $candidate->name }}</h4>
                        @if ($candidate->originLabel())
                            <p class="person__meta">Asal {{ $candidate->originLabel() }}</p>
                        @endif

                        @if ($candidate->hasProfile())
                            <details class="profile__details">
                                <summary>Visi &amp; misi</summary>
                                @if (filled($candidate->vision))
                                    <h5>Visi</h5>
                                    <p>{{ $candidate->vision }}</p>
                                @endif
                                @if ($points = $candidate->missionPoints())
                                    <h5>Misi</h5>
                                    <ol>
                                        @foreach ($points as $point)
                                            <li>{{ $point }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                            </details>
                        @else
                            <p class="profile__empty">Visi &amp; misi belum diisi.</p>
                        @endif
                    </div>
                </article>
            @empty
                <p class="muted">Belum ada calon.</p>
            @endforelse
        </div>
        <p class="search-empty" data-candidate-empty hidden>Tidak ada calon yang cocok.</p>
    </div>
@endforeach
