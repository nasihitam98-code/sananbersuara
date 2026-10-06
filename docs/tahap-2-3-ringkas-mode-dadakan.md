# Tahap 2–3 (ringkas): Mode Dadakan untuk Penjaringan 10 Oktober

| | |
|---|---|
| Status | Dibangun 5 Oktober 2026, menunggu uji coba pemilik |
| Dasar | Tahap 1 disetujui dengan semua usulan default (K01–K36); Tahap 2–3 diringkas atas persetujuan pemilik karena penjaringan tanggal 10 |
| Belum termasuk | Mode Resmi (meja + bilik), berita acara PDF, ekspor Excel, halaman publik, putaran 2 lewat UI, backup otomatis |

## 1. Alur yang sudah jadi

```
PERSIAPAN (Super Admin)                 HARI H                                      SETELAH
Pemilihan Mode Dadakan                  Meja Pintu: nama → PIN tampil sekali         Tutup Pemilihan (password)
 └ Surat suara "Calon Ketua RW"         Layar QR di proyektor                         └ tautan peserta-suara dihapus
    └ 27 calon + foto 1:1               Ruang Kendali: BUKA VOTING (timer)           Layar Hasil: [Tampilkan Hasil]
Tugaskan Panitia & Petugas Pintu        HP: tunggu → cari nama → PIN → pilih          └ ranking + persen, tercatat
Tandai Siap → Mulai Pemilihan               → periksa → KONFIRMASI → layar hijau
                                        Perpanjang / Tutup Sekarang
                                        Gelombang bantuan (HP panitia, tanpa timer)
                                        Pulihkan Hak Pilih / buka kunci PIN
                                        Hitung kepala (rekonsiliasi)
```

## 2. Struktur data

| Tabel | Isi | Catatan kerahasiaan |
|---|---|---|
| `elections` | Pemilihan, mode, status, pengaturan, `access_code` (kode acak di QR) | |
| `ballots` / `candidates` | Surat suara, calon (nomor, nama, status, kunci foto acak) | |
| `rounds` / `waves` | Putaran dan gelombang (jenis, timer, status) | |
| `attendees` | Peserta hadir, nomor hadir, **hash PIN** (HMAC + pepper server), jumlah salah PIN, kunci | PIN asli tidak pernah disimpan |
| `attendee_participations` | "Sudah memilih" per peserta/surat suara/putaran, penanda **dibantu** | Tidak menyimpan pilihan |
| `votes` | Pilihan. **UUID acak, tanpa timestamp**, `voter_link` = HMAC sementara | Tidak memuat ID peserta; tautan dihapus saat ditutup |
| `vote_cancellations` | Pembatalan (alasan, pelaku) | Tidak memuat pilihan |
| `election_staff` | Penugasan Panitia / Petugas Pintu per pemilihan | |
| `audit_logs` | Append-only, **hash berantai**, trigger DB menolak UPDATE/DELETE | Tanpa PIN, token, pilihan |

Struktur ini sudah generik: Mode Resmi nanti menambah tabel pemilih, snapshot, slot perangkat, dan izin tanpa mengubah tabel di atas.

## 3. Jaminan anti pemilihan ganda (terbukti diuji)

1. Baris peserta dikunci (`SELECT … FOR UPDATE`) selama transaksi.
2. Unique index `(peserta, surat suara, putaran, active_key)`. `active_key` menjadi NULL saat dibatalkan, jadi riwayat tetap ada.
3. Catatan "sudah memilih" dan suara ditulis dalam **satu transaksi**.
4. Kirim ulang, refresh, atau klik ganda → "Anda sudah memilih".

**Uji konkuren nyata** (6 proses server PHP paralel, satu database MySQL):

| Uji | Hasil |
|---|---|
| 30 konfirmasi bersamaan untuk **satu** peserta | 1 suara tersimpan |
| 299 peserta berbeda konfirmasi hampir bersamaan | 299 tersimpan, 0 error, 0 ganda (sekitar 16 detik di laptop tanpa PHP-FPM) |

## 4. Keamanan yang diterapkan

| Area | Penerapan |
|---|---|
| Login admin | Tanpa registrasi publik; **2FA wajib** semua akun (sejak 6 Okt: kode lewat email, bukan aplikasi authenticator, atas keputusan pemilik); wajib ganti password sementara; password min. 12 + huruf + angka (+ cek bocor di produksi); login/gagal/lockout masuk audit |
| Re-autentikasi | Password diminta ulang untuk Mulai, Tutup, Batalkan pemilihan, reset password/2FA |
| Otorisasi | Policy server: konfigurasi hanya Super Admin; Meja Pintu hanya Petugas Pintu/Panitia **pemilihan yang ditugaskan**; Ruang Kendali, Layar Hasil, Layar QR hanya Panitia; diuji otomatis (403) |
| Status | Calon/surat suara terkunci setelah Mulai (diuji); hasil per calon hanya setelah Ditutup |
| PIN | Acak 4 digit (PIN lemah seperti 1234 dibuang), HMAC + pepper, tampil sekali, kunci setelah 3 salah, hanya bisa dicoba saat gelombang dibuka |
| Pencarian nama | Min. 3 huruf, maks. 5 hasil, yang sudah memilih disembunyikan, rate limit per HP |
| Rate limit | Per HP (sesi), bukan per IP, karena ratusan HP bisa keluar lewat satu IP WiFi; batas per IP dibuat longgar sebagai rem terakhir |
| Header | CSP ketat untuk halaman pemilih (tanpa skrip inline), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS (HTTPS) |
| Halaman pemilih | `no-store` (tombol Kembali tidak menampilkan surat suara orang sebelumnya); sesi pemilih hanya ID sementara, dihapus setelah selesai |
| Foto | Validasi MIME asli, re-encode WebP (EXIF terbuang), potong 1:1, 3 ukuran, nama file acak |
| Rahasia | `PIN_PEPPER`, `VOTE_LINK_KEY` di `.env` (tidak masuk repositori) |

## 5. Keputusan desain baru (mohon dicek)

| No | Keputusan | Alasan |
|---|---|---|
| D1 | Petugas Pintu diperingatkan bila **nama yang sama** sudah terdaftar dan harus menekan "Ya, orang berbeda" | Pendaftaran ganda orang yang sama adalah celah kecurangan terbesar di mode ini |
| D2 | Penanda "dibantu" = suara yang masuk di **gelombang bantuan**, disimpan di catatan kehadiran, bukan di suara | Tidak perlu mendaftarkan HP pinjaman; pilihan lansia tidak bisa ditebak |
| D3 | Masa tenggang setelah timer habis **30 detik** (bisa diubah per pemilihan) | Antrean server saat ratusan orang menekan di detik terakhir |
| D4 | Tutup Pemilihan menghapus tautan peserta-suara secara permanen. Setelah ditutup, Pulihkan Hak Pilih tidak mungkin lagi | Sesuai K25 |
| D5 | HP pemilih memakai font sistem, tanpa CDN font; CSS 6,8 KB + JS 4,3 KB | Ringan untuk ratusan HP |
| D6 | Pemilihan aktif hanya satu pada satu waktu | K13 |
| D7 | Polling HP membaca **file statis** `public/status/{kode}.json` (ditulis ulang setiap buka/perpanjang/jeda/tutup). Sisa waktu dihitung di HP dari `ends_at` dan header `Date` server. Endpoint `/status` tetap ada sebagai cadangan | 600 HP × polling tiap 3 detik ≈ 200 request/detik; lewat file statis bebannya hampir nol bagi PHP |

## 6. Syarat server untuk tanggal 10

- VPS Linux 2–4 vCPU, RAM 4 GB, lokasi Indonesia/Singapura; Nginx + **PHP-FPM 8.3 dengan OPcache aktif**, MySQL 8, Redis (sesi + cache).
- Tanpa OPcache satu request bisa sekitar 1,4 detik; dengan OPcache sekitar 85 ms (diukur di laptop).
- HTTPS (Let's Encrypt), `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize`.
- Sebaiknya di belakang Cloudflare (gratis) untuk perlindungan dan cache foto.
- Folder `public/status` harus bisa ditulis oleh PHP-FPM; Nginx menyajikan `/status/*.json` dengan `Cache-Control: no-cache`.
- Uji beban ulang di VPS pada tanggal 9 dengan skrip yang sama.

## 7. Uji otomatis (33 tes, semuanya lulus)

`tests/Feature/DadakanVotingTest.php`, `VoterFlowTest.php`, `AdminAccessTest.php`, `AdminPagesRenderTest.php`. Isinya mencakup: PIN tersimpan sebagai hash, PIN/cari nama ditolak sebelum dibuka, satu suara per orang, kunci 3x salah, batas waktu + tenggang, pilihan dari surat suara lain ditolak, Pulihkan Hak Pilih membatalkan tanpa membuka pilihan, penutupan menghapus tautan, audit log tidak bisa diubah/dihapus (trigger), rantai hash mendeteksi penyusupan, satu pemilihan aktif, 2FA dan ganti password dipaksa, Petugas Pintu tidak bisa membuka Ruang Kendali/pengaturan, staf pemilihan lain ditolak (403), hasil tersembunyi sebelum ditutup, dan penayangan hasil tercatat.
