<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerWhereOnDate();
    }

    /**
     * `whereOnDate($kolom, $tanggal)` — menyaring satu hari kalender TANPA
     * mematikan indeks.
     *
     * MENGAPA INI ADA. `whereDate('prescribed_at', '2026-09-10')` menghasilkan
     * SQL `"prescribed_at"::date = ?`. Membungkus kolom dalam cast membuatnya
     * jadi EKSPRESI, dan indeks B-tree atas kolomnya tidak bisa dipakai —
     * PostgreSQL jatuh ke Seq Scan. Diukur langsung dengan EXPLAIN, bukan
     * ditebak:
     *
     *   whereDate pada timestamptz -> Seq Scan on prescriptions
     *   whereBetween pada kolom    -> Index Scan using ..._prescribed_at_index
     *
     * Yang perlu digarisbawahi, karena mudah salah dipukul rata: pada kolom
     * bertipe `date` — service_date, report_date, attendance_date —
     * `whereDate` TETAP memakai indeks, karena PostgreSQL membuang cast yang
     * tidak berguna. Jadi tidak semua `whereDate` bermasalah, dan mengganti
     * semuanya membabi buta cuma membuat kode lebih berisik tanpa manfaat.
     * Yang bermasalah hanya `whereDate` pada kolom timestamp.
     *
     * Pada 2.000 pasien/hari perbedaannya bukan soal kerapian. Layar antrean
     * apotek menyegarkan diri setiap dua puluh detik; sekali pindai penuh
     * atas jutaan baris resep, dikali tiga ribu kali sehari, adalah beban
     * yang tidak pernah berhenti.
     *
     * RENTANGNYA SETENGAH TERBUKA — `>= awal` dan `< awal+1hari` — bukan
     * BETWEEN. BETWEEN mencakup kedua ujungnya, jadi baris yang jatuh tepat
     * pada tengah malam akan terhitung di dua hari sekaligus. Pada rekap
     * pendapatan harian, itu berarti satu pembayaran dilaporkan dua kali.
     *
     * NAMANYA BUKAN `whereDay`: Laravel sudah punya method itu, artinya
     * berbeda (menyaring TANGGAL-DALAM-BULAN, bukan satu hari kalender), dan
     * makro tidak menimpa method yang sungguh ada — pemanggilnya akan diam-
     * diam mendapat perilaku yang salah.
     *
     * Ditaruh di tingkat aplikasi, bukan di salah satu konteks: ini plumbing
     * kueri yang dipakai seluruh konteks, sejenis dengan ModuleServiceProvider
     * — bukan pengetahuan domain milik siapa pun.
     */
    private function registerWhereOnDate(): void
    {
        $makro = function (string $column, mixed $date) {
            $awal = Carbon::parse($date instanceof Carbon ? $date->toDateString() : $date)->startOfDay();

            /** @var QueryBuilder|EloquentBuilder $this */
            return $this->where($column, '>=', $awal)
                ->where($column, '<', $awal->copy()->addDay());
        };

        QueryBuilder::macro('whereOnDate', $makro);
        EloquentBuilder::macro('whereOnDate', $makro);
    }
}
