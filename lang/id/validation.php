<?php

/*
|--------------------------------------------------------------------------
| Pesan validasi (bahasa Indonesia)
|--------------------------------------------------------------------------
| Aturan yang tidak ada di sini memakai pesan bawaan (bahasa Inggris).
*/

return [
    'accepted' => ':Attribute harus disetujui.',
    'array' => ':Attribute harus berupa daftar.',
    'between' => [
        'numeric' => ':Attribute harus antara :min dan :max.',
        'string' => ':Attribute harus antara :min dan :max karakter.',
        'file' => ':Attribute harus antara :min dan :max kilobyte.',
        'array' => ':Attribute harus berisi :min sampai :max item.',
    ],
    'boolean' => ':Attribute harus ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Password salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'different' => ':Attribute dan :other harus berbeda.',
    'digits' => ':Attribute harus :digits angka.',
    'digits_between' => ':Attribute harus :min sampai :max angka.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'exists' => ':Attribute yang dipilih tidak ditemukan.',
    'file' => ':Attribute harus berupa berkas.',
    'filled' => ':Attribute wajib diisi.',
    'image' => ':Attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak valid.',
    'integer' => ':Attribute harus berupa angka bulat.',
    'max' => [
        'numeric' => ':Attribute tidak boleh lebih dari :max.',
        'string' => ':Attribute tidak boleh lebih dari :max karakter.',
        'file' => ':Attribute tidak boleh lebih dari :max kilobyte.',
        'array' => ':Attribute tidak boleh lebih dari :max item.',
    ],
    'mimes' => ':Attribute harus berkas bertipe: :values.',
    'mimetypes' => ':Attribute harus berkas bertipe: :values.',
    'min' => [
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
        'file' => ':Attribute minimal :min kilobyte.',
        'array' => ':Attribute minimal berisi :min item.',
    ],
    'not_in' => 'Pilihan :attribute tidak valid.',
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus berisi minimal satu huruf.',
        'mixed' => ':Attribute harus berisi huruf besar dan huruf kecil.',
        'numbers' => ':Attribute harus berisi minimal satu angka.',
        'symbols' => ':Attribute harus berisi minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor di internet. Pilih password lain.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi.',
    'required_with' => ':Attribute wajib diisi.',
    'same' => ':Attribute dan :other harus sama.',
    'size' => [
        'numeric' => ':Attribute harus :size.',
        'string' => ':Attribute harus :size karakter.',
        'file' => ':Attribute harus :size kilobyte.',
        'array' => ':Attribute harus berisi :size item.',
    ],
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah dipakai.',
    'uploaded' => ':Attribute gagal diunggah.',
    'url' => ':Attribute harus berupa alamat web yang valid.',

    'attributes' => [
        'password' => 'password',
        'current_password' => 'password',
        'name' => 'nama',
        'email' => 'email',
    ],
];
