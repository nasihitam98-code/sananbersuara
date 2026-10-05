# Tahap 1: Analisis Bisnis dan Alur

**Platform Pemilihan Digital** (nama aplikasi = `APP_NAME`, ditetapkan saat deployment)

| | |
|---|---|
| Status | DRAFT, menunggu persetujuan |
| Versi | 0.1, 5 Oktober 2026 |
| Cakupan | Tahap 1 (bagian 14): business flow, matriks peran, election flow, voter flow, Meja/Bilik/Token, Dadakan, koreksi, publish, backup, audit, edge case |
| Belum termasuk | ERD dan wireframe (Tahap 2), arsitektur Laravel dan keamanan (Tahap 3), kode |

**Cara membaca.** Tanda **⟨K07⟩** berarti nilai atau aturan itu masih **usulan** dan menunggu keputusan Anda. Semua usulan dikumpulkan di [Bagian 15: Daftar Keputusan](#15-daftar-keputusan-yang-perlu-anda-setujui). Yang tidak bertanda sudah tertulis di dokumen kebutuhan Anda.

---

## Daftar Isi

1. [Istilah](#1-istilah)
2. [Business flow dari awal sampai akhir](#2-business-flow-dari-awal-sampai-akhir)
3. [Peran dan hak akses](#3-peran-dan-hak-akses)
4. [Election flow (status pemilihan)](#4-election-flow-status-pemilihan)
5. [Surat suara dan cakupan hak pilih](#5-surat-suara-dan-cakupan-hak-pilih)
6. [Voter flow Mode Resmi (data pemilih)](#6-voter-flow-mode-resmi-data-pemilih)
7. [Alur Meja, Bilik, dan Token (Mode Resmi)](#7-alur-meja-bilik-dan-token-mode-resmi)
8. [Alur Mode Dadakan](#8-alur-mode-dadakan-fase-2)
9. [Correction flow (koreksi/pembatalan suara)](#9-correction-flow-koreksipembatalan-suara)
10. [Hasil, verifikasi, berita acara, publish/unpublish](#10-hasil-verifikasi-berita-acara-publishunpublish)
11. [Backup flow](#11-backup-flow)
12. [Audit flow](#12-audit-flow)
13. [Notifikasi internal](#13-notifikasi-internal)
14. [Edge case](#14-edge-case)
15. [Daftar keputusan yang perlu Anda setujui](#15-daftar-keputusan-yang-perlu-anda-setujui)
16. [Langkah berikutnya](#16-langkah-berikutnya)

---

## 1. Istilah

| Istilah | Arti dalam sistem |
|---|---|
| **Pemilihan** | Satu sesi berdiri sendiri (mis. "Pemilihan Ketua RT & RW 2026"). Data antar pemilihan tidak bercampur. |
| **Mode** | `RESMI` (meja + bilik) atau `DADAKAN` (QR + PIN). Ditetapkan saat pemilihan dibuat, tidak bisa diubah setelah READY. |
| **Unit / RT** | RT 01..RT N. Jumlah RT adalah data master, bukan angka di kode. |
| **Surat suara** | Satu pertanyaan pilihan dalam pemilihan (mis. "Ketua RT", "Ketua RW"). Punya cakupan dan daftar kandidat. |
| **Putaran** | Satu kali pemungutan untuk sebuah surat suara. Putaran 2 = pemilihan ulang dengan kandidat yang ditetapkan panitia. |
| **Gelombang** | Jendela buka-tutup di dalam satu putaran (utama di Mode Dadakan). |
| **Pemilih (master)** | Data warga terdaftar per RT, dengan `Voter ID` internal (mis. VTR-000123). |
| **Snapshot hak pilih** | Salinan daftar pemilih berhak per surat suara, dibekukan saat pemilihan dimulai. |
| **Peserta hadir** | Orang yang didata di pintu pada Mode Dadakan (daftar per pemilihan, terpisah dari pemilih master). |
| **Izin** | Catatan bahwa petugas meja mengizinkan satu pemilih memakai satu bilik. Hanya Mode Resmi. |
| **Slot perangkat** | Tempat logis untuk laptop: `RT03-MEJA`, `RT03-01`, `RT03-02`, dst. |
| **Sesi bilik** | Satu kali pemakaian bilik oleh satu pemilih, dari izin sampai selesai/hangus. |
| **Suara** | Satu pilihan pada satu surat suara di satu putaran. Status `SAH` atau `DIBATALKAN`; tidak pernah dihapus. |
| **Pengajuan koreksi** | Permintaan membatalkan suara tertentu (Mode Resmi), perlu persetujuan Super Admin. |
| **Pulihkan Hak Pilih** | Padanan koreksi di Mode Dadakan, dilakukan langsung oleh panitia utama. |

---

## 2. Business flow dari awal sampai akhir

```
 PERSIAPAN            SIAP              HARI H                 SETELAH PEMUNGUTAN                    ARSIP
 ─────────           ──────           ──────────              ──────────────────────               ───────
 [DRAFT] ──────────▶ [READY] ───────▶ [BERLANGSUNG] ◀──▶ [PAUSED]
    │                   │                   │
    │                   │                   ▼
    │                   │              [DITUTUP] ──▶ [VERIFIKASI] ──▶ [PUBLISHED] ◀──▶ [UNPUBLISHED]
    │                   │                                                   │
    └───────────────────┴──────────▶ [CANCELLED]                            └──────────▶ [ARCHIVED]
```

| Fase | Aktor utama | Kegiatan | Keluaran |
|---|---|---|---|
| **Persiapan** (DRAFT) | Super Admin, Admin RT | Buat pemilihan, pilih mode, susun surat suara dan cakupannya, input kandidat + foto, import/tambah pemilih (Mode Resmi), atur jumlah bilik per RT, buat akun Admin RT, atur opsi (bagian 15) | Konfigurasi lengkap |
| **Siap** (READY) | Super Admin | Cek kelengkapan otomatis, pasang token meja di laptop Meja Izin tiap RT, pasang laptop bilik, uji coba (pemilihan latihan terpisah), latihan restore backup | Checklist kesiapan hijau |
| **Hari H** (BERLANGSUNG/PAUSED) | Petugas Meja, pemilih, Super Admin | Snapshot hak pilih dibekukan saat dimulai. Izinkan memilih, pemilih memilih di bilik, pantau partisipasi dan perangkat, koreksi bila perlu | Suara tersimpan, audit log |
| **Tutup** (DITUTUP) | Super Admin | Hentikan pemungutan; hasil per kandidat bisa dilihat internal | Hasil mentah |
| **Verifikasi** (VERIFIKASI) | Super Admin, Admin RT, saksi | Rekonsiliasi, selesaikan koreksi tertunda, [Tampilkan Hasil] di lokasi, berita acara dibuat dan ditandatangani, penetapan terpilih oleh panitia, pengesahan berita acara | Berita acara DISAHKAN |
| **Publikasi** (PUBLISHED) | Super Admin | Halaman publik menampilkan "Terpilih" per unit | Hasil resmi publik |
| **Arsip** (ARCHIVED) | Super Admin / sistem | Data pribadi dikurangi sesuai kebijakan retensi ⟨K26⟩ | Arsip minimal |

### 2.1 Rangkaian pemilihan yang direncanakan (keputusan pemilik, 5 Okt 2026)

```
1. PENJARINGAN CALON RW            2. PENETAPAN               3. HARI H: RT + RW
   Mode Dadakan (QR + PIN)            Panitia memilih            Mode Resmi (meja + bilik)
   1 surat suara, 27 calon   ───▶     3 calon RW dari    ───▶    Surat suara 1: Ketua RT, PER_RT, maks. 5 calon per RT
   Hasil: total + ranking             hasil penjaringan          Surat suara 2: Ketua RW, SEMUA_RT, 3 calon
   (sistem tidak menentukan)          (di luar sistem)           Pemilih memilih 1 calon di tiap surat suara
```

Ketiganya adalah pemilihan terpisah di sistem (data tidak bercampur). Batas jumlah calon (5 per RT, 3 untuk RW) adalah **pengaturan per surat suara**, bukan angka di kode ⟨K35⟩.

Mode Dadakan memakai status yang sama. Bedanya, di dalam BERLANGSUNG ada banyak gelombang (dan bisa banyak putaran), lihat [Bagian 8](#8-alur-mode-dadakan-fase-2).

---

## 3. Peran dan hak akses

### 3.1 Peran

| Peran | Login | Cakupan | Catatan |
|---|---|---|---|
| **Super Admin** | Ya, 2FA wajib | Semua RT, semua pemilihan | Minimal 2 akun aktif. Sistem menolak menonaktifkan Super Admin terakhir kedua. |
| **Admin RT** | Ya, 2FA wajib | RT yang ditugaskan saja (satu akun = satu RT) | Bisa punya izin tambahan **Petugas Meja**. Satu RT boleh punya beberapa akun (setiap petugas wajib akun sendiri). |
| **Petugas Meja** | (izin di akun Admin RT) | RT akun tersebut, **hanya di laptop Meja terdaftar** RT yang sama | Bukan akun terpisah ⟨K31⟩ |
| **Perangkat Bilik** | Tidak (sesi perangkat dari token) | Satu slot bilik satu RT | Bukan pengguna; hanya menampilkan surat suara milik izin aktif |
| **Pemilih** | Tidak | Surat suara yang berhak | Mode Resmi lewat bilik; Mode Dadakan lewat QR + nama + PIN |
| **Publik** | Tidak | Halaman hasil PUBLISHED | |
| **Panitia Dadakan / Petugas Pintu** | Ya | Satu pemilihan Mode Dadakan | **Belum didefinisikan** di dokumen kebutuhan; usulan ⟨K24⟩ |
| **Auditor/Saksi** | Ya | Read-only | Fase 3 |
| **Ketua Panitia** | Tidak (penerima notifikasi) | | Usulan: alamat penerima notifikasi, bukan akun ⟨K23⟩ |

### 3.2 Aturan tiga syarat

Setiap aksi dicek di backend terhadap tiga hal sekaligus. Menyembunyikan tombol hanya kenyamanan.

1. **Siapa** (akun, peran, izin, RT akun)
2. **Dari mana** (perangkat: laptop Meja terdaftar untuk RT yang sama, laptop bilik terdaftar, atau perangkat biasa)
3. **Kapan** (status pemilihan, status TPS RT, status gelombang)

Contoh: tombol **[Izinkan Memilih]** sah hanya jika akun Admin RT punya izin Petugas Meja **dan** request datang dari sesi perangkat Meja yang aktif **dan** RT akun = RT meja **dan** pemilihan BERLANGSUNG **dan** TPS RT itu tidak dijeda ⟨K15⟩.

### 3.3 Matriks hak akses rinci

Singkatan: **SA** = Super Admin; **ART** = Admin RT (dari perangkat apa pun); **MEJA** = Admin RT dengan izin Petugas Meja, sedang di laptop Meja RT-nya; **PUB** = publik. "Sendiri" = RT akun sendiri. "—" = ditolak.

#### A. Konfigurasi

| Aksi | SA | ART | MEJA | PUB | Batas status |
|---|---|---|---|---|---|
| Buat/ubah pemilihan, mode, opsi | Ya | — | — | — | DRAFT/READY. Setelah mulai: hanya opsi operasional (mis. timer) dengan alasan |
| Buat/ubah surat suara dan cakupan | Ya | — | — | — | DRAFT/READY. Setelah mulai: SA + alasan dropdown + audit |
| Kelola akun admin, role, izin Petugas Meja | Ya (re-auth) | — | — | — | Kapan saja |
| Atur jumlah slot bilik per RT | Ya | — | — | — | DRAFT/READY (menambah slot saat berlangsung: SA + alasan) |
| Kandidat + foto (surat suara semua RT) | Ya | — | — | — | DRAFT/READY. Setelah mulai: SA + alasan |
| Kandidat + foto (surat suara RT) | Ya | Sendiri | — | — | DRAFT/READY. Setelah mulai: SA + alasan |
| Pemilih: tambah/edit/import | Ya | Sendiri | — | — | Sebelum BERLANGSUNG bebas; saat berlangsung hanya jalur darurat |
| Pemilih: hapus | Ya | Sendiri | — | — | Hanya jika belum punya riwayat suara di pemilihan mana pun |
| Pemilih: nonaktif | Ya | Sendiri | — | — | Sebelum BERLANGSUNG |
| Pemilih: tambah darurat saat berlangsung | Ya | Sendiri | Sendiri | — | BERLANGSUNG/PAUSED, alasan dropdown, notifikasi SA |
| Pemilih: koreksi RT | Ya (alasan) | — | — | — | Kapan saja, audit |

#### B. Perangkat dan hari H

| Aksi | SA | ART | MEJA | PUB | Batas status |
|---|---|---|---|---|---|
| Buat token meja | Ya (re-auth) | — | — | — | READY s.d. PAUSED |
| Buat token bilik, Lepas bilik | — | — | Sendiri | — | READY s.d. PAUSED |
| Lihat Perangkat Terhubung | Semua | Sendiri | Sendiri | — | |
| Izinkan Memilih, Batalkan Izin | — | — | Sendiri | — | BERLANGSUNG, TPS tidak dijeda |
| Mulai / Jeda / Lanjutkan / Tutup pemilihan | Ya (re-auth untuk mulai/tutup) | — | — | — | Sesuai transisi di 4.2 |
| Jeda TPS satu RT | Ya | — | Sendiri ⟨K15⟩ | — | BERLANGSUNG |
| Lihat status sudah/belum per pemilih | Semua | Sendiri | Sendiri | — | |
| Lihat partisipasi live | Semua | Sendiri | Sendiri | — | |
| Layar Live Partisipasi (proyektor) | Semua | Sendiri | Sendiri | — | ⟨K32⟩ |
| Lihat hitungan per kandidat saat berlangsung | Alasan dropdown + audit + notifikasi | — | — | — | BERLANGSUNG/PAUSED |

#### C. Hasil dan koreksi

| Aksi | SA | ART | MEJA | PUB | Batas status |
|---|---|---|---|---|---|
| Hasil dan ranking | Semua | Sendiri ⟨K19⟩ | Sendiri ⟨K19⟩ | — | ≥ DITUTUP |
| [Tampilkan Hasil] di layar pengumuman | Ya | Sendiri ⟨K20⟩ | Sendiri ⟨K20⟩ | — | ⟨K20⟩ |
| Detail Suara (siapa memilih siapa) | Ya (re-auth, alasan, audit, notifikasi) | **Tidak pernah** | **Tidak pernah** | — | ≥ DITUTUP |
| Ajukan koreksi suara | Ya ⟨K17⟩ | Sendiri | Sendiri | — | ⟨K16⟩ |
| Setujui/tolak koreksi | Ya (re-auth), bukan pemberi izin suara itu | — | — | — | ⟨K16⟩ |
| Penetapan kandidat terpilih | Ya | — | — | — | VERIFIKASI ⟨K21⟩ |
| Draf berita acara | Semua | Sendiri | Sendiri | — | ≥ DITUTUP |
| Sahkan berita acara | Ya (re-auth) | — | — | — | VERIFIKASI/UNPUBLISHED |
| Rekap PDF/Excel | Semua | Sendiri | Sendiri | — | ≥ DITUTUP |
| Publish / Unpublish | Ya (re-auth) | — | — | — | Lihat 4.2 |
| Halaman publik | Ya | Ya | Ya | Ya | Hanya PUBLISHED |

#### D. Sistem

| Aksi | SA | ART | MEJA | PUB |
|---|---|---|---|---|
| Lihat audit log | Semua | ⟨K30⟩ | ⟨K30⟩ | — |
| Ubah/hapus audit log | **Tidak ada yang bisa** (termasuk SA) | — | — | — |
| Backup Now, unduh backup | Ya (re-auth) | — | — | — |
| Restore | Ya (re-auth + konfirmasi ketik) ⟨K27⟩ | — | — | — |
| Pengaturan aplikasi | Ya | — | — | — |

### 3.4 Larangan mutlak (diuji otomatis di Tahap 4)

- Admin RT melihat/mengubah data RT lain lewat menu, URL, API, ekspor, atau widget → **403/404**.
- Siapa pun selain Super Admin melihat pilihan individu → ditolak. Super Admin pun ditolak sebelum DITUTUP.
- Siapa pun menghapus suara → tidak ada fitur. Pembatalan hanya mengubah status.
- Siapa pun mengubah/menghapus audit log → tidak ada fitur, dan ditolak di level database.
- Perangkat bilik mengakses data selain izin aktif di slotnya → ditolak.
- Sesi admin, sesi perangkat, dan sesi pemilih saling terpisah; satu tidak memberi akses ke yang lain.

### 3.5 Kriteria penerimaan

- **AC-3.1** Admin RT 03 membuka URL detail pemilih RT 04 → 403/404, tercatat di audit log sebagai akses ditolak.
- **AC-3.2** Admin RT 05 login di laptop Meja RT 03 → tombol Izinkan dan Buat Token nonaktif; request langsung ke endpoint ditolak.
- **AC-3.3** Admin RT dengan izin Petugas Meja login dari laptop Dashboard → bisa melihat Meja Izin, tombol aksi nonaktif, request langsung ditolak.
- **AC-3.4** Menonaktifkan Super Admin hingga tersisa 1 akun aktif → ditolak dengan pesan jelas.

---

## 4. Election flow (status pemilihan)

### 4.1 Diagram status

```
                 ┌──────────── kembali ke DRAFT ───────────┐
                 ▼                                          │
            ┌─────────┐   cek kesiapan    ┌─────────┐       │
            │  DRAFT  │ ────────────────▶ │  READY  │ ──────┘
            └────┬────┘                   └────┬────┘
                 │                              │ Mulai (snapshot dibekukan)
                 │                              ▼
                 │                       ┌──────────────┐  Jeda   ┌────────┐
                 │                       │ BERLANGSUNG  │ ──────▶ │ PAUSED │
                 │                       │              │ ◀────── │        │
                 │                       └──────┬───────┘ Lanjut  └───┬────┘
                 │                              │ Tutup               │ Tutup
                 │                              ▼                     │
                 │                       ┌──────────────┐ ◀───────────┘
                 │                       │   DITUTUP    │
                 │                       └──────┬───────┘
                 │                              │ Mulai verifikasi
                 │                              ▼
                 │                       ┌──────────────┐
                 │                       │  VERIFIKASI  │ ◀─────────────┐
                 │                       └──────┬───────┘               │ Perbaiki
                 │                              │ Publish (BA disahkan) │
                 │                              ▼                       │
                 │                       ┌──────────────┐  Unpublish  ┌─┴────────────┐
                 │                       │  PUBLISHED   │ ──────────▶ │ UNPUBLISHED  │
                 │                       │              │ ◀────────── │              │
                 │                       └──────┬───────┘  Publish    └──────────────┘
                 ▼                              ▼ (setelah masa retensi)
           ┌───────────┐                 ┌──────────────┐
           │ CANCELLED │                 │   ARCHIVED   │
           └───────────┘                 └──────────────┘
```

### 4.2 Tabel transisi

| Dari → Ke | Siapa | Prasyarat | Efek sistem | Audit |
|---|---|---|---|---|
| DRAFT → READY | SA | Minimal 1 surat suara; tiap surat suara/unit punya kandidat aktif (atau kotak kosong ⟨K01⟩); ada pemilih berhak di setiap cakupan; slot bilik terkonfigurasi (Mode Resmi). Peringatan (tidak memblokir): kandidat tanpa foto, RT tanpa Admin RT | Konfigurasi inti terkunci ringan (bisa kembali ke DRAFT) | `election.ready` |
| READY → DRAFT | SA | Belum pernah BERLANGSUNG | Token meja/bilik yang sudah dibuat tetap berlaku | `election.back_to_draft` |
| READY → BERLANGSUNG | SA (re-auth) | Tidak ada pemilihan lain yang aktif ⟨K13⟩ | **Snapshot hak pilih dibekukan per surat suara**; putaran 1 dibuka; daftar pemilih masuk mode beku | `election.started` + jumlah pemilih berhak per surat suara/RT |
| BERLANGSUNG → PAUSED | SA | | Izin baru ditolak. Pemilih yang sedang di bilik boleh menyelesaikan ⟨K15⟩. Mode Dadakan: gelombang aktif ikut dijeda, timer berhenti | `election.paused` + alasan |
| PAUSED → BERLANGSUNG | SA | | Izin kembali bisa diberikan; timer gelombang lanjut dari sisa | `election.resumed` |
| BERLANGSUNG/PAUSED → DITUTUP | SA (re-auth) | Konfirmasi jika masih ada sesi bilik aktif ⟨K29⟩ | Semua izin aktif ditutup; suara yang belum dikonfirmasi tidak tersimpan; tidak ada suara baru diterima | `election.closed` + jumlah izin yang ditutup paksa |
| DITUTUP → VERIFIKASI | SA | | Membuka checklist verifikasi | `election.verification_started` |
| VERIFIKASI → PUBLISHED | SA (re-auth) | Penetapan terpilih diisi untuk setiap unit ⟨K21⟩; berita acara DISAHKAN ⟨K08⟩; tidak ada pengajuan koreksi tertunda | Halaman publik menampilkan terpilih | `election.published` |
| PUBLISHED → UNPUBLISHED | SA (re-auth) | Alasan + konfirmasi | Halaman publik menampilkan "Hasil sedang ditinjau ulang"; suara tidak berubah | `election.unpublished` + alasan |
| UNPUBLISHED → VERIFIKASI | SA | | Koreksi/BA versi baru bisa dilakukan | `election.reverify` |
| UNPUBLISHED → PUBLISHED | SA (re-auth) | Prasyarat publish terpenuhi lagi | | `election.published` (versi ke-n) |
| DRAFT/READY/BERLANGSUNG/PAUSED/DITUTUP/VERIFIKASI → CANCELLED | SA (re-auth) | Alasan + konfirmasi ketik nama pemilihan | Semua aksi berhenti; data dan suara tetap tersimpan, tidak pernah tampil di publik | `election.cancelled` |
| PUBLISHED/CANCELLED → ARCHIVED | SA atau terjadwal | Masa sengketa lewat ⟨K26⟩ | Kebijakan retensi dijalankan | `election.archived` |
| DITUTUP/VERIFIKASI → (buka putaran 2) | SA (re-auth) | ⟨K22⟩ | Lihat 4.4 | `round.opened` |

### 4.3 Data yang boleh diubah per status

Keterangan: ✅ boleh, ⚠️ hanya SA dengan alasan dropdown + audit, 🆘 jalur darurat (alasan + audit + notifikasi), ❌ tidak boleh.

| Data | DRAFT | READY | BERLANGSUNG / PAUSED | DITUTUP / VERIFIKASI | PUBLISHED | UNPUBLISHED | CANCELLED / ARCHIVED |
|---|---|---|---|---|---|---|---|
| Nama, deskripsi, tanggal pemilihan | ✅ | ✅ | ⚠️ | ⚠️ | ❌ | ⚠️ | ❌ |
| Mode | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Surat suara, cakupan | ✅ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| Kandidat (nama, nomor, foto) | ✅ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| Status kandidat (mundur) | ✅ | ✅ | ⚠️ ⟨K18⟩ | ❌ | ❌ | ❌ | ❌ |
| Opsi aturan hasil (seri, kuorum, abstain, kotak kosong) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Opsi operasional (timer, masa hangus izin) | ✅ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| Jumlah slot bilik per RT | ✅ | ✅ | ⚠️ (tambah saja) | ❌ | ❌ | ❌ | ❌ |
| Pemilih master (tambah/edit/import/nonaktif) | ✅ | ✅ | 🆘 tambah saja | ✅ (tidak mengubah snapshot) | ✅ | ✅ | ✅ |
| Snapshot hak pilih | (belum ada) | (belum ada) | 🆘 tambah; ⚠️ cabut | ❌ | ❌ | ❌ | ❌ |
| Token meja / bilik | ✅ | ✅ | ✅ | ❌ (perangkat dilepas otomatis) | ❌ | ❌ | ❌ |
| Suara: buat baru | ❌ | ❌ | ✅ (lewat bilik/HP) | ❌ | ❌ | ❌ | ❌ |
| Suara: batalkan | ❌ | ❌ | ✅ lewat alur koreksi | ⟨K16⟩ | ❌ | ⟨K16⟩ | ❌ |
| Penetapan terpilih | ❌ | ❌ | ❌ | ✅ di VERIFIKASI | ❌ | ✅ | ❌ |
| Berita acara | ❌ | ❌ | ❌ | ✅ draf / sahkan | ❌ (terkunci) | versi baru + alasan | ❌ |

### 4.4 Putaran

- Setiap surat suara punya putaran 1 saat pemilihan dimulai.
- Status "sudah memilih" dihitung **per surat suara per putaran**.
- Putaran berikutnya dibuka Super Admin untuk surat suara/unit tertentu, dengan kandidat yang **dipilih panitia** dari kandidat putaran sebelumnya. Sistem tidak memilih kandidat otomatis.
- Mode Dadakan: putaran 2 dibuka di dalam status BERLANGSUNG (lihat 8.8).
- Mode Resmi: usulan putaran 2 untuk kasus seri ⟨K22⟩.

### 4.5 Kriteria penerimaan

- **AC-4.1** Mengubah kandidat saat BERLANGSUNG dari akun Admin RT → ditolak; dari Super Admin tanpa alasan → ditolak; dengan alasan → berhasil dan tercatat sebelum/sesudah.
- **AC-4.2** Klik Mulai → jumlah pemilih berhak per surat suara/RT tercatat di audit log dan tidak berubah kecuali lewat jalur darurat.
- **AC-4.3** Publish saat masih ada pengajuan koreksi tertunda atau berita acara belum disahkan → tombol nonaktif dan request langsung ditolak.
- **AC-4.4** Unpublish → halaman publik langsung tidak lagi menampilkan nama terpilih; jumlah suara di database tidak berubah.

---

## 5. Surat suara dan cakupan hak pilih

### 5.1 Pengaturan per surat suara

| Pengaturan | Pilihan |
|---|---|
| Cakupan pemilih | `PER_RT` (tiap RT punya kandidat sendiri), `RT_TERTENTU` (daftar RT dipilih SA), `SEMUA_RT` |
| Cakupan kandidat | Mengikuti cakupan pemilih: `PER_RT` → kandidat ditandai RT; lainnya → satu daftar kandidat bersama |
| Aturan pilihan | Satu pilihan (Fase 1). Multi pilihan di Fase 3 |
| Batas jumlah calon | Opsional per surat suara (per unit untuk `PER_RT`). Rencana saat ini: Ketua RT maks. 5 per RT, Ketua RW 3. Dicek saat menambah kandidat dan saat DRAFT → READY ⟨K35⟩ |
| Urutan tampil | Urutan surat suara tetap (mis. RT dulu, lalu RW); kandidat urut nomor urut |
| Opsi hasil | Kotak kosong ⟨K01⟩, abstain ⟨K02⟩, seri ⟨K03⟩, kuorum ⟨K04⟩ |
| Mode Dadakan | Cakupan = semua peserta hadir di pemilihan itu; RT tidak dipakai untuk hak pilih |

### 5.2 Aturan penentuan hak pilih

Seorang pemilih **berhak** pada surat suara S di putaran P jika:

1. Pemilih ada di **snapshot** surat suara S (dibekukan saat mulai, plus tambahan darurat);
2. Pemilih aktif di snapshot (tidak dicabut);
3. RT pemilih (dari data pemilih, bukan input pemilih) masuk cakupan S;
4. Putaran P untuk unit tersebut sedang dibuka.

Surat suara yang ditampilkan di bilik = semua surat suara yang ia berhak **dan belum** ia pilih di putaran berjalan, berurutan.

Pengecekan dilakukan di backend pada **tiga titik**: (a) saat Izinkan Memilih, (b) saat bilik meminta surat suara, (c) saat menyimpan suara. Kandidat yang dikirim harus milik surat suara dan unit pemilih; kalau tidak, ditolak dan dicatat.

### 5.3 Contoh konfigurasi (semua tanpa kode berbeda)

| Kebutuhan | Surat suara |
|---|---|
| Ketua RT 01-09 | 1 surat suara `PER_RT`, kandidat per RT |
| Ketua RW | 1 surat suara `SEMUA_RT` |
| RT + RW sekaligus (rencana hari H) | Mode Resmi. S1 `PER_RT` (maks. 5 calon per RT) + S2 `SEMUA_RT` (3 calon hasil penetapan penjaringan), urutan S1 lalu S2 |
| Penjaringan 27 calon RW | **Mode `DADAKAN`** (keputusan pemilik, 5 Okt 2026), 1 surat suara, 27 kandidat. Lihat 8.11. Konfigurasi Mode Resmi `SEMUA_RT` tetap tersedia bila suatu saat diperlukan |
| Ulang Ketua RT 03 saja | 1 surat suara `RT_TERTENTU` = {RT 03} |
| Hanya RT 01-03 | 1 surat suara `RT_TERTENTU` = {01, 02, 03} |
| Voting dadakan | Mode `DADAKAN`, 1 surat suara, peserta hadir |

### 5.4 Statistik

- Penyebut partisipasi = jumlah pemilih berhak di snapshot surat suara/unit tersebut (termasuk tambahan darurat, ditampilkan terpisah sebagai "ditambah saat berlangsung").
- Untuk `RT_TERTENTU`, RT di luar cakupan tidak menampilkan surat suara itu di dashboard, meja, maupun rekap mereka.
- Di Meja Izin, pemilih yang tidak berhak di **semua** surat suara tampil dengan status **"Tidak berhak di pemilihan ini"** dan tombol Izinkan nonaktif.

### 5.5 Kriteria penerimaan

- **AC-5.1** Request simpan suara dengan kandidat RT 04 dari bilik RT 03 (dipalsukan) → ditolak, tidak ada suara tersimpan, tercatat.
- **AC-5.2** Pemilihan `RT_TERTENTU`={03}: Meja RT 05 tidak menampilkan surat suara itu; request izin dari Meja RT 05 ditolak.
- **AC-5.3** RT + RW: pemilih RT 02 melihat kandidat RT 02 lalu kandidat RW yang sama dengan pemilih RT lain.

---

## 6. Voter flow Mode Resmi (data pemilih)

### 6.1 Data pemilih ⟨K11⟩

Usulan kolom:

| Kolom | Wajib | Catatan |
|---|---|---|
| Voter ID internal (VTR-000123) | Otomatis | Bukan nomor berurutan untuk akses publik; ID akses memakai ULID |
| Nama lengkap | Ya | Bukan identifier unik |
| RT | Ya | Admin RT tidak bisa mengubah ke RT lain |
| Alamat singkat / no. rumah | Ya | Pembeda nama kembar di Meja Izin |
| Jenis kelamin | Opsional | Statistik |
| Tanggal lahir | Opsional | Pembeda duplikat |
| NIK | Opsional | Usulan: **tidak disimpan penuh**; hanya hash berkunci (untuk deteksi duplikat) + 4 digit terakhir (untuk tampilan masking `**********1234`) |
| No. HP | Opsional | Terenkripsi; tidak dipakai untuk blast (bagian 15 kebutuhan) |
| Status | Otomatis | Aktif / nonaktif |

### 6.2 Import Excel/CSV

```
Unduh template ─▶ Upload ─▶ Validasi per baris ─▶ Preview + laporan error ─▶ Konfirmasi ─▶ Simpan ─▶ Ringkasan
                                │
                                ├─ wajib kosong          → error baris
                                ├─ RT ≠ RT akun (ART)    → error baris
                                ├─ duplikat dalam file   → error baris
                                ├─ duplikat RT sendiri   → error / pilih "lewati"
                                └─ duplikat RT lain      → error "terdaftar di RT lain" ⟨K12⟩
```

- Batas ukuran file dan jumlah baris (usulan: 5 MB, 5.000 baris per file) supaya tidak membebani server.
- Import besar diproses di antrean; layar menampilkan progres.
- Import hanya membuat data jika **seluruh baris valid** atau pengguna memilih "import yang valid saja" secara eksplisit.
- Isi sel dibersihkan; saat ekspor, sel berawalan `=`, `+`, `-`, `@` diamankan (anti formula injection).

### 6.3 Tambah, edit, hapus, nonaktif

| Aksi | Sebelum BERLANGSUNG | Saat BERLANGSUNG/PAUSED | Setelah DITUTUP |
|---|---|---|---|
| Tambah manual | Bebas (cek duplikat) | Jalur darurat (6.5) | Bebas (tidak masuk snapshot pemilihan yang sudah jalan) |
| Edit nama/alamat | Bebas | Hanya ejaan, dengan alasan; tidak mengubah hak pilih | Bebas |
| Ubah RT | Hanya SA + alasan | Hanya SA + alasan; snapshot tidak ikut berubah ⟨K09⟩ | Hanya SA + alasan |
| Hapus | Jika tidak ada riwayat suara di pemilihan mana pun, dengan konfirmasi | ❌ | Sama dengan sebelum |
| Nonaktif | Bebas (alasan) | Tidak mengubah snapshot; pencabutan hak pilih hanya SA (lihat edge case E-38) | Bebas |

### 6.4 Snapshot

- Saat **Mulai**, sistem menyalin pemilih aktif yang masuk cakupan ke snapshot setiap surat suara (berikut RT saat itu).
- Pemilihan lain tidak terpengaruh; hak pilih tidak terbawa otomatis.
- Pindah RT setelah snapshot: pemilih tetap memilih di RT lama pada pemilihan ini ⟨K09⟩.

### 6.5 Tambah pemilih darurat (saat berlangsung)

```
Admin RT: [Tambah Pemilih Darurat]
   │  isi data + alasan dropdown
   │    (nama belum ada di data awal / salah ketik di data awal / pemilih baru disahkan panitia / lainnya + catatan)
   ▼
Sistem: cek duplikat RT sendiri dan lintas RT ⟨K12⟩
   │── ditemukan di RT lain → TOLAK: "Warga ini terdaftar di RT lain, memilih di RT asalnya"
   │── mirip di RT sendiri  → peringatan, ART pilih "ini orang yang sama" (batal) atau "orang berbeda" (alasan)
   ▼
Simpan ke master (tanda: DITAMBAH_SAAT_BERLANGSUNG) + masuk snapshot surat suara yang sesuai cakupan
   ▼
Langsung tampil di Meja Izin RT itu · audit log · notifikasi Super Admin
```

### 6.6 Status pemilih per surat suara

| Status | Arti | Tampilan di Meja |
|---|---|---|
| `TIDAK_BERHAK` | Tidak ada di snapshot surat suara ini | Abu + ikon ⛔ + teks, tombol nonaktif |
| `BELUM` | Berhak, belum ada suara sah di putaran ini | Tombol Izinkan aktif (jika ada bilik kosong) |
| `DI_BILIK` | Ada izin aktif | Biru + ikon + "Di Bilik 2" + tombol [Batalkan Izin] |
| `SUDAH` | Ada suara sah | Hijau + ✓ + teks |

Gabungan RT + RW di Meja dan dashboard: `RT ✓ / RW –`, `RT – / RW ✓`, `RT ✓ / RW ✓ = SELESAI`.

### 6.7 Kriteria penerimaan

- **AC-6.1** Import file berisi 3 baris kosong nama dan 2 duplikat → preview menunjukkan 5 error dengan nomor baris; tidak ada data tersimpan sebelum konfirmasi.
- **AC-6.2** Menghapus pemilih yang punya riwayat suara → ditolak, ditawarkan nonaktif.
- **AC-6.3** Tambah darurat nama yang ada di RT lain (NIK sama) → ditolak dengan pesan yang ditentukan; percobaan tercatat.
- **AC-6.4** Tambah darurat berhasil → muncul di Meja Izin RT itu dalam ≤ 1 siklus polling; dashboard menghitung "ditambah saat berlangsung" = 1.
- **AC-6.5** Kolom NIK di semua layar tampil termasking.

---

## 7. Alur Meja, Bilik, dan Token (Mode Resmi)

### 7.1 Slot perangkat

- Slot dibuat per pemilihan per RT ⟨K14⟩: `RTxx-MEJA` (1) dan `RTxx-01` .. `RTxx-0N` (N = maksimal bilik RT itu, default 3 ⟨K06⟩).
- Laptop Dashboard dan laptop admin tidak didaftarkan.

### 7.2 Token meja

```
SA: Perangkat ▸ RT 03 ▸ [Buat Token Meja] (re-auth)
  ▼
Token tampil SEKALI (mis. M4QK-7T2P-XR9D), masa berlaku ⟨K05⟩; token meja lama RT 03 (jika ada) langsung dicabut
  ▼
Di laptop Meja: buka /meja/pasang ▸ ketik token ▸ isi label laptop
  ▼
Server: token valid, belum dipakai, belum kedaluwarsa, slot cocok
  → token ditandai TERPAKAI; terbit sesi perangkat Meja (cookie aman, tahan restart browser)
  ▼
Laptop Meja terdaftar sebagai RT03-MEJA. Admin RT RT 03 dengan izin Petugas Meja login di laptop ini → tombol aksi aktif.
```

- Akun RT lain di laptop ini → tombol aksi nonaktif dengan teks "Laptop ini milik Meja RT 03".
- Laptop rusak → SA membuat token baru; sesi laptop lama langsung tidak berlaku.
- Token salah: dibatasi jumlah percobaan per IP; setiap kegagalan tercatat.

### 7.3 Token bilik

```
Laptop Meja ▸ menu Bilik ▸ slot "Bilik 2" ▸ [Buat Token]
  ▼
Token tampil (mis. K7P2-9XQM): sekali pakai, berlaku 10 menit ⟨K05⟩, terikat slot RT03-02
Token lama slot itu (jika belum dipakai) → HANGUS
  ▼
Laptop bilik: buka /bilik ▸ ketik token ▸ isi label laptop ("Asus Vivobook Pak Budi")
  ▼
Server: valid → token TERPAKAI; sesi perangkat Bilik RT03-02 diterbitkan;
        jika slot sudah punya laptop lain → laptop lama otomatis TERLEPAS
  ▼
Layar terkunci: "Bilik 2 · RT 03 · Menunggu pemilih"
```

- **Tersambung ulang**: browser tertutup/laptop restart di laptop yang sama → cookie sesi masih ada → langsung kembali ke slot tanpa token baru.
- **Ganti laptop** atau cookie hilang (mis. jendela penyamaran) → perlu token baru.
- **[Lepas]**: alasan dropdown (rusak, baterai habis, hilang, diganti, lainnya). Jika ada pemilih di bilik → konfirmasi tambahan "Pemilih sedang memilih. Suara yang belum dikonfirmasi tidak akan tersimpan." Sesi perangkat langsung dicabut; request berikutnya dari laptop itu ditolak dan layar menampilkan "Bilik ini sudah dilepas".

### 7.4 Perangkat Terhubung dan heartbeat

- Laptop bilik dan meja melakukan polling tiap ~2 detik; tiap polling sekaligus heartbeat.
- Status slot:

| Status | Syarat |
|---|---|
| Belum tersambung | Belum ada sesi perangkat |
| Menunggu | Tersambung, heartbeat < 30 detik, tidak ada izin |
| Dipesan | Ada izin aktif, pemilih belum menyentuh layar |
| Sedang dipakai | Pemilih sudah mulai |
| Terputus | Heartbeat terakhir > 30 detik ⟨K05⟩ → peringatan di dashboard RT dan SA |
| Dilepas | Dicabut oleh petugas |

- Kolom: label laptop, browser/OS (dari user agent), status, terakhir aktif, waktu tersambung, IP (info).
- Bilik berstatus Terputus **tidak dipilih** untuk izin baru.

### 7.5 Izinkan Memilih

```
Petugas cari nama (min. 2 huruf, hanya pemilih RT sendiri)
  hasil: Nama · alamat singkat · Voter ID · status per surat suara
  ▼
[Izinkan Memilih] ▸ "Izinkan BUDI SANTOSO (Jl. Mawar 12) memilih?" [Ya] [Batal]
  ▼
Server (satu transaksi, dikunci):
  1. cek tiga syarat (akun, perangkat Meja RT sama, status)
  2. cek pemilih: berhak, ada surat suara yang BELUM, tidak punya izin aktif
  3. pilih bilik: slot RT sama, status Menunggu, heartbeat segar
     (usulan: yang paling lama menganggur supaya merata)
  4. buat izin (AKTIF), tandai slot Dipesan, catat akun + perangkat meja
  ▼
Berhasil → Meja: "Silakan ke BILIK 2" (besar)
Gagal    → pesan jelas:
           "Semua bilik terpakai" · "Sudah memilih" · "Sedang di Bilik 1" ·
           "Tidak berhak di pemilihan ini" · "TPS sedang dijeda"
```

- Dua petugas mengklik nama yang sama bersamaan → hanya klik pertama berhasil; yang kedua melihat "Sedang di Bilik X".
- Tombol Izinkan nonaktif jika tidak ada bilik kosong (indikator di atas layar: "Bilik kosong: 2 dari 3").
- Tidak ada pilihan bilik manual (Fase 1).

### 7.6 Sesi bilik (sudut pandang pemilih)

```
[Menunggu pemilih]
   │ polling menerima izin
   ▼
[Kartu kandidat surat suara 1]  ← tanpa nama pemilih ⟨K28⟩
   │ sentuh kandidat (izin berubah DIPAKAI)
   ▼
[Review: foto + nomor + nama · "Sudah benar?"]  ── KEMBALI ──▶ kartu kandidat
   │ KONFIRMASI PILIHAN
   ▼
Server: simpan suara (transaksi + unique (pemilihan, surat suara, putaran, pemilih) + kunci idempoten)
   │
   ├── masih ada surat suara berikutnya (mis. RW) ─▶ [Kartu kandidat surat suara 2] ─▶ review ─▶ simpan
   ▼
[Terima kasih, suara Anda sudah tersimpan]  (± 5 detik)
   ▼
Layar dibersihkan (state, cache halaman, riwayat) ─▶ [Menunggu pemilih]
```

Aturan:

- Bilik hanya menerima surat suara dan kandidat milik izin aktif di slotnya.
- Setiap konfirmasi membawa **kunci idempoten** (izin + surat suara). Konfirmasi ulang/refresh → server menjawab "Anda sudah memilih" tanpa membuat suara kedua.
- Jika jawaban server tidak sampai (koneksi putus), bilik menampilkan "Sedang menyimpan, mohon tunggu" lalu mencoba lagi dengan kunci yang sama. Status akhir selalu mengikuti server.
- Kartu kandidat tanpa angka hitungan apa pun.

### 7.7 Izin: siklus hidup

| Status izin | Masuk ke status ini ketika | Efek |
|---|---|---|
| AKTIF | Izinkan Memilih berhasil | Slot Dipesan |
| DIPAKAI | Pemilih menyentuh kandidat pertama | Slot Sedang dipakai |
| SELESAI | Semua surat suara yang berhak sudah tersimpan | Slot kembali Menunggu |
| HANGUS | AKTIF tanpa disentuh selama 5 menit ⟨K05⟩ | Slot kembali Menunggu; status pemilih kembali BELUM |
| TIDAK_SELESAI | DIPAKAI tetapi diam (tanpa sentuhan) melewati batas ⟨K05⟩, atau pemilih pergi | Surat suara yang sudah tersimpan tetap; sisanya BELUM; pemilih bisa diizinkan lagi untuk sisanya |
| DIBATALKAN | Petugas [Batalkan Izin] sebelum konfirmasi pertama | Bilik terkunci lagi; status pemilih kembali BELUM |
| TERHENTI | Bilik dilepas / pemilihan dijeda paksa / ditutup saat izin aktif | Seperti TIDAK_SELESAI |

Semua perubahan status izin tercatat di audit log (tanpa pilihan kandidat).

**[Batalkan Izin]** hanya tersedia selama **belum ada satu pun konfirmasi** di izin tersebut. Jika surat suara pertama (mis. RT) sudah dikonfirmasi, pembatalan izin hanya menghentikan surat suara berikutnya; suara RT yang sudah masuk hanya bisa dibatalkan lewat alur koreksi. Alasan dropdown: salah klik nama, pemilih berubah pikiran sebelum masuk, bilik bermasalah, lainnya.

### 7.8 RT + RW dan pemulihan

- Izin satu kali mencakup semua surat suara yang berhak dan belum dipilih.
- Jika listrik/internet mati setelah RT tersimpan: saat tersambung kembali, server melihat RT = SUDAH. Jika izin masih berlaku, bilik langsung menampilkan RW. Jika izin sudah TIDAK_SELESAI, petugas mengizinkan ulang dan bilik hanya menampilkan RW.
- Sebaran suara RW per RT asal tersimpan (dari RT pemilih) dan hanya dilihat Super Admin setelah DITUTUP.

### 7.9 Kriteria penerimaan

- **AC-7.1** 20 request Izinkan bersamaan untuk 20 pemilih berbeda dengan 3 bilik kosong → tepat 3 berhasil, 17 menerima "Semua bilik terpakai", tidak ada bilik dengan 2 izin.
- **AC-7.2** 2 request Izinkan bersamaan untuk pemilih yang sama → tepat 1 berhasil.
- **AC-7.3** Token bilik dipakai dua kali → kedua kalinya ditolak. Token berumur 11 menit → ditolak. Token lama setelah token baru dibuat → ditolak.
- **AC-7.4** Bilik di-Lepas → request polling berikutnya dari laptop itu ditolak dalam ≤ 2 detik.
- **AC-7.5** Laptop bilik ditutup browsernya lalu dibuka lagi → kembali ke slot yang sama tanpa token.
- **AC-7.6** Konfirmasi dikirim 10 kali cepat (klik ganda, refresh) → tepat 1 suara.
- **AC-7.7** Izin tidak disentuh 5 menit → HANGUS, pemilih kembali BELUM, tercatat.
- **AC-7.8** Setelah layar "Terima kasih", tombol Back browser dan riwayat tidak menampilkan surat suara pemilih sebelumnya.
- **AC-7.9** RT tersimpan lalu laptop mati → izin ulang hanya menampilkan surat suara RW.

---

## 8. Alur Mode Dadakan (Fase 2)

Tahap 1 hanya mendefinisikan alur. Implementasi di Fase 2.

### 8.1 Persiapan

- Super Admin membuat pemilihan Mode `DADAKAN`, surat suara, kandidat.
- Sistem menyediakan **satu URL/QR** per pemilihan (ID acak, bukan angka berurutan). QR bisa diganti oleh panitia jika bocor (edge case E-31).
- Penugasan peran Panitia Dadakan dan Petugas Pintu ⟨K24⟩.
- Panitia mendaftarkan HP/tablet pinjaman (pakai token, seperti bilik) agar suara lewat perangkat itu bisa ditandai "dibantu".

### 8.2 Di pintu

```
Petugas Pintu: [Tambah Peserta] nama (+RT opsional)
  ▼
Sistem: buat nomor urut hadir (administrasi) + PIN acak 4 digit (tidak berurutan)
  ▼
PIN tampil SEKALI ke petugas → ditulis di kertas/stiker → diserahkan ke warga
  ▼
PIN disimpan sebagai hash berkunci (pepper server); tidak bisa dilihat lagi oleh siapa pun
```

- Opsi tambahan per pemilihan: PIN = 4 digit terakhir HP (usulan: nonaktif default karena mudah ditebak orang yang mengenal).
- Datang setelah gelombang dibuka → tetap bisa didata (ditandai "datang terlambat"), tercatat.
- Import daftar tertulis → PIN dibuat massal, dicetak sekali.

### 8.3 Gelombang

```
[TERTUTUP] ── [BUKA VOTING] (durasi ⟨K05⟩) ──▶ [DIBUKA, timer jalan]
                                                │  [Perpanjang +N menit]   (audit)
                                                │  [Tutup Sekarang]        (audit)
                                                ▼  waktu habis / ditutup
                                           [DITUTUP]
```

| Jenis gelombang | Timer | Siapa bisa masuk | Perangkat |
|---|---|---|---|
| Gelombang terbuka (gelombang 1) | Ya | Semua peserta hadir yang belum memilih di putaran ini | HP masing-masing |
| Gelombang bantuan (gelombang 2, dst.) | Tidak, ditutup manual | Peserta yang belum memilih | HP pinjaman terdaftar (boleh juga HP sendiri) |

Gelombang boleh diulang selama masih ada yang belum memilih.

### 8.4 Layar HP pemilih

```
[Menunggu pemungutan dibuka]          ← kolom nama/PIN tidak ada; polling
   │ gelombang dibuka
   ▼
[Cari nama]  min. 3 huruf · maks. 5 hasil · yang sudah memilih tidak tampil
   │ pilih nama
   ▼
[Masukkan PIN]  salah 3x → nama terkunci (hubungi panitia)
   │ benar → sesi pemilih pendek (hanya ID sementara)
   ▼
[Kartu kandidat] ─▶ [Review] ─ KEMBALI / KONFIRMASI ─▶ [Layar hijau: "Sudah memilih"]
                                                         ▼
                                       sesi dihapus; HP bisa dipakai orang berikutnya
```

- Tidak ada aturan satu HP satu suara.
- Pencarian dan PIN di-rate-limit per perangkat/IP dan per nama.
- **Batas waktu**: suara diterima jika PIN sudah diverifikasi sebelum gelombang tutup **dan** konfirmasi diterima server paling lambat batas waktu + masa tenggang (usulan 15 detik) ⟨K05⟩. Ini memenuhi "yang sudah menekan konfirmasi sebelum batas tetap dihitung" walau jaringan lambat.

### 8.5 Penanda "dibantu"

Usulan: penanda "dibantu" disimpan pada **catatan kehadiran** (peserta X memilih lewat perangkat pinjaman), **bukan pada suara**. Kalau disimpan di suara, penanda itu bisa membuka pilihan kelompok kecil (mis. 6 lansia). Jumlah suara dibantu tetap bisa dilaporkan sebagai angka.

### 8.6 Layar panitia

- Sudah/belum memilih per putaran (tanpa pilihan), sisa waktu, jumlah suara masuk per gelombang.
- Daftar nama dengan banyak gagal PIN dan nama terkunci, tombol [Buka Kunci] (alasan).
- Daftar "belum memilih" untuk dipanggil di gelombang bantuan.

### 8.7 Pulihkan Hak Pilih

```
Panitia pilih nama ▸ [Pulihkan Hak Pilih] ▸ alasan dropdown
   (nama dipakai orang lain / PIN terkunci / PIN hilang / gangguan teknis / lainnya + catatan)
   ▼
Sudah memilih?
   ├─ Ya  → suara lama dicari lewat tautan sementara (9.2) → status DIBATALKAN (tidak dihapus)
   │        → PIN baru dibuat (PIN lama hangus) → tampil sekali ke panitia
   └─ Tidak (terkunci/hilang) → kunci dibuka; PIN baru dibuat (PIN lama hangus)
   ▼
Peserta bisa memilih di gelombang berikutnya / jendela khusus
Audit: siapa, kapan, alasan, peserta, putaran (tanpa isi pilihan)
```

Pilihan lama **tidak pernah** ditampilkan ke panitia. Laporan hanya menampilkan jumlah total pembatalan.

### 8.8 Putaran 2

- Panitia menetapkan kandidat putaran 2, lalu membuka gelombang baru di putaran 2.
- Semua peserta hadir berstatus BELUM untuk putaran 2; **PIN lama dipakai ulang**.
- Peserta baru → didata di pintu, dapat PIN baru.

### 8.9 Rekonsiliasi

Panitia menginput **hitung kepala** hadir. Sistem membandingkan: hitung kepala vs jumlah peserta terdaftar vs jumlah suara sah per putaran. Selisih → tanda peringatan di layar panitia dan berita acara.

### 8.10 Kerahasiaan

- Tabel kehadiran dan tabel suara terpisah.
- Suara tidak menyimpan waktu presisi (usulan: hanya nomor gelombang), dan ID suara acak, supaya urutan/waktu tidak bisa dicocokkan dengan log PIN.
- Log aplikasi tidak memuat PIN, token, atau pilihan.

### 8.11 Penjaringan dengan banyak kandidat (mis. 27 calon RW)

Penjaringan calon RW dijalankan di Mode Dadakan. Konsekuensinya:

- **Layar HP**: 27 kartu tidak muat satu layar. Usulan: daftar bergulir dengan foto kecil, nomor urut besar, dan nama; urut nomor tetap; tanpa fitur pencarian kandidat (agar semua calon diperlakukan sama). Alternatif tata letak diajukan di Tahap 2.
- **Timer gelombang**: cari nama + PIN + membaca 27 calon butuh lebih lama dari surat suara biasa. Usulan default 10 menit untuk surat suara > 10 kandidat ⟨K05⟩.
- **Beban server**: ratusan HP × 27 foto. Foto ukuran kecil yang dioptimalkan, cache HTTP, dimuat saat layar "Menunggu" agar siap saat gelombang dibuka.
- **Hasil**: total, per calon, dan ranking di Layar Hasil internal setelah DITUTUP; sistem tidak menentukan siapa yang lolos; halaman publik hanya menampilkan nama yang lolos yang ditetapkan panitia ⟨K21⟩.
- **Urutan fase**: Mode Dadakan dijadwalkan di Fase 2, sedangkan penjaringan biasanya berlangsung **sebelum** pemilihan Ketua RW ⟨K33⟩.
- **Siapa boleh ikut**: daftar hadir diisi bebas, atau dipilih dari data warga terdaftar ⟨K34⟩.

### 8.12 Kriteria penerimaan

- **AC-8.1** Sebelum gelombang dibuka, endpoint cari nama dan PIN menolak request.
- **AC-8.2** Pencarian 2 huruf → ditolak; pencarian umum → maksimal 5 hasil; nama yang sudah memilih tidak tampil.
- **AC-8.3** PIN salah 3x → nama terkunci; PIN benar setelahnya tetap ditolak sampai dibuka panitia.
- **AC-8.4** 500 konfirmasi dalam 10 detik → semua tersimpan tepat sekali, tidak ada error server.
- **AC-8.5** Konfirmasi tiba 5 detik setelah timer habis (PIN diverifikasi sebelumnya) → diterima; tiba 60 detik setelahnya → ditolak.
- **AC-8.6** Pulihkan hak pilih untuk peserta yang sudah memilih → total suara sah berkurang 1, total dibatalkan bertambah 1, panitia tidak melihat pilihan lama.

---

## 9. Correction flow (koreksi/pembatalan suara)

### 9.1 Mode Resmi

```
Admin RT (RT sendiri): Koreksi ▸ cari pemilih ▸ pilih surat suara + putaran ▸ alasan dropdown
   (izin diberikan ke orang yang salah dan suara sudah masuk / pemilih dipaksa atau diintimidasi /
    gangguan teknis bilik / pemilih ternyata tidak berhak / lainnya + catatan wajib)
   ▼
Pengajuan berstatus DIAJUKAN · audit · notifikasi ke Super Admin
   ▼
Super Admin (HP): buka notifikasi ▸ login (2FA) ▸ lihat pengajuan
   (pemilih, RT, surat suara, alasan, pengaju, pemberi izin — TANPA pilihan kandidat)
   ▼
[Setujui] atau [Tolak + alasan]   (re-auth berlaku 15 menit → pengajuan berikutnya cukup 1 ketukan)
   ▼
Disetujui:
   - sistem menolak jika penyetuju = akun pemberi izin suara itu ⟨K17⟩
   - suara → DIBATALKAN (sebelum: SAH, sesudah: DIBATALKAN, pelaku, waktu, alasan)
   - status pemilih untuk surat suara itu → BELUM (jika masih BERLANGSUNG)
   - pemilih bisa diizinkan lagi di Meja untuk surat suara itu saja
```

| Status pengajuan | Keterangan |
|---|---|
| DIAJUKAN | Menunggu Super Admin |
| DISETUJUI | Suara dibatalkan |
| DITOLAK | Suara tetap SAH; alasan penolakan tercatat |
| DITARIK | Ditarik pengaju sebelum diputuskan |

Satu suara hanya boleh punya satu pengajuan DIAJUKAN pada satu waktu. Koreksi setelah pemilihan DITUTUP ⟨K16⟩.

### 9.2 Mode Dadakan: masalah tautan dan usulan mekanisme ⟨K25⟩

Masalahnya: Mode Dadakan tidak menyimpan hubungan pemilih–pilihan, tetapi "Pulihkan Hak Pilih" untuk orang yang sudah memilih harus bisa menemukan suaranya. Desain final skema ada di Tahap 2; arah yang diminta disetujui sekarang:

| | **Opsi A (usulan): tautan sementara terkunci + penghancuran kunci** | Opsi B: tanda terima ke pemilih |
|---|---|---|
| Cara kerja | Saat menyimpan suara, server menyimpan `tautan = HMAC(kunci_pemilihan, peserta + surat suara + putaran)` di baris suara. Kunci pemilihan disimpan terpisah dari database (secret server). Pulihkan hak pilih → server menghitung HMAC yang sama → menemukan suara → membatalkan. | Pemilih menerima kode acak setelah memilih; pembatalan butuh kode itu. |
| Setelah pemilihan DITUTUP (masa koreksi selesai) | Kolom tautan dikosongkan dan kunci pemilihan dihapus → hubungan tidak bisa dipulihkan lagi, termasuk oleh admin database | Kode tidak lagi berguna |
| Kasus "nama dipakai orang lain" | Bisa: panitia cukup memilih nama korban | **Gagal**: kode dipegang orang yang memakai nama tersebut |
| Risiko sisa | Selama pemilihan berlangsung, orang yang memegang database **dan** secret server bisa mencocokkan. Dimitigasi dengan pembatasan akses server dan masa hidup singkat | Lebih rahasia, tapi tidak menyelesaikan kebutuhan utama |

Rekomendasi: **Opsi A**.

### 9.3 Kriteria penerimaan

- **AC-9.1** Admin RT membuka pengajuan untuk pemilih RT lain → ditolak.
- **AC-9.2** Layar persetujuan dan audit log tidak memuat kandidat yang dipilih.
- **AC-9.3** Penyetuju = akun yang memberi izin → ditolak.
- **AC-9.4** Setelah disetujui, Meja menampilkan pemilih sebagai BELUM untuk surat suara itu dan bisa diizinkan lagi; surat suara lain tidak terpengaruh.
- **AC-9.5** Suara dibatalkan tetap ada di database dan dihitung di kolom "dibatalkan" rekap.

---

## 10. Hasil, verifikasi, berita acara, publish/unpublish

### 10.1 Selama BERLANGSUNG

- Admin RT dan Layar Live Partisipasi: hanya jumlah dan persen sudah memilih per surat suara (mis. "212 dari 500 = 42%"), status bilik, sisa waktu (Mode Dadakan per gelombang).
- Super Admin: hitungan per kandidat tersembunyi. [Buka Hitungan Sementara] → alasan dropdown + re-auth → tercatat → notifikasi ke Super Admin lain ⟨K23⟩.

### 10.2 Checklist VERIFIKASI (per unit/RT)

```
☐ Rekonsiliasi: izin SELESAI/TIDAK_SELESAI vs suara masuk per bilik dan per RT = cocok (selisih harus dijelaskan)
☐ Tidak ada pengajuan koreksi berstatus DIAJUKAN
☐ Catatan kejadian terisi (otomatis dari audit: bilik diganti, koreksi, pemilih darurat, jeda) + catatan manual
☐ [Tampilkan Hasil] di lokasi ⟨K20⟩
☐ Penetapan terpilih oleh panitia ⟨K21⟩
☐ Berita acara dibuat → dicetak → ditandatangani → DISAHKAN
```

**Rekonsiliasi Mode Resmi**: setiap suara mencatat izin dan slot bilik asalnya. Sistem memeriksa (a) setiap suara punya izin yang sah, (b) jumlah suara per bilik = jumlah konfirmasi tercatat per bilik, (c) jumlah pemilih berstatus SUDAH = jumlah suara sah + dibatalkan-lalu-memilih-ulang. Selisih → peringatan merah di dashboard dan berita acara.

### 10.3 Layar Hasil

- Tombol [Tampilkan Hasil] menampilkan diagram persen + jumlah per kandidat **sekaligus**.
- Layar internal, untuk momen pengumuman di lokasi. Setiap pemakaian tercatat.
- Kandidat seri, kuorum tidak tercapai, atau kotak kosong unggul ditampilkan apa adanya dengan label; sistem tidak menyimpulkan ⟨K03⟩ ⟨K04⟩.

### 10.4 Berita acara

```
[Buat Draf] (ART untuk RT sendiri / SA semua) ─▶ DRAFT v1 (PDF, checksum, pembuat, waktu)
       │ cetak, tanda tangan panitia/saksi/perwakilan kandidat
       ▼
SA: [Sahkan] (re-auth) ─▶ DISAHKAN v1 (terkunci)
       │ perlu perubahan (mis. setelah koreksi saat UNPUBLISHED)
       ▼
SA: [Buat Versi Baru] + alasan ─▶ DRAFT v2 ─▶ DISAHKAN v2   (v1 tetap tersimpan, ditandai "digantikan")
```

Isi mengikuti bagian 9A kebutuhan. Tidak memuat siapa memilih siapa. Unit berita acara: per RT untuk surat suara `PER_RT`; keseluruhan untuk surat suara `SEMUA_RT`/`RT_TERTENTU`.

### 10.5 Publish dan halaman publik

- Prasyarat publish: lihat tabel 4.2.
- Halaman publik menampilkan per unit: judul jabatan + foto besar + nama **terpilih**. Contoh: pilih "RT 01 ▼" → "Ketua RT 01 Terpilih: Bapak X"; "Ketua RW Terpilih: Bapak Y".
- **Tidak ada** ranking, jumlah suara, partisipasi per kandidat, atau data pemilih.
- Penjaringan (27 calon): yang ditampilkan publik ⟨K21⟩.
- Unit yang belum ditetapkan (mis. seri menunggu putaran 2): "Belum ditetapkan".

### 10.6 Unpublish dan publish ulang

```
SA: [Unpublish] ▸ alasan ▸ konfirmasi ▸ re-auth
  → publik: "Hasil sedang ditinjau ulang" · suara tidak berubah · audit · notifikasi
  → (opsional) kembali ke VERIFIKASI: koreksi, penetapan ulang, berita acara versi baru
  → [Publish] lagi (prasyarat dicek ulang)
```

### 10.7 Rekap

- Per RT dan total: berhak, sudah/belum, partisipasi, suara per kandidat, dibatalkan, ditambah saat berlangsung, riwayat putaran/gelombang.
- PDF, Excel (aman formula injection), dan tampilan siap cetak. Hanya ≥ DITUTUP. Admin RT hanya RT sendiri.

### 10.8 Kriteria penerimaan

- **AC-10.1** Saat BERLANGSUNG, semua endpoint dan halaman Admin RT tidak mengembalikan angka per kandidat (diuji dengan request langsung).
- **AC-10.2** Halaman publik saat status bukan PUBLISHED → tidak menampilkan nama terpilih.
- **AC-10.3** Berita acara DISAHKAN tidak bisa diubah; perubahan membuat versi baru dengan checksum berbeda.
- **AC-10.4** Ekspor Excel dengan nama pemilih `=HYPERLINK(...)` → tersimpan sebagai teks, bukan rumus.

---

## 11. Backup flow

```
Terjadwal (⟨K27⟩) ─┐
Backup Now (SA) ───┴─▶ dump database + foto kandidat ─▶ enkripsi ─▶ simpan lokal + salin ke lokasi off-server
                                                                         │
                                         riwayat: tanggal, ukuran, status, pembuat, checksum
                                                                         │
                                         gagal → status GAGAL + notifikasi SA + peringatan dashboard
```

| Aksi | Kontrol |
|---|---|
| Backup Now | SA, re-auth, audit |
| Unduh backup | SA, re-auth, audit; file tetap terenkripsi |
| Restore | SA, re-auth, ketik konfirmasi; **ditolak jika ada pemilihan BERLANGSUNG/PAUSED**; sistem otomatis membuat backup sebelum restore; notifikasi ke SA lain; audit dicatat sebelum dan sesudah restore ⟨K27⟩ |
| Uji restore | Latihan wajib di server uji sebelum hari H (bagian dari prosedur Tahap 5) |

Backup **berbeda** dari import/ekspor pemilih: backup adalah salinan seluruh sistem untuk pemulihan bencana.

Catatan: restore mengembalikan database ke titik lampau, termasuk audit log. Usulan: audit log entri "restore dilakukan" juga ditulis ke file log terpisah di luar database sehingga jejak restore tidak hilang.

**Kriteria penerimaan**: **AC-11.1** backup gagal (mis. tujuan off-server tidak bisa dijangkau) → status GAGAL dan notifikasi; **AC-11.2** restore saat BERLANGSUNG → ditolak; **AC-11.3** file backup tidak bisa dibuka tanpa kunci.

---

## 12. Audit flow

### 12.1 Isi setiap entri

| Field | Isi |
|---|---|
| Waktu | Waktu server |
| Aktor | Akun admin / perangkat (slot + label) / sistem (job terjadwal) |
| Peran dan RT aktor | Saat kejadian |
| Perangkat dan IP | Jika ada |
| Aksi | Kode kejadian (mis. `voter.emergency_added`) |
| Objek | Tipe + ID acak |
| Pemilihan, surat suara, putaran, RT | Jika relevan |
| Alasan | Dari dropdown + catatan |
| Sebelum/sesudah | Perubahan field (tanpa data rahasia) |
| Hash berantai | Hash entri sebelumnya + entri ini |

**Tidak boleh berisi**: pilihan kandidat, PIN, token, password, NIK penuh.

### 12.2 Katalog kejadian

| Kategori | Kejadian |
|---|---|
| Akun | login berhasil/gagal, logout, lockout, 2FA aktif/reset, ganti password, reset password oleh SA, buat/ubah/nonaktif akun, ubah role/izin Petugas Meja, akses ditolak (403) |
| Pemilihan | buat, ubah konfigurasi, READY, mulai, jeda/lanjut (global dan TPS), tutup, verifikasi, publish, unpublish, batal, arsip, buka putaran |
| Surat suara/kandidat | buat, ubah, ubah cakupan, ubah foto, mundur/nonaktif |
| Pemilih | tambah, import (ringkasan), edit, nonaktif, hapus, tambah darurat, koreksi RT, cabut hak pilih, duplikat ditolak |
| Perangkat | token meja/bilik dibuat, dipakai, hangus, dicabut, salah (percobaan), bilik dipasang, tersambung ulang, terputus > 30 detik, dilepas |
| Izin | diberikan, dipakai, selesai, hangus, tidak selesai, dibatalkan, terhenti |
| Suara | tersimpan (hanya ID suara, surat suara, slot; **tanpa kandidat**), ditolak (alasan teknis) |
| Koreksi | diajukan, disetujui, ditolak, ditarik; pulihkan hak pilih (Dadakan) |
| Hasil | buka hitungan sementara, tampilkan hasil, penetapan terpilih, berita acara dibuat/disahkan/versi baru, rekap diunduh, **detail suara dibuka** (alasan, data yang dilihat) |
| Dadakan | peserta ditambah, PIN dibuat ulang, gelombang dibuka/diperpanjang/ditutup, PIN terkunci/dibuka, hitung kepala diinput |
| Sistem | backup dibuat/gagal/diunduh, restore, perubahan pengaturan |

### 12.3 Integritas

- Aplikasi tidak punya fitur ubah/hapus audit log.
- Database: user aplikasi tidak punya hak `UPDATE`/`DELETE` pada tabel audit (atau trigger penolak).
- Hash berantai + perintah verifikasi untuk mendeteksi baris yang diubah langsung di database.
- Super Admin bisa menyaring dan mengekspor; ekspor itu sendiri tercatat.

**Kriteria penerimaan**: **AC-12.1** percobaan `UPDATE`/`DELETE` dari user aplikasi ke tabel audit → gagal di level database; **AC-12.2** mengubah satu baris secara manual → perintah verifikasi melaporkan rantai rusak; **AC-12.3** pencarian teks "PIN"/token/nama kandidat di log aplikasi hasil uji → tidak ada data rahasia.

---

## 13. Notifikasi internal

Tidak ada notifikasi ke warga. Hanya ke Super Admin / Ketua Panitia ⟨K23⟩.

| Kejadian | Penerima |
|---|---|
| Detail Suara dibuka | Semua SA lain + Ketua Panitia |
| Hitungan per kandidat dibuka saat berlangsung | Semua SA lain + Ketua Panitia |
| Pemilih ditambah darurat | Semua SA |
| Pengajuan koreksi baru | Semua SA |
| Perubahan akun/role SA | Semua SA |
| Publish / unpublish | Semua SA + Ketua Panitia |
| Backup gagal, restore dilakukan | Semua SA |
| Selisih rekonsiliasi, perangkat terputus > 30 detik | Dashboard (bukan email, agar tidak membanjir) |

---

## 14. Edge case

Format: kondisi → tindakan sistem → berwenang → hasil akhir → audit log. Kode audit bersifat sementara, final di Tahap 3.

### 14.1 Infrastruktur dan perangkat

| No | Kondisi | Tindakan sistem | Berwenang | Hasil akhir | Audit log |
|---|---|---|---|---|---|
| E-01 | Listrik mati di TPS, laptop pakai baterai | Tidak ada; server di cloud tidak terpengaruh | — | Pemungutan lanjut selama baterai dan internet ada | — |
| E-02 | Listrik mati, laptop bilik mati saat pemilih di dalam, **suara belum dikonfirmasi** | Heartbeat hilang > 30 dtk → slot Terputus, peringatan. Izin menjadi TERHENTI saat bilik dilepas atau batas diam terlewati | Petugas Meja (Lepas bilik / izinkan ulang) | Tidak ada suara; pemilih BELUM, boleh diizinkan ulang ke bilik lain | `device.disconnected`, `permit.stopped`, `device.released` |
| E-03 | Sama, **suara sudah dikonfirmasi dan diterima server** | Server tetap acuan | — | Pemilih SUDAH untuk surat suara itu; sisa surat suara (mis. RW) masih BELUM | `vote.stored`, `permit.incomplete` |
| E-04 | Konfirmasi terkirim tapi tidak jelas apakah sampai (koneksi putus) | Bilik mengulang dengan kunci idempoten yang sama; jika laptop mati, Meja melihat status dari server | Petugas Meja melihat status | Ada suara di server = SUDAH; tidak ada = BELUM. Tidak ada status menggantung | `vote.stored` atau tidak ada |
| E-05 | Internet TPS mati | Semua laptop TPS kehilangan koneksi; Perangkat Terhubung menunjukkan semua Terputus | Admin RT: pindah ke hotspot cadangan; **Jeda TPS** jika > beberapa menit ⟨K15⟩ | Setelah tersambung, sesi perangkat pulih tanpa token baru; izin yang lewat batas menjadi HANGUS/TIDAK_SELESAI | `tps.paused`/`tps.resumed` + alasan, `device.disconnected` |
| E-06 | Refresh browser bilik saat memilih | Sesi perangkat tetap; server mengirim ulang surat suara yang belum dipilih | — | Pemilih melanjutkan; pilihan yang belum dikonfirmasi harus diulang | — |
| E-07 | Laptop bilik restart | Seperti E-06; cookie tahan restart | — | Kembali ke slot; jika ada izin aktif, lanjut | `device.reconnected` |
| E-08 | Laptop bilik rusak/diganti | Petugas Lepas slot (alasan) → Buat Token → pasang laptop baru | Petugas Meja | Slot sama, laptop baru; laptop lama ditolak | `device.released`, `token.created`, `device.paired` |
| E-09 | Laptop Meja rusak | Admin RT menghubungi SA → SA buat token meja baru (bisa dari HP) → dipasang di laptop cadangan | SA | Meja lama dicabut, meja baru aktif. Selama itu izin tidak bisa diberikan | `token.meja_created`, `device.revoked`, `device.paired` |
| E-10 | Token bilik salah/kedaluwarsa/bekas | Ditolak dengan pesan umum; percobaan berulang di-rate-limit | — | Petugas membuat token baru | `token.rejected` |
| E-11 | Akun RT 05 dipakai di laptop Meja RT 03 | Tombol aksi nonaktif; request langsung ditolak | — | Tidak ada izin diberikan | `access.denied` |
| E-12 | Server lambat/beban puncak | Antrean dan index; respons idempoten; bilik menampilkan "Sedang menyimpan" | — | Suara tersimpan sekali | — |

### 14.2 Pemilih dan bilik (Mode Resmi)

| No | Kondisi | Tindakan sistem | Berwenang | Hasil akhir | Audit log |
|---|---|---|---|---|---|
| E-13 | Pemilih keluar bilik sebelum konfirmasi | Izin DIPAKAI → diam melewati batas → TIDAK_SELESAI; layar bilik dibersihkan | Petugas boleh [Batalkan Izin] lebih cepat jika belum ada konfirmasi | Pemilih BELUM; bisa kembali dan diizinkan ulang | `permit.incomplete` / `permit.cancelled` |
| E-14 | Pemilih salah tekan kandidat (belum konfirmasi) | Layar review → KEMBALI | Pemilih | Memilih ulang | — |
| E-15 | Pemilih merasa salah pilih **setelah** konfirmasi | Tidak ada tombol ubah | Admin RT ajukan koreksi → SA setujui | Suara lama DIBATALKAN, pemilih boleh memilih lagi | `correction.*` |
| E-16 | Petugas salah klik nama (izin ke orang yang salah), belum konfirmasi | [Batalkan Izin] + alasan | Petugas Meja | Bilik terkunci, status kembali BELUM; izinkan orang yang benar | `permit.cancelled` |
| E-17 | Sama, tapi orang yang salah **sudah** konfirmasi | Alur koreksi untuk suara atas nama pemilih tersebut | ART ajukan, SA setujui | Suara DIBATALKAN; pemilih aslinya bisa memilih | `correction.*` |
| E-18 | Konfirmasi ditekan dua kali / refresh setelah konfirmasi | Unique constraint + kunci idempoten | — | Satu suara; layar "Anda sudah memilih" | `vote.duplicate_rejected` |
| E-19 | Dua petugas mengizinkan pemilih yang sama bersamaan | Transaksi + kunci | — | Hanya satu izin | `permit.granted` (sekali) |
| E-20 | Semua bilik terpakai | Tombol Izinkan nonaktif; "Semua bilik terpakai" | — | Pemilih menunggu | — |
| E-21 | Izin tidak dipakai 5 menit | Job terjadwal menandai HANGUS | Sistem | Status kembali BELUM, bilik kosong | `permit.expired` |
| E-22 | Nama tidak ditemukan di Meja | Pesan "Nama tidak ditemukan di RT ini". Tidak menampilkan RT lain | Admin RT: cek data → tambah darurat bila memang belum terdata | Terdaftar lalu bisa diizinkan, atau ditolak jika terdaftar di RT lain | `voter.emergency_added` / `voter.duplicate_rejected` |
| E-23 | Nama kembar dalam satu RT | Hasil pencarian menampilkan alamat singkat + Voter ID | Petugas mencocokkan dengan KTP/alamat | Izin ke orang yang benar | — |
| E-24 | Pemilih datang ke TPS RT yang salah | Meja RT ini tidak menampilkan pemilih RT lain; tidak ada jalur lintas RT | Petugas mengarahkan ke TPS RT asal | Memilih di RT asal | — (atau `voter.duplicate_rejected` jika dicoba ditambah) |
| E-25 | RT sudah, RW belum (pemilih pergi/bilik mati) | Status `RT ✓ / RW –` | Petugas izinkan ulang | Bilik hanya menampilkan RW | `permit.incomplete`, `permit.granted` |
| E-26 | Pemilih disabilitas/lansia butuh pendamping | Prosedur offline; usulan: petugas bisa menandai izin "didampingi" (di izin, bukan di suara) | Petugas Meja | Tercatat untuk berita acara | `permit.assisted` |

### 14.3 Pemilihan dan hasil

| No | Kondisi | Tindakan sistem | Berwenang | Hasil akhir | Audit log |
|---|---|---|---|---|---|
| E-27 | Kandidat mundur/dinonaktifkan saat berlangsung | ⟨K18⟩ Usulan: tetap tampil dengan label "Mengundurkan diri"; suara masuk tetap tercatat; penetapan oleh panitia | SA + alasan | Tercatat di berita acara | `candidate.withdrawn` |
| E-28 | Pemilihan dijeda | Izin baru ditolak; yang di bilik boleh selesai ⟨K15⟩; Mode Dadakan: timer berhenti | SA | Lanjut setelah [Lanjutkan] | `election.paused/resumed` |
| E-29 | Pemilihan ditutup saat ada pemilih di bilik | Konfirmasi ke SA menampilkan jumlah bilik aktif ⟨K29⟩ | SA | Suara yang belum dikonfirmasi tidak tersimpan; izin TERHENTI | `election.closed`, `permit.stopped` |
| E-30 | Hasil ternyata salah (mis. koreksi terlewat) setelah PUBLISHED | Unpublish → verifikasi ulang → berita acara versi baru → publish | SA | Hasil diperbarui; jejak versi lama tersimpan | `election.unpublished`, `minutes.new_version`, `election.published` |
| E-31 | Seri | Ditampilkan apa adanya dengan label "Seri" ⟨K03⟩ | Panitia menetapkan (putaran 2/musyawarah) | Penetapan tercatat di berita acara | `result.winner_set` |
| E-32 | Kuorum tidak tercapai | Indikator "Kuorum belum tercapai" ⟨K04⟩, tidak memblokir | Panitia | Tercatat di berita acara | — |
| E-33 | Selisih rekonsiliasi | Peringatan merah di dashboard dan berita acara; publish tidak diblokir tapi wajib catatan penjelasan | SA | Catatan di berita acara | `reconciliation.mismatch` |

### 14.4 Akses dan data

| No | Kondisi | Tindakan sistem | Berwenang | Hasil akhir | Audit log |
|---|---|---|---|---|---|
| E-34 | Admin RT mencoba akses data RT lain (menu, URL, API, ekspor) | 403/404 | — | Ditolak | `access.denied` |
| E-35 | Super Admin membuka Detail Suara | Hanya ≥ DITUTUP; re-auth; alasan dropdown; notifikasi | SA | Data tampil read-only, tidak bisa diekspor massal (usulan) | `vote_detail.opened` + data yang dilihat |
| E-36 | Super Admin membuka Detail Suara saat BERLANGSUNG | Ditolak | — | — | `access.denied` |
| E-37 | Akun Admin RT diduga bocor | SA nonaktifkan akun, putus semua sesinya, reset 2FA | SA | Akun tidak bisa dipakai; tindakannya bisa ditelusuri | `user.disabled`, `session.revoked_all` |
| E-38 | Pemilih ternyata tidak berhak (mis. meninggal, belum cukup umur) saat berlangsung | Usulan: SA "cabut dari snapshot" dengan alasan; jika sudah memilih → lewat koreksi | SA | Pemilih TIDAK_BERHAK; penyebut partisipasi berkurang | `snapshot.voter_revoked` |
| E-39 | Backup gagal | Status GAGAL, notifikasi, peringatan | SA | Ulangi Backup Now / periksa tujuan | `backup.failed` |
| E-40 | Restore diperlukan | Lihat Bagian 11; ditolak saat BERLANGSUNG | SA | Database kembali ke titik backup | `backup.restored` (DB + file log terpisah) |
| E-41 | Pemilihan baru dibuat lama setelah pemilihan sebelumnya | Pemilihan baru kosong; pemilih master dipakai ulang tapi snapshot baru; status pemilih lama tidak terbawa; slot dan token baru ⟨K14⟩ | SA | Tidak ada data bercampur | `election.created` |

### 14.5 Mode Dadakan

| No | Kondisi | Tindakan sistem | Berwenang | Hasil akhir | Audit log |
|---|---|---|---|---|---|
| E-42 | QR bocor ke orang di luar ruangan | Tetap butuh nama + PIN fisik; rate limit; panitia bisa [Ganti QR] | Panitia | QR lama tidak berlaku | `qr.rotated` |
| E-43 | PIN ditebak | 3x salah → terkunci; rate limit per nama dan IP; PIN hanya bisa dicoba saat gelombang dibuka | Panitia membuka kunci | Penebak terhenti; pemilik sah dapat PIN baru | `pin.locked`, `pin.reissued` |
| E-44 | Nama dipakai orang lain (pemilik sah datang, status sudah memilih) | Pulihkan Hak Pilih → suara lama DIBATALKAN, PIN baru | Panitia utama | Pemilik sah memilih di gelombang berikutnya | `voter_right.restored` |
| E-45 | Dua HP memakai nama yang sama bersamaan | Unique constraint; yang kedua: "Nama ini sudah memilih" | — | Satu suara | `vote.duplicate_rejected` |
| E-46 | Nama tidak ditemukan di HP | "Nama tidak ditemukan. Jika Anda belum memilih, hubungi panitia." | Petugas Pintu menambah peserta | Dapat PIN, ikut gelombang berikut | `attendee.added` |
| E-47 | Pemilih tanpa HP | Gelombang bantuan dengan HP pinjaman | Panitia | Memilih sendiri, layar menghadap pemilih | `wave.opened`, kehadiran ditandai dibantu |
| E-48 | Gelombang kedua dibuka | Hanya peserta BELUM yang muncul di pencarian dan bisa memasukkan PIN | Panitia | | `wave.opened/closed` |
| E-49 | Timer habis saat pemilih sedang di layar review | Masa tenggang ⟨K05⟩; setelah itu ditolak dengan pesan "Waktu habis, tunggu gelombang berikutnya" | — | Ikut gelombang berikutnya | `vote.rejected_late` (tanpa identitas) |
| E-50 | Hitung kepala ≠ jumlah suara | Tanda peringatan; catatan wajib di berita acara | Panitia | | `headcount.entered`, `reconciliation.mismatch` |

---

## 15. Daftar keputusan yang perlu Anda setujui

Kolom "Usulan" adalah rekomendasi saya. Anda bisa menjawab misalnya "setuju semua kecuali K12 dan K18".

### Opsi per pemilihan (bagian 13 kebutuhan)

| ID | Topik | Usulan | Alternatif |
|---|---|---|---|
| K01 | Kandidat tunggal / kotak kosong | Opsi per surat suara. Jika kandidat hanya 1, opsi "Kotak Kosong" **otomatis aktif** (bisa dimatikan SA). Kotak kosong tampil sebagai kartu terakhir dan dihitung seperti kandidat | Tidak ada kotak kosong; calon tunggal langsung ditetapkan panitia |
| K02 | Suara kosong / abstain | Opsi per surat suara, **default nonaktif**. Jika aktif, kartu "Tidak memilih" di akhir, dihitung terpisah, tidak masuk persentase kandidat | Selalu aktif |
| K03 | Aturan seri | Sistem hanya memberi label "Seri"; panitia menetapkan (putaran 2/musyawarah/undian offline) dan menulis keputusan di penetapan terpilih + berita acara | Sistem otomatis membuka putaran 2 untuk kandidat seri |
| K04 | Kuorum | Opsi per surat suara, default **tidak ada**. Jika diisi (mis. 50%), sistem hanya menampilkan indikator tercapai/tidak, tidak memblokir | Kuorum memblokir publish |
| K05 | Waktu dan batas | Izin hangus jika tidak disentuh **5 menit**; sesi bilik berakhir jika diam **3 menit** (peringatan "Masih memilih?" 30 detik sebelumnya); token bilik **10 menit**; token meja **24 jam** sebelum dipakai; terputus = **30 detik** tanpa heartbeat; gelombang default **5 menit**; masa tenggang submit Dadakan **15 detik**. Semua bisa diubah per pemilihan | Angka lain |
| K06 | Maksimal bilik per RT | Default **3**, bisa diatur per RT per pemilihan, rentang 1-10 | — |
| K07 | Merangkap Admin RT dan Petugas Meja | **Boleh**; setiap aksi tetap atas nama akun | Harus akun berbeda |
| K08 | Berita acara sebelum publish | Berita acara semua unit **wajib DISAHKAN** SA sebelum tombol Publish aktif | Publish tanpa menunggu |

### Data dan aturan pemilih

| ID | Topik | Usulan | Alternatif |
|---|---|---|---|
| K09 | Warga pindah RT di tengah periode | Ikut RT di snapshot (saat pemilihan dimulai) | — |
| K10 | Visi-misi kandidat | **Tidak** di Fase 1 (layar bilik tetap sederhana) | Teks singkat di halaman publik saja / juga di bilik |
| K11 | Kolom data pemilih | Lihat tabel 6.1. NIK opsional, **tidak disimpan penuh** (hash berkunci + 4 digit terakhir) | NIK disimpan penuh terenkripsi / tidak ada NIK sama sekali |
| K12 | Aturan duplikat lintas RT | **Tolak** jika NIK sama, atau nama + tanggal lahir sama. **Peringatan** (bukan tolak) jika hanya nama yang sama: Admin RT harus menyatakan "orang berbeda" + alasan, tercatat, notifikasi SA. (Tolak berdasarkan nama saja akan memblokir warga yang namanya kebetulan sama.) | Tolak setiap kali nama sama |

### Operasional pemilihan

| ID | Topik | Usulan | Alternatif |
|---|---|---|---|
| K13 | Pemilihan aktif bersamaan | Hanya **1 pemilihan** BERLANGSUNG/PAUSED pada satu waktu (RT + RW tetap 1 pemilihan dengan 2 surat suara) | Boleh beberapa, Meja memilih pemilihan aktif |
| K14 | Slot dan token per pemilihan | Slot dan token **per pemilihan**; perangkat dilepas otomatis saat pemilihan DITUTUP | Slot permanen lintas pemilihan |
| K15 | Jeda per TPS | Tambahan **Jeda TPS RT** (di luar PAUSED global): oleh Admin RT dari laptop Meja atau SA, alasan dropdown. Saat jeda (global maupun TPS), izin baru ditolak, pemilih yang sedang di bilik boleh menyelesaikan | Hanya jeda global oleh SA |
| K16 | Koreksi setelah DITUTUP | Koreksi tetap bisa diajukan/disetujui saat DITUTUP, VERIFIKASI, dan UNPUBLISHED, tetapi **hanya membatalkan suara, tanpa memilih ulang** (dicatat di berita acara). Memilih ulang hanya terjadi saat BERLANGSUNG | Koreksi hanya saat BERLANGSUNG / boleh membuka jendela pilih ulang |
| K17 | Siapa yang tidak boleh membatalkan | Akun pemberi izin **tidak boleh** menjadi pengaju maupun penyetuju koreksi suara itu. Jika RT hanya punya 1 Admin RT (yang memberi izin), SA yang mengajukan atas laporan RT | Larangan hanya untuk penyetuju |
| K18 | Kandidat mundur saat berlangsung | Tetap tampil di surat suara dengan label "Mengundurkan diri"; suara yang masuk tetap dihitung apa adanya; panitia menetapkan sikap di VERIFIKASI | Kandidat disembunyikan sejak saat itu / pemilihan dijeda dan diulang |
| K22 | Putaran 2 di Mode Resmi | Boleh, dibuka SA untuk surat suara/unit tertentu dari status DITUTUP/VERIFIKASI (sebelum PUBLISHED) dengan kandidat pilihan panitia; pemilihan kembali BERLANGSUNG untuk unit itu saja | Tidak ada putaran 2 di Mode Resmi (buat pemilihan baru) |
| K28 | Nama pemilih di layar bilik | **Tidak ditampilkan** (petugas sudah verifikasi di meja; layar hanya "Surat suara Ketua RT 03") | Tampilkan nama depan untuk mencegah salah orang |
| K29 | Menutup pemilihan Mode Resmi | **Manual** oleh SA. Jam tutup terjadwal hanya pengingat di dashboard. Jika masih ada bilik aktif, SA melihat jumlahnya dan bisa menunggu atau menutup paksa | Tutup otomatis pada jam tertentu |
| K31 | Akun Petugas Meja saja (tanpa kelola pemilih) | **Tambahkan** izin terpisah: akun Admin RT bisa diberi "Kelola Pemilih" dan/atau "Petugas Meja" sehingga petugas meja relawan tidak bisa mengubah data pemilih | Ikut dokumen: semua Admin RT bisa kelola pemilih |
| K32 | Layar Live Partisipasi | Dibuka dari akun Admin RT/SA dalam mode layar penuh read-only (tanpa angka per kandidat) | Token tampilan khusus tanpa login |

### Hasil dan publikasi

| ID | Topik | Usulan | Alternatif |
|---|---|---|---|
| K19 | Hasil untuk Admin RT setelah DITUTUP | Admin RT melihat hasil surat suara RT-nya; untuk surat suara semua RT (RW/penjaringan) hanya **total**, tanpa sebaran per RT | Admin RT juga melihat sebaran RW di RT-nya / tidak melihat hasil RW |
| K20 | [Tampilkan Hasil] | Aktif saat status **VERIFIKASI**, sebelum berita acara ditandatangani (warga dan saksi melihat hasil, lalu tanda tangan). Bisa dipakai SA (semua unit) dan Admin RT (RT sendiri) | Hanya SA / hanya setelah berita acara disahkan |
| K21 | Penetapan terpilih dan publik penjaringan | Karena sistem tidak memutuskan, SA **menetapkan terpilih** per unit di VERIFIKASI (sistem menyarankan peringkat 1, SA konfirmasi atau memilih "Belum ditetapkan"). Penjaringan: halaman publik menampilkan daftar **nama yang lolos** (ditetapkan panitia), tanpa angka | Penjaringan tidak ditampilkan di publik |

### Peran, notifikasi, keamanan

| ID | Topik | Usulan | Alternatif |
|---|---|---|---|
| K23 | Kanal notifikasi | **Email + notifikasi di panel** pada Fase 1. WhatsApp butuh layanan gateway berbayar, usulan Fase 3. Ketua Panitia = alamat email penerima (bukan akun) | WhatsApp di Fase 1 (sebutkan gateway) |
| K24 | Peran Mode Dadakan | Dua peran per pemilihan: **Panitia Dadakan** (buka/tutup/perpanjang gelombang, pulihkan hak pilih, buka kunci PIN, rekonsiliasi, lihat sudah/belum; 2FA wajib) dan **Petugas Pintu** (hanya tambah peserta dan melihat PIN sekali; 2FA wajib) | SA saja yang menjadi panitia |
| K25 | Mekanisme pembatalan Mode Dadakan | Opsi A (Bagian 9.2): tautan HMAC sementara, dihapus beserta kuncinya saat pemilihan DITUTUP | Opsi B / usulan lain |
| K26 | Retensi data (UU PDP) | (1) Keterkaitan pemilih–pilihan Mode Resmi dihapus otomatis **30 hari setelah PUBLISHED** (masa sengketa), bisa diperpanjang SA dengan alasan; setelah itu Detail Suara tidak tersedia. (2) Snapshot hak pilih dan status sudah/belum disimpan **1 tahun**, lalu dianonimkan. (3) Data pemilih master dipertahankan selama masih dipakai; NIK tidak disimpan penuh. (4) Audit log **2 tahun**. (5) Backup dirotasi **30 hari**, sehingga data yang dihapus hilang dari backup paling lambat 30 hari kemudian | Angka lain |
| K27 | Backup dan restore | Backup otomatis **tiap 6 jam** di hari biasa, **tiap 15 menit** saat ada pemilihan BERLANGSUNG; simpan 30 hari. Restore: SA + re-auth + ketik konfirmasi + backup otomatis sebelum restore + notifikasi SA lain; ditolak saat BERLANGSUNG. Tujuan off-server ditentukan saat deployment | Restore butuh persetujuan 2 SA |
| K33 | Urutan fase: penjaringan (Mode Dadakan) berlangsung **sebelum** hari H RT + RW (Mode Resmi) | Mode Dadakan harus siap sebelum tanggal penjaringan. Usulan urutan pengerjaan bergantung tanggal kedua acara (perlu jadwal dari pemilik) | Urutan fase tetap (penjaringan memakai Mode Resmi) |
| K34 | Sumber daftar hadir Mode Dadakan untuk penjaringan | Petugas Pintu **memilih dari data warga terdaftar** (cari nama), dengan jalur tambah manual bertanda khusus, agar non-warga tidak ikut. Untuk voting dadakan umum tetap boleh input bebas | Selalu input bebas |
| K35 | Batas jumlah calon | Pengaturan per surat suara: RT maks. 5, RW 3. Sistem **menolak** kandidat ke-6 di satu RT. Untuk RW, sistem **memperingatkan** (bukan menolak) jika jumlah calon ≠ 3 saat DRAFT → READY | Batas keras untuk keduanya / tanpa batas di sistem |
| K36 | Kandidat RW dari hasil penjaringan | Tombol "Salin kandidat dari pemilihan lain" agar data dan foto 3 calon terpilih tidak diinput ulang; panitia yang memilih nama mana yang disalin | Input manual |
| K30 | Audit log untuk Admin RT | Admin RT **tidak** membuka audit log; cukup "Riwayat TPS" (kejadian perangkat dan izin di RT-nya, tanpa data akun lain yang sensitif) | Admin RT melihat audit log RT-nya |

---

## 16. Langkah berikutnya

Setelah Anda menyetujui dokumen ini (beserta jawaban K01-K32), Tahap 2 berisi:

1. ERD konseptual: pemilihan, surat suara, cakupan, putaran, gelombang, pemilih, snapshot, kandidat, slot/perangkat/token, izin, suara, koreksi, kehadiran Dadakan, tautan sementara, berita acara, audit, backup.
2. Wireframe: Layar Bilik (menunggu, kandidat, review, selesai), alur HP Dadakan, Meja Izin, Perangkat Terhubung, Dashboard Admin RT, Dashboard Super Admin, Layar Live Partisipasi, Layar Hasil, Halaman Publik.
3. Design tokens dan 2-3 alternatif kartu kandidat. Untuk ini saya membutuhkan **screenshot referensi gaya visual** yang Anda sebut di bagian 11A.
