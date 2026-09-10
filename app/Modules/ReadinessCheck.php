<?php

namespace App\Modules;

/**
 * Kontrak kesiapan operasional milik sebuah bounded context.
 *
 * MENGAPA BUKAN SATU KELAS YANG MEMBACA SEMUANYA. Versi pertama
 * `OperationalReadiness` mengueri tabel lima konteks lain langsung, dan uji
 * batas konteks menangkapnya — dengan benar. Kesalahannya bukan sekadar
 * teknis: pengetahuan tentang APA yang membuat sebuah konteks siap adalah
 * milik konteks itu sendiri.
 *
 * Kalau konteks kitchen suatu hari menambah syarat kesiapan baru — misalnya
 * siklus menu harus tersusun sebelum dapur bisa berproduksi — syarat itu
 * harus ditambahkan DI kitchen, bukan di sebuah kelas terpusat yang harus
 * diingat orang untuk ikut diperbarui. Daftar terpusat akan benar hari ini
 * dan diam-diam usang pada syarat berikutnya.
 *
 * Konteks yang punya syarat kesiapan cukup menaruh kelas bernama
 * `App\Modules\{Modul}\Services\{Modul}Readiness` yang mengimplementasikan
 * antarmuka ini; ModuleServiceProvider mendaftarkannya sendiri.
 */
interface ReadinessCheck
{
    /** Ada yang benar-benar tidak bisa dijalankan. */
    public const MENGHALANGI = 'menghalangi';

    /** Jalan, tapi ada keputusan yang belum diambil dan akibatnya bisa disebut. */
    public const PERINGATAN = 'peringatan';

    public const BERES = 'beres';

    /**
     * Butir kesiapan konteks ini.
     *
     * `akibat` WAJIB menyebut apa yang terjadi kalau dibiarkan — bukan
     * mengulang judulnya. Yang membaca laporan ini adalah orang yang harus
     * memutuskan mana dikerjakan lebih dulu, dan "belum diisi" tidak
     * membantunya memutuskan apa pun.
     *
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    public function readinessItems(): array;
}
