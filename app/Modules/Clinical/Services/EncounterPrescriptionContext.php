<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resep satu kunjungan — untuk panel e-Resep di layar RME.
 *
 * MENGAPA DIBACA DI LAYAR PEMERIKSAAN. Dokter yang hendak menambah obat
 * perlu tahu apa yang sudah diresepkan pada kunjungan yang sama. Tanpa itu,
 * obat yang sama diresepkan dua kali oleh dua pemeriksa — dan yang
 * menemukannya apoteker saat telaah, kalau sempat.
 *
 * PENANDA NARKOTIKA/PSIKOTROPIKA/HIGH-ALERT IKUT DIBAWA, bukan karena
 * layar ini mengaturnya, melainkan karena tiga golongan itu menuntut
 * perhatian berbeda saat dibaca sekilas di tengah pemeriksaan.
 */
class EncounterPrescriptionContext
{
    /**
     * Baris obat satu kunjungan, terbaru lebih dulu.
     *
     * @return Collection<int, object>
     */
    public function baris(int $registrationId): Collection
    {
        return collect(DB::select(
            'SELECT item_id, prescription_id, prescription_number, kind, status,
                    prescriber_name, prescribed_at, reviewed_at, dispensed_at,
                    drug_id, drug_code, drug_name, drug_unit, generic_name,
                    form, strength, is_narcotic, is_psychotropic, is_high_alert,
                    prescribed_quantity, dispensed_quantity, dosage_instruction, note,
                    substituted_from_drug_name
               FROM pharmacy.v_prescription_detail
              WHERE registration_id = ?
              ORDER BY prescribed_at DESC, drug_name',
            [$registrationId]
        ));
    }

    /**
     * Jumlah baris obat pada kunjungan ini — untuk lencana angka.
     */
    public function jumlah(int $registrationId): int
    {
        return (int) DB::table('pharmacy.v_prescription_detail')
            ->where('registration_id', $registrationId)
            ->count();
    }
}
