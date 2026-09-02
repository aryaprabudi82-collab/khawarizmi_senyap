<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Prescription;
use Illuminate\Support\Facades\DB;

/**
 * Penyaringan resep terhadap alergi pasien.
 *
 * Inilah alasan konteks clinical menerbitkan v_patient_allergy. Kopling
 * lintas konteksnya dikumpulkan di kelas ini saja.
 *
 * Catatan penting soal keterbatasannya: pencocokan dilakukan pada nama zat
 * dan nama generik. Ini menangkap kasus yang paling sering — alergi
 * "Amoksisilin" pada resep berisi amoksisilin — tetapi TIDAK menangkap
 * alergi lintas golongan, misalnya alergi penisilin terhadap sefalosporin.
 * Untuk itu dibutuhkan basis data interaksi obat, yang belum ada di sini.
 *
 * Karena itu hasil penyaringan ini adalah alat bantu apoteker, bukan
 * pengganti telaahnya.
 */
class AllergyScreening
{
    private const VIEW = 'clinical.v_patient_allergy';

    /**
     * @return list<array{substance: string, severity: string, reaction: ?string, drug_id: int, drug_name: string, matched_on: string}>
     */
    public function screen(Prescription $prescription): array
    {
        $alergi = DB::table(self::VIEW)
            ->where('patient_id', $prescription->patient_id)
            ->get();

        if ($alergi->isEmpty()) {
            return [];
        }

        $temuan = [];

        foreach ($prescription->items()->with('drug')->get() as $item) {
            $kandidat = array_filter([
                'nama obat' => $item->drug_name,
                'nama generik' => $item->drug?->generic_name,
            ]);

            foreach ($alergi as $a) {
                foreach ($kandidat as $sumber => $teks) {
                    if (! $this->matches($a->substance, (string) $teks)) {
                        continue;
                    }

                    $temuan[] = [
                        'substance' => $a->substance,
                        'severity' => $a->severity,
                        'reaction' => $a->reaction,
                        'drug_id' => $item->drug_id,
                        'drug_name' => $item->drug_name,
                        'matched_on' => $sumber,
                    ];

                    continue 3;
                }
            }
        }

        return $temuan;
    }

    /** Adakah temuan berderajat berat pada resep ini. */
    public function hasSevereFinding(array $findings): bool
    {
        foreach ($findings as $f) {
            if (($f['severity'] ?? null) === 'berat') {
                return true;
            }
        }

        return false;
    }

    /**
     * Pencocokan sederhana: satu nama mengandung yang lain.
     *
     * Sengaja longgar ke arah lebih banyak peringatan. Peringatan berlebih
     * merepotkan apoteker; peringatan yang terlewat membahayakan pasien.
     */
    private function matches(string $substance, string $drugText): bool
    {
        $substance = mb_strtolower(trim($substance));
        $drugText = mb_strtolower(trim($drugText));

        if ($substance === '' || $drugText === '') {
            return false;
        }

        return str_contains($drugText, $substance) || str_contains($substance, $drugText);
    }
}
