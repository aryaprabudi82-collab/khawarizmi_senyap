<?php

/*
|--------------------------------------------------------------------------
| Autentikasi Active Directory
|--------------------------------------------------------------------------
|
| MENGAPA TIDAK MEMAKAI PAKET LDAP.
|
| LdapRecord adalah pilihan yang wajar untuk banyak aplikasi, tapi ia menuntut
| guard baru, provider baru, kolom `guid`/`domain` pada tabel pengguna, dan
| mengganti jalur `Auth::attempt` yang di sini SUDAH diuji. Yang dibutuhkan
| RSP UI bentuknya tidak berubah-ubah — mengikat, mencari, memeriksa grup —
| dan itu ~12 baris `ldap_*` bawaan PHP. Menambah dependensi berikut siklus
| pembaruannya untuk kebutuhan sesempit itu tidak dibayar oleh manfaatnya.
|
| SELURUH NILAI DARI ENVIRONMENT, tanpa kecuali. Tidak ada host, base DN,
| apalagi kata sandi akun layanan yang boleh ada di berkas yang ter-commit.
|
*/

return [

    /*
     * Sakelar mati-hidup.
     *
     * Dimatikan, login kembali sepenuhnya ke kata sandi lokal tanpa perlu
     * menyunting satu baris kode pun. Dua keadaan membutuhkannya: AD sedang
     * dipelihara, dan pengujian — suite tidak boleh menghubungi server
     * sungguhan.
     */
    'enabled' => (bool) env('LDAP_ENABLED', false),

    /*
     * Mencatat kegagalan LDAP ke log aplikasi.
     *
     * Dihidupkan secara bawaan, dan itu disengaja: kegagalan LDAP dirancang
     * untuk JATUH DIAM-DIAM ke kata sandi lokal supaya AD mati tidak mengunci
     * seluruh rumah sakit. Tanpa log, "diam-diam" itu berarti tidak ada
     * seorang pun yang tahu AD sudah mati berhari-hari.
     */
    'logging' => (bool) env('LDAP_LOGGING', true),

    'connection' => env('LDAP_CONNECTION', 'default'),

    'host' => env('LDAP_HOST'),
    'port' => (int) env('LDAP_PORT', 389),

    /*
     * Akun layanan untuk pengikatan awal.
     *
     * Dibutuhkan karena pengguna memasukkan nama pengguna, bukan DN lengkap —
     * dan DN-nya baru bisa diketahui setelah dicari. Pola bakunya: ikat
     * sebagai akun layanan, cari penggunanya, lalu ikat ULANG memakai DN
     * pengguna beserta kata sandi yang ia ketik. Pengikatan kedua itulah yang
     * membuktikan kata sandinya benar; pencarian saja tidak membuktikan apa pun.
     */
    'username' => env('LDAP_USERNAME'),
    'password' => env('LDAP_PASSWORD'),

    'base_dn' => env('LDAP_BASE_DN'),

    'timeout' => (int) env('LDAP_TIMEOUT', 5),

    'ssl' => (bool) env('LDAP_SSL', false),
    'tls' => (bool) env('LDAP_TLS', false),

    /*
     * Grup yang boleh masuk.
     *
     * INI YANG MENENTUKAN SIAPA PUNYA AKSES, dan karena itu ditegakkan di
     * dalam filter pencarian, bukan diperiksa sesudahnya: pemeriksaan sesudah
     * pencarian gampang terlewat saat kode berubah, sementara filter yang
     * tidak cocok tidak mengembalikan baris sama sekali.
     *
     * Dikosongkan berarti SELURUH akun domain bisa masuk. Itu jarang yang
     * dimaksud, jadi keadaan itu dicatat ke log sebagai peringatan.
     */
    'allowed_group' => env('LDAP_ALLOWED_GROUP'),

    /*
     * Peran yang diberikan otomatis saat anggota grup masuk pertama kali.
     *
     * Di konfigurasi, bukan di kode, karena ini KEBIJAKAN: siapa mendapat
     * kewenangan apa adalah keputusan rumah sakit. Dikosongkan berarti akun
     * dibuat tanpa peran — pengguna tetap bisa masuk, tapi melihat halaman
     * "Belum ada layar yang bisa dibuka" sampai administrator menetapkan
     * perannya.
     */
    'default_role' => env('LDAP_DEFAULT_ROLE') ?: null,

    /*
     * Atribut yang dibaca dari direktori.
     *
     * Sengaja sesempit mungkin. Tiap atribut tambahan adalah data pribadi
     * pegawai yang ikut berpindah ke basis data ini, dan yang tidak dipakai
     * layar mana pun tidak perlu disalin.
     */
    'attributes' => [
        'samaccountname',
        'displayname',
        'mail',
        'distinguishedname',
        'useraccountcontrol',
    ],

];
