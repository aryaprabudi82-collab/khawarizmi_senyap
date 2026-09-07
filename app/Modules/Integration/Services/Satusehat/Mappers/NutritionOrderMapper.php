<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR NutritionOrder dari pesanan diet rawat inap
 * (satu_sehat_kirim_diet).
 *
 * NUTRITIONORDER, BUKAN NUTRITIONINTAKE — dan bedanya bukan soal selera
 * penamaan. Yang kita catat adalah diet yang DIPESANKAN dokter untuk
 * pasien, bukan makanan yang benar-benar dimakannya. Mengirimkannya
 * sebagai asupan berarti menyatakan pasien telah mengonsumsi sesuatu yang
 * tidak pernah kita amati, dan itu masuk ke riwayat gizi pasien secara
 * nasional. (NutritionIntake juga baru ada di FHIR R5, sedangkan SATUSEHAT
 * memakai R4.)
 *
 * DIET YANG DIHENTIKAN TETAP DIKIRIM, sebagai 'revoked'. Penghentian diet
 * adalah keputusan klinis — pasien puasa menjelang operasi, atau dietnya
 * diganti — dan menghilangkannya dari laporan membuat riwayat gizi tampak
 * berlanjut padahal sudah dihentikan. Pelajaran yang sama pernah muncul
 * pada laporan gizi domain J item E.
 */
class NutritionOrderMapper
{
    /** Label terbaca untuk kosakata diet kita sendiri. */
    private const LABEL = [
        'biasa' => 'Diet Biasa',
        'lunak' => 'Diet Lunak',
        'cair' => 'Diet Cair',
        'bubur' => 'Diet Bubur',
        'diabetes' => 'Diet Diabetes Melitus',
        'rendah-garam' => 'Diet Rendah Garam',
        'rendah-lemak' => 'Diet Rendah Lemak',
        'tinggi-protein' => 'Diet Tinggi Protein',
        'bebas-gluten' => 'Diet Bebas Gluten',
        'lainnya' => 'Diet Lainnya',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(stdClass $diet, string $patientId, string $encounterId, ?string $practitionerId = null): array
    {
        $resource = [
            'resourceType' => 'NutritionOrder',
            'status' => $this->status($diet),
            'intent' => 'order',
            'patient' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'dateTime' => Carbon::parse($diet->start_date)->toIso8601String(),
            'oralDiet' => [
                'type' => [[
                    // Jenis diet kita memang kosakata tertutup, tapi kosakata
                    // MILIK KITA — bukan terminologi yang dikenal fasilitas
                    // lain. Jadi ia dikirim sebagai kode lokal yang jujur
                    // menyebut sistemnya sendiri, ditemani label terbaca.
                    // Memasangkannya ke SNOMED tanpa pemetaan resmi akan
                    // menyatakan diet yang berbeda dari yang dipesan dokter.
                    'coding' => [[
                        'system' => 'http://sys-ids.kemkes.go.id/local/diet',
                        'code' => $diet->diet_type,
                        'display' => self::LABEL[$diet->diet_type] ?? $diet->diet_type,
                    ]],
                    'text' => self::LABEL[$diet->diet_type] ?? $diet->diet_type,
                ]],
            ],
        ];

        if ($practitionerId !== null) {
            $resource['orderer'] = [
                'reference' => "Practitioner/{$practitionerId}",
                'display' => $diet->dpjp_name ?? $diet->ordered_by_name,
            ];
        }

        if (! empty($diet->end_date)) {
            $resource['oralDiet']['schedule'] = [[
                'repeat' => [
                    'boundsPeriod' => [
                        'start' => Carbon::parse($diet->start_date)->toIso8601String(),
                        'end' => Carbon::parse($diet->end_date)->toIso8601String(),
                    ],
                ],
            ]];
        }

        return $resource;
    }

    /** Diet yang dihentikan dilaporkan 'revoked' — lihat catatan kelas. */
    private function status(stdClass $diet): string
    {
        return match ($diet->status) {
            'dihentikan' => 'revoked',
            default => 'active',
        };
    }
}
