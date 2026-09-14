<?php

namespace App\Modules\Keuangan\MasterData\Infrastructure\Resolvers;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tarif parkir.
 *
 * SATU-SATUNYA TARIF YANG DIHITUNG DARI DURASI, dan justru karena itu ia
 * jadi pembuktian bahwa `TariffResolver` harus berupa kontrak, bukan satu
 * tabel tarif seragam. Dua basis:
 *
 *   'harian' — tarif tetap, berapa pun lamanya
 *   'jam'    — tarif dikali jumlah jam, dibulatkan KE ATAS, dengan
 *              `free_minutes` menit pertama tidak ditagih
 *
 * PEMBULATAN JAM KE ATAS, dan itu bukan kesewenangan: parkir 61 menit
 * memakai tempat selama dua jam sejauh yang bisa dipakai orang lain.
 * Membulatkan ke bawah berarti tiap kendaraan yang lewat semenit dari
 * jamnya memakai tempat gratis selama 59 menit.
 *
 * MENIT BEBAS DIPOTONG SEBELUM PEMBULATAN, bukan sesudah. Kalau sesudah,
 * pengantar yang berhenti 20 menit dengan 30 menit bebas tetap ditagih
 * satu jam — karena 20 menit sudah dibulatkan jadi 1 jam sebelum
 * pembebasannya dihitung.
 */
class ParkingTariffResolver implements TariffResolver
{
    public function konteks(): string
    {
        return 'parking';
    }

    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $baris = DB::table('parking.v_rate')
            ->where('rate_id', $item->source_id)
            ->where('is_active', true)
            ->first();

        if ($baris === null) {
            return null;
        }

        $tarif = Money::tagihan((string) $baris->fee);

        if ($baris->basis === 'harian') {
            return $tarif;
        }

        $menit = $konteksPenagihan['minutes'] ?? null;

        if ($menit === null) {
            /*
             * Tarif per jam TIDAK BISA dihitung tanpa durasinya, dan
             * menganggapnya satu jam adalah cara paling halus salah
             * menagih: kendaraan yang menginap dibayar seharga sejam, dan
             * selisihnya tidak pernah muncul sebagai galat.
             */
            return null;
        }

        $menitDitagih = max(0, (int) $menit - (int) $baris->free_minutes);

        if ($menitDitagih === 0) {
            return Money::nol();
        }

        $jam = intdiv($menitDitagih, 60) + ($menitDitagih % 60 > 0 ? 1 : 0);

        return $tarif->kali($jam);
    }

    public function belumTertaut(): iterable
    {
        $baris = DB::select("
            SELECT r.rate_id, r.code, r.name
              FROM parking.v_rate r
             WHERE r.is_active = true
               AND NOT EXISTS (
                   SELECT 1 FROM keuangan_master.charge_items c
                    WHERE c.source_context = 'parking'
                      AND c.source_id = r.rate_id
                      AND c.valid_until IS NULL
               )
             ORDER BY r.code
        ");

        foreach ($baris as $r) {
            yield [
                'source_id' => (int) $r->rate_id,
                'code' => $r->code,
                'name' => $r->name,
                'golongan' => ChargeItem::GOL_LAIN,
            ];
        }
    }
}
