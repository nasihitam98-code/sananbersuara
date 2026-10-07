@extends('public.layout')

@section('title', 'Kenali calon · '.$election->name)
@section('heading', $election->name)

@section('content')
    <section class="card">
        <h1>Kenali calon</h1>
        <p class="muted">Nama, nomor urut, dan visi &amp; misi calon. Pilihan Anda rahasia; satu orang satu suara.</p>
    </section>

    @foreach ($ballots as $ballot)
        <section class="card">
            <h2>{{ $ballot['title'] }}</h2>

            <div class="profiles">
                @forelse ($ballot['candidates'] as $candidate)
                    <article class="profile-card">
                        @include('voter.partials.photo', ['candidate' => $candidate, 'size' => 'card'])
                        <div>
                            <p class="candidate__number">Nomor {{ $candidate->displayNumber() }}@if ($candidate->unit) · {{ $candidate->unit->name }}@endif</p>
                            <p class="profile__name">{{ $candidate->name }}</p>
                            @if ($candidate->originLabel())
                                <p class="candidate__origin">Asal {{ $candidate->originLabel() }}</p>
                            @endif

                            @if (filled($candidate->vision))
                                <h3 class="profile__label">Visi</h3>
                                <p class="profile__text">{{ $candidate->vision }}</p>
                            @endif

                            @if ($points = $candidate->missionPoints())
                                <h3 class="profile__label">Misi</h3>
                                <ol class="profile__list">
                                    @foreach ($points as $point)
                                        <li>{{ $point }}</li>
                                    @endforeach
                                </ol>
                            @endif

                            @if (! $candidate->hasProfile())
                                <p class="muted">Visi &amp; misi belum diisi.</p>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="muted">Belum ada calon.</p>
                @endforelse
            </div>
        </section>
    @endforeach

    <p class="center"><a href="{{ route('public.index') }}">← Kembali</a></p>
@endsection
