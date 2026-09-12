<?php

use App\Modules\Platform\Services\LandingResolver;
use Illuminate\Support\Facades\Route;

/*
| Rute lintas modul saja. Rute milik sebuah bounded context tinggal di
| app/Modules/{Konteks}/Routes/web.php dan dimuat oleh provider modulnya.
*/

Route::middleware(['web', 'auth'])->group(function () {
    /*
     * BERANDA MENGANTAR KE LAYAR YANG BOLEH DIBUKA PENGGUNANYA.
     *
     * DITEMUKAN SAAT MENELUSURI LAYAR KEUANGAN LEWAT BROWSER. Sebelumnya
     * beranda selalu mengalihkan ke pendaftaran pasien, siapa pun yang
     * masuk. Akibatnya SETIAP peran yang tidak berhak mendaftarkan pasien
     * — manajemen, apoteker, petugas dapur, pustakawan, petugas aset —
     * langsung menabrak 403 pada detik pertama sesudah memasukkan kata
     * sandinya yang BENAR, tanpa pernah melihat satu pun layar yang
     * menjadi haknya.
     *
     * Tidak ada uji yang menangkapnya: seluruh uji memanggil route()
     * tujuannya langsung, tidak satu pun pernah masuk lalu berhenti di
     * beranda seperti yang dilakukan manusia.
     */
    Route::get('/', fn () => LandingResolver::untuk(request()->user()))->name('beranda');
});
