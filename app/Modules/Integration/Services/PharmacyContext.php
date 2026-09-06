<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca data resep milik konteks pharmacy, lewat kontrak yang
 * diterbitkannya: pharmacy.v_prescription_detail dan v_prescription_review.
 *
 * RESEP YANG BELUM DISERAHKAN TETAP BOLEH DIBACA, berbeda dari hasil
 * penunjang yang wajib terverifikasi dulu. Alasannya berbeda pula:
 * MedicationRequest menyatakan obat DIRESEPKAN, dan itu sudah benar-benar
 * terjadi begitu dokter menuliskannya — yang menunggu penyerahan hanyalah
 * MedicationDispense, dan penyaringnya ada di mapper-nya sendiri.
 */
class PharmacyContext
{
    private const RINCIAN = 'pharmacy.v_prescription_detail';
    private const TELAAH = 'pharmacy.v_prescription_review';
    private const KATALOG = 'pharmacy.v_drug_catalog';

    /** Baris-baris obat satu resep. */
    public function itemsFor(int $prescriptionId): Collection
    {
        return DB::table(self::RINCIAN)
            ->where('prescription_id', $prescriptionId)
            ->orderBy('item_id')
            ->get();
    }

    public function header(int $prescriptionId): ?stdClass
    {
        return DB::table(self::RINCIAN)
            ->where('prescription_id', $prescriptionId)
            ->orderBy('item_id')
            ->first();
    }

    /** Telaah apoteker atas satu resep, kalau memang sudah ditelaah. */
    public function reviewFor(int $prescriptionId): ?stdClass
    {
        return DB::table(self::TELAAH)
            ->where('prescription_id', $prescriptionId)
            ->orderByDesc('reviewed_at')
            ->first();
    }

    /**
     * Obat-obat yang dipakai satu resep, tanpa pengulangan.
     *
     * Satu Medication per OBAT, bukan per baris resep: obat yang sama
     * diresepkan pada sepuluh pasien tetap satu obat, dan menyusun sepuluh
     * Medication membuat platform nasional mengira ada sepuluh obat berbeda
     * dengan kode KFA yang sama.
     */
    public function drugsIn(int $prescriptionId): Collection
    {
        return DB::table(self::RINCIAN)
            ->where('prescription_id', $prescriptionId)
            ->whereNotNull('drug_id')
            ->select('drug_id', 'drug_code', 'drug_name', 'generic_name', 'kfa_code', 'form', 'strength')
            ->distinct()
            ->orderBy('drug_id')
            ->get();
    }

    /**
     * Seluruh obat aktif berikut kode KFA yang sudah tercatat di master.
     *
     * Dipakai MENYIAPKAN baris pemetaan, bukan sebagai sumber kode saat
     * mengirim — lihat catatan di MedicationMapper.
     */
    public function drugMaster(): Collection
    {
        return DB::table(self::KATALOG)
            ->where('is_active', true)
            ->select('drug_code', 'drug_name', 'kfa_code')
            ->orderBy('drug_code')
            ->get();
    }
}
