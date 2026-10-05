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

];
