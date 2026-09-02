<?php

use Illuminate\Support\Facades\Route;

/*
| Rute lintas modul saja. Rute milik sebuah bounded context tinggal di
| app/Modules/{Konteks}/Routes/web.php dan dimuat oleh provider modulnya.
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/', fn () => redirect()->route('registrasi.index'))->name('beranda');
});
