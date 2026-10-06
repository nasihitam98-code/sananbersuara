@extends('voter.layout', ['page' => 'ballot'])

@section('content')
    <main class="wrap">
        @if ($status['state'] === 'open')
            @include('voter.partials.timer')
        @endif

        <form method="POST" action="{{ route('voter.cast', $election->access_code) }}" data-ballot-form data-once>
            @csrf
            <input type="hidden" name="ballot" value="{{ $ballot->public_id }}">

            <div data-step="choose">
                <section class="card">
                    <span class="step">Langkah 3 dari 3{{ $ballotTotal > 1 ? " · Surat suara {$ballotIndex} dari {$ballotTotal}" : '' }}</span>
                    <h1>{{ $ballot->title }}</h1>
                    <p class="muted">Sentuh <strong>satu</strong> calon pilihan Anda, lalu tekan <strong>LANJUT</strong>.</p>
                </section>

                @error('candidate')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror

                <fieldset class="candidates candidates--grid">
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
                                <span class="candidate__check" aria-hidden="true">✓</span>
                                @include('voter.partials.photo', ['candidate' => $candidate, 'responsive' => true])
                                <span class="candidate__text">
                                    <span class="candidate__number">Nomor {{ $candidate->displayNumber() }}</span>
                                    <span class="candidate__name">{{ $candidate->name }}</span>
                                    @if ($candidate->originLabel())
                                        <span class="candidate__origin">Asal {{ $candidate->originLabel() }}</span>
                                    @endif
                                    @if ($candidate->status->value === 'MUNDUR')
                                        <span class="tag">Mengundurkan diri</span>
                                    @endif
                                </span>
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
