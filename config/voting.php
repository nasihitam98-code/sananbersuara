<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rahasia server
    |--------------------------------------------------------------------------
    |
    | PIN_PEPPER dipakai untuk hash PIN Mode Dadakan (PIN 4 digit mudah dibongkar
    | jika hash bocor tanpa pepper). VOTE_LINK_KEY dipakai untuk tautan sementara
    | peserta-suara yang dihapus saat pemilihan ditutup. Keduanya wajib diisi
    | di .env produksi dan tidak boleh masuk repositori.
    |
    */

    'pin_pepper' => env('PIN_PEPPER'),

    'vote_link_key' => env('VOTE_LINK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Nilai default per pemilihan
    |--------------------------------------------------------------------------
    |
    | Bisa diubah per pemilihan lewat kolom settings (lihat Election::setting()).
    |
    */

    'defaults' => [
        'wave_minutes' => 5,
        'wave_minutes_many_candidates' => 10,
        'many_candidates_threshold' => 10,
        'late_grace_seconds' => 30,
        'pin_length' => 4,
        'pin_max_attempts' => 3,
        'search_min_chars' => 3,
        'search_max_results' => 5,
        'voter_session_minutes' => 15,
        'headcount' => null,

        // Mode Resmi (K05, K06)
        'max_booths_per_unit' => 3,
        'permit_expiry_minutes' => 5,
        'booth_idle_minutes' => 3,
        'booth_token_minutes' => 10,
        'desk_token_hours' => 24,

        // Retensi (K26): keterkaitan pemilih-pilihan dihapus N hari setelah dipublikasikan
        'linkage_retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Foto kandidat
    |--------------------------------------------------------------------------
    */

    'photo' => [
        'disk' => 'public',
        'directory' => 'kandidat',
        'max_kilobytes' => 2048,
        'sizes' => [
            'thumb' => 160,
            'card' => 480,
            'large' => 960,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling halaman pemilih
    |--------------------------------------------------------------------------
    */

    'poll_seconds' => 3,

    /*
    |--------------------------------------------------------------------------
    | Backup (K27)
    |--------------------------------------------------------------------------
    |
    | Zip terenkripsi AES-256 berisi dump database + foto calon. offsite_disk = nama disk
    | filesystem untuk salinan di luar server (diisi saat deployment, mis. sftp/s3).
    |
    */

    'backup' => [
        'disk' => 'local',
        'directory' => 'backups',
        'offsite_disk' => env('BACKUP_OFFSITE_DISK'),
        'password' => env('BACKUP_PASSWORD'),
        'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_PATH', 'mysql'),
        'live_interval_minutes' => 15,
        'idle_interval_minutes' => 360,
        'keep_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Penerima notifikasi internal tambahan (mis. Ketua Panitia), dipisah koma
    |--------------------------------------------------------------------------
    */

    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('VOTING_ALERT_EMAILS', ''))))),

];
