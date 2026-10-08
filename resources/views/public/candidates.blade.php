@extends('public.layout')

@section('title', 'Kenali calon · '.$election->name)

@section('content')
    <section class="page-head reveal">
        <p class="section__kicker">Kenali calon</p>
        <h1 class="page-head__title">{{ $election->name }}</h1>
        <p class="page-head__text">Nama, nomor urut, asal, dan visi &amp; misi calon. Pilihan Anda rahasia; satu orang satu suara.</p>
    </section>

    <section class="section reveal">
        @include('public.partials.candidate-grid', ['ballots' => $ballots])
    </section>

    <p class="back-links"><a href="{{ route('public.index') }}#pemilihan">← Semua pemilihan</a></p>
@endsection
