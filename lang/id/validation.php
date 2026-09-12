<?php

/*
 * Pesan validasi Bahasa Indonesia.
 *
 * DIBUAT SETELAH SEORANG PETUGAS MEMBACA "The obat field is required."
 * APP_LOCALE sudah bernilai `id` sejak awal, tapi tidak ada satu pun
 * berkas terjemahan di proyek ini — jadi Laravel diam-diam jatuh ke
 * bahasa Inggris. Tidak ada galat, tidak ada uji merah: hanya seluruh
 * pesan kesalahan sistem yang muncul dalam bahasa yang bukan bahasa
 * penggunanya.
 *
 * Yang diterjemahkan di sini aturan yang benar-benar dipakai formulir
 * sistem ini, bukan seluruh daftar bawaan Laravel. Daftar yang disalin
 * utuh lalu tidak pernah dibaca ulang akan menua tanpa ada yang tahu;
 * yang belum diterjemahkan tetap muncul dalam bahasa Inggris — jelek,
 * tapi terlihat, dan yang terlihat bisa diperbaiki.
 */

return [
    'accepted' => ':attribute wajib disetujui.',
    'after' => ':attribute harus setelah :date.',
    'after_or_equal' => ':attribute harus sama dengan atau setelah :date.',
    'array' => ':attribute harus berupa daftar.',
    'before' => ':attribute harus sebelum :date.',
    'before_or_equal' => ':attribute harus sama dengan atau sebelum :date.',
    'boolean' => ':attribute harus bernilai ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'date' => ':attribute bukan tanggal yang sah.',
    'date_format' => ':attribute tidak sesuai format :format.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus terdiri dari :digits angka.',
    'digits_between' => ':attribute harus terdiri dari :min sampai :max angka.',
    'email' => ':attribute harus berupa alamat surel yang sah.',
    'exists' => ':attribute yang dipilih tidak ditemukan.',
    'file' => ':attribute harus berupa berkas.',
    'filled' => ':attribute wajib diisi.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak sah.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'json' => ':attribute harus berupa teks JSON yang sah.',
    'max' => [
        'array' => ':attribute tidak boleh lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobita.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => ':attribute harus berjenis berkas: :values.',
    'mimetypes' => ':attribute harus berjenis berkas: :values.',
    'min' => [
        'array' => ':attribute harus berisi sekurang-kurangnya :min item.',
        'file' => ':attribute harus sekurang-kurangnya :min kilobita.',
        'numeric' => ':attribute harus sekurang-kurangnya :min.',
        'string' => ':attribute harus sekurang-kurangnya :min karakter.',
    ],
    'not_in' => ':attribute yang dipilih tidak sah.',
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute harus ada.',
    'prohibited' => ':attribute tidak boleh diisi.',
    'regex' => 'Format :attribute tidak sesuai.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other bernilai :value.',
    'required_unless' => ':attribute wajib diisi kecuali :other bernilai :values.',
    'required_with' => ':attribute wajib diisi bila ada :values.',
    'required_without' => ':attribute wajib diisi bila tidak ada :values.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus berisi :size item.',
        'file' => ':attribute harus berukuran :size kilobita.',
        'numeric' => ':attribute harus bernilai :size.',
        'string' => ':attribute harus :size karakter.',
    ],
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah dipakai.',
    'uploaded' => ':attribute gagal diunggah.',
    'url' => 'Format :attribute tidak sah.',

    /*
     * Nama kolom yang berlaku di SELURUH sistem. Yang khas satu layar
     * tetap ditulis di controllernya lewat argumen ketiga validate() —
     * daftar terpusat yang menampung tiap kolom tiap formulir akan
     * tumbuh jadi tempat yang tidak ada yang berani menyentuhnya.
     */
    'attributes' => [
        'alasan' => 'alasan',
        'catatan' => 'catatan',
        'email' => 'surel',
        'jumlah' => 'jumlah',
        'keterangan' => 'keterangan',
        'name' => 'nama',
        'nama' => 'nama',
        'password' => 'kata sandi',
        'tanggal' => 'tanggal',
        'username' => 'nama pengguna',
    ],

    'custom' => [],
];
