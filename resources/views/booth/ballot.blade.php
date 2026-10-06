@extends('booth.layout')

@section('page', 'booth-ballot')
@section('label', $booth->name())
@section('heading', $ballot->title)

@section('content')
    <main class="wrap">
        <form method="POST" action="{{ route('booth.cast') }}" data-ballot-form data-once>
            @csrf
            <input type="hidden" name="ballot" value="{{ $ballot->public_id }}">

            <div data-step="choose">
                <section class="card">
                    @if ($ballotTotal > 1)
                        <span class="step">Surat suara {{ $ballotIndex }} dari {{ $ballotTotal }}</span>
                    @endif
                    <h1>{{ $ballot->title }}</h1>
                    <p class="muted">Sentuh <strong>satu</strong> calon pilihan Anda, lalu tekan <strong>LANJUT</strong>.</p>
                </section>

                @error('candidate')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror

                <fieldset class="candidates">
                    <legend class="sr-only">Daftar calon {{ $ballot->title }}</legend>

                    @foreach ($candidates as $candidate)
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
                                @include('voter.partials.photo', ['candidate' => $candidate, 'size' => 'thumb'])
                                <span>
                                    <span class="candidate__number">Nomor {{ $candidate->displayNumber() }}</span><br>
                                    <span class="candidate__name">{{ $candidate->name }}</span>
                                    @if ($candidate->originLabel())
                                        <br><span class="candidate__origin">Asal {{ $candidate->originLabel() }}</span>
                                    @endif
                                    @if ($candidate->status->value === 'MUNDUR')
                                        <br><span class="tag">Mengundurkan diri</span>
                                    @endif
                                </span>
                                <span class="candidate__check" aria-hidden="true">✓</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            </div>

            <section class="card review hidden" data-step="review" aria-live="polite">
                <h1 tabindex="-1">Periksa pilihan Anda</h1>
                <p class="muted">Anda memilih:</p>
                <span class="photo" data-review-photo></span>
                <p class="candidate__number" data-review-number></p>
                <p class="review__name" data-review-name></p>
                <p class="candidate__origin" data-review-origin></p>
                <p class="review__question">Sudah benar?</p>
                <p class="muted">Setelah dikonfirmasi, pilihan tidak bisa diubah.</p>
            </section>

            <div class="actionbar">
                <div class="actionbar__inner" data-bar="choose">
                    <p class="actionbar__hint">Pilih satu calon terlebih dahulu</p>
                    <button type="button" class="btn btn--primary" data-next disabled>LANJUT</button>
                </div>

                <div class="actionbar__inner stack hidden" data-bar="review">
                    <button type="submit" class="btn btn--confirm">✓ KONFIRMASI PILIHAN</button>
                    <button type="button" class="btn btn--ghost" data-back>KEMBALI, ganti pilihan</button>
                </div>
            </div>
        </form>
    </main>
@endsection
