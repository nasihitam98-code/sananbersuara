# Ringkasan Mode Resmi dan Publikasi Hasil

| | |
|---|---|
| Status | Dibangun 6 Oktober 2026 |
| Dasar | Dokumen Tahap 1 (bagian 4, 5, 5A, 7, 8, 9, 9A) dengan usulan K01–K36 yang disetujui |

## 1. Alur hari H

```
Super Admin                        Petugas Meja (laptop Meja RT)            Laptop Bilik
──────────                         ─────────────────────────────            ────────────
Pemilihan Resmi + surat suara      /meja/pasang (token meja)                /bilik (token bilik)
(Ketua RT per RT, Ketua RW)        Meja Izin: cari nama RT sendiri          Menunggu pemilih
Data pemilih (import Excel)        [Izinkan Memilih] → "Ke Bilik 2"   ───▶  Kartu calon RT → periksa → konfirmasi
Tandai Siap → slot dibuat          Bilik: [Buat Token] / [Lepas]            Kartu calon RW → periksa → konfirmasi
Perangkat: token Meja per RT       [Batalkan Izin] sebelum konfirmasi       Terima kasih → terkunci lagi
Mulai → snapshot hak pilih
Tutup → izin dihentikan, laptop dilepas
Verifikasi → penetapan → berita acara per RT + keseluruhan → Publish
```

## 2. Data baru

| Tabel | Isi | Catatan |
|---|---|---|
| `voters` | Pemilih per RT | NIK hanya HMAC + 4 digit terakhir; HP terenkripsi |
| `ballot_voters` | Snapshot hak pilih per surat suara | Dibekukan saat Mulai; tambahan darurat ditandai |
| `devices` | Slot Meja/Bilik per RT per pemilihan | Token dan rahasia sesi hanya hash |
| `permits` | Izin memilih | Unique `active_voter_id`/`active_device_id` = satu izin aktif per pemilih/bilik |
| `votes` (+kolom) | `voter_id`, `permit_id`, `device_id`, `active_key` | Unique (surat suara, putaran, pemilih, active_key) |
| `vote_corrections` | Pengajuan koreksi | Satu pengajuan menunggu per suara; tanpa pilihan |
| `outcomes` | Penetapan terpilih/lolos per surat suara/RT | Ditetapkan Super Admin |
| `official_reports` | Berita acara berversi | Checksum SHA-256; disahkan = terkunci |
| `notifications` | Notifikasi lonceng panel | Hanya untuk admin |

## 3. Menu per peran

| Menu | Super Admin | Admin RT (kelola pemilih) | Admin RT (petugas meja) |
|---|---|---|---|
| Data Pemilih + Import | Semua RT | RT sendiri | — |
| Meja Izin | — | — | RT sendiri, hanya dari laptop Meja RT itu |
| Partisipasi | Semua RT | RT sendiri | RT sendiri |
| Koreksi Suara | Setujui/tolak | Ajukan (RT sendiri) | Ajukan (RT sendiri) |
| Layar Hasil (setelah ditutup) | Semua | RT sendiri + total RW | RT sendiri + total RW |
| Perangkat Terhubung | Semua RT | (status bilik di Meja Izin) | (status bilik di Meja Izin) |
| Verifikasi & Publikasi, Detail Suara, Audit Log, Akun | Ya | — | — |

## 4. Keputusan desain baru (mohon dicek)

| No | Keputusan | Alasan |
|---|---|---|
| D8 | Izin "Kelola Pemilih" dan "Petugas Meja" dipisah per akun Admin RT | K31: relawan meja tidak bisa mengubah data pemilih |
| D9 | Sidik NIK dan hash token perangkat memakai `APP_KEY`. **Jangan mengganti `APP_KEY`** setelah data pemilih masuk | Mengganti kunci membuat deteksi duplikat NIK berhenti bekerja |
| D10 | Slot perangkat dibuat saat pemilihan ditandai **Siap**, sehingga laptop bisa dipasang sehari sebelumnya | Persiapan hari H tidak terburu-buru |
| D11 | Bilik dipilih otomatis: yang paling lama menganggur | Beban merata antar bilik |
| D12 | "Batalkan Izin" hanya selama belum ada surat suara yang dikonfirmasi lewat izin itu | Suara yang sudah masuk hanya bisa dibatalkan lewat koreksi |
| D13 | Saat pemilihan dijeda: izin baru ditolak, pemilih yang sudah di bilik boleh menyelesaikan | K15 |
| D14 | Berita acara dicetak/disimpan PDF lewat browser (A4, tanda tangan, checksum) | Tanpa paket PDF tambahan |
| D15 | Detail Suara: setiap ganti filter juga tercatat di audit log | "Data apa yang dilihat" terekam lengkap |
| D16 | Status yang membuka data sensitif (Tampilkan Hasil, Detail Suara) dikunci di server (`#[Locked]`) | Tidak bisa diubah dari browser |

## 5. Belum dikerjakan

- Jeda per TPS RT (K15). Yang ada sekarang hanya jeda seluruh pemilihan.
- Notifikasi WhatsApp (K23). Yang ada sekarang email + lonceng panel.
- Penghapusan otomatis keterkaitan pemilih–pilihan 30 hari setelah publish (K26).
- Menu Backup & Restore di panel. Backup server diatur saat deployment.
- Putaran 2 lewat menu, peran Auditor (Fase 3), unggah foto calon oleh Admin RT.

## 6. Uji otomatis

82 tes lulus. Untuk Mode Resmi, yang dicakup:
- Alur bilik RT lalu RW.
- Calon RT lain ditolak walaupun request dipalsukan.
- Kirim ulang tidak membuat suara kedua.
- Satu pemilih tidak bisa ke dua bilik; muncul pesan "semua bilik terpakai" saat penuh.
- Izinkan hanya dari laptop meja RT yang sama (dan bukan Super Admin).
- Izin hangus setelah 5 menit.
- Batalkan izin hanya sebelum ada suara masuk.
- RT sudah, RW belum: setelah ganti laptop, hanya RW yang muncul.
- Token sekali pakai dan kedaluwarsa; token bilik tidak bisa memasang meja.
- Penutupan pemilihan melepas semua perangkat.
- Cookie perangkat palsu ditolak.
- Import Excel/CSV dan aturan duplikat K12.
- Admin RT tidak bisa melihat atau mengubah RT lain.
- Snapshot sesuai cakupan surat suara.
- Koreksi sesuai K17 dan bisa memilih ulang selama berlangsung.
- Detail Suara hanya setelah ditutup, tercatat, dan diberitahukan.
- Berita acara per RT dengan rekonsiliasi per bilik.
- Publikasi per RT.
- Rekap Excel dibatasi sesuai peran.
