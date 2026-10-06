@extends('booth.layout')

@section('page', 'booth-wait')
@section('label', $booth->election->name)
@section('heading', $booth->name().' · '.$booth->unit->name)

@section('content')
    <main class="wrap">
        <section class="card center" role="status">
            <div class="pulse" aria-hidden="true"></div>
            <h1>Menunggu pemilih</h1>
            <p class="muted">Layar akan terbuka sendiri setelah petugas di meja mengizinkan pemilih.</p>
        </section>
        <p class="muted center">{{ $booth->code() }} · {{ $booth->label }}</p>
    </main>
@endsection
