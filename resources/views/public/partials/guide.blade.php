{{-- Cara memilih: alur di lokasi (HP) dan di TPS (bilik), plus kerahasiaan pilihan. --}}
<div class="grid grid--2">
    @foreach ([
        [
            'icon' => 'phone',
            'title' => 'Di lokasi acara, lewat HP',
            'note' => 'Dipakai untuk acara langsung, misalnya penjaringan calon.',
            'steps' => [
                ['Daftar di meja pintu', 'Sebutkan nama dan RT Anda. Petugas memberi kartu PIN.'],
                ['Pindai QR di layar', 'Buka kamera HP, arahkan ke QR yang ditampilkan panitia.'],
                ['Cari nama, masukkan PIN', 'Ketik nama Anda, pilih yang sesuai nomor hadir, lalu masukkan PIN.'],
                ['Pilih satu calon', 'Ketuk calon pilihan, periksa sekali lagi, lalu kirim.'],
                ['Selesai', 'Layar kembali ke awal. HP boleh dipinjamkan ke warga lain.'],
            ],
        ],
        [
            'icon' => 'booth',
            'title' => 'Di TPS RT, di bilik suara',
            'note' => 'Dipakai untuk pemilihan resmi Ketua RT dan Ketua RW.',
            'steps' => [
                ['Datang ke TPS RT Anda', 'Petugas memeriksa nama Anda di daftar pemilih.'],
                ['Masuk bilik', 'Petugas membuka layar bilik untuk Anda. Tidak ada yang ikut melihat.'],
                ['Pilih Ketua RT', 'Ketuk satu calon, periksa, lalu kirim.'],
                ['Pilih Ketua RW', 'Lanjutkan ke surat suara berikutnya dengan cara yang sama.'],
                ['Selesai', 'Layar bilik terkunci kembali untuk pemilih berikutnya.'],
            ],
        ],
    ] as $way)
        <section class="way reveal">
            <div class="way__head">
                <span class="way__icon" aria-hidden="true">
                    @if ($way['icon'] === 'phone')
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"></rect><path d="M11 18h2"></path></svg>
                    @else
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V8l8-5 8 5v13"></path><path d="M9 21v-6h6v6"></path><path d="M10 10h4"></path></svg>
                    @endif
                </span>
                <div>
                    <h3 class="way__title">{{ $way['title'] }}</h3>
                    <p class="way__note">{{ $way['note'] }}</p>
                </div>
            </div>
            <ol class="timeline">
                @foreach ($way['steps'] as [$title, $text])
                    <li class="timeline__item">
                        <span class="timeline__dot" aria-hidden="true"></span>
                        <h4>{{ $title }}</h4>
                        <p>{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>

<div class="privacy reveal">
    <span class="notice__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
    </span>
    <div>
        <h3 class="notice__title">Pilihan Anda dirahasiakan</h3>
        <p>Hasil baru dibuka panitia setelah pemungutan suara ditutup. Pilihan seseorang hanya dapat dibuka Super Admin bila ada sengketa hasil, dengan alasan yang tercatat, dan dihapus otomatis setelah masa sengketa berakhir. Halaman ini tidak menampilkan jumlah suara calon.</p>
    </div>
</div>
