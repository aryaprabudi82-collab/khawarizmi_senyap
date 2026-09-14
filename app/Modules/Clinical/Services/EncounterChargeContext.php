<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Biaya yang sudah tertagih pada satu kunjungan.
 *
 * MENGAPA ANGKANYA DITAMPILKAN DI LAYAR PEMERIKSAAN. Tindakan yang dicatat
 * dokter langsung menjadi baris tagihan, dan sampai sekarang tidak ada
 * satu pun tempat di layar RME yang memperlihatkan akibatnya. Dokter
 * mencatat lima tindakan tanpa pernah melihat berapa yang sudah menempel
 * pada pasien — dan yang pertama mengetahuinya adalah pasien sendiri, di
 * kasir.
 *
 * LAYAR INI TIDAK MENGHITUNG APA PUN SENDIRI. Nominalnya dibaca dari
 * `billing.v_charge_detail` yang sudah jadi sumbernya. Menjumlahkan ulang
 * dari tabel tindakan akan melahirkan angka kedua yang bisa berbeda dari
 * angka kasir — dan dua angka berbeda untuk hal yang sama jauh lebih buruk
 * daripada satu angka yang harus dicari di layar lain.
 *
 * NOL BERBEDA DARI BELUM ADA TAGIHAN. `total()` mengembalikan null bila
 * belum ada satu pun baris biaya, supaya layar bisa menyebut "belum ada
 * tagihan" alih-alih menampilkan "Rp 0" yang terbaca seolah pelayanannya
 * gratis.
 */
class EncounterChargeContext
{
    /**
     * Rincian baris biaya kunjungan ini, terbaru lebih dulu.
     *
     * @return Collection<int, object>
     */
    public function rincian(int $registrationId): Collection
    {
        return collect(DB::select(
            'SELECT charge_line_id, invoice_id, charged_at, source_type,
                    description, amount, care_type, payer_kind, unit_name
               FROM billing.v_charge_detail
              WHERE registration_id = ?
              ORDER BY charged_at DESC',
            [$registrationId]
        ));
    }

    /**
     * Total biaya kunjungan, atau null bila belum ada baris tagihan sama sekali.
     */
    public function total(int $registrationId): ?string
    {
        $baris = DB::selectOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
               FROM billing.v_charge_detail
              WHERE registration_id = ?',
            [$registrationId]
        );

        if ($baris === null || (int) $baris->n === 0) {
            return null;
        }

        return (string) $baris->total;
    }
}
