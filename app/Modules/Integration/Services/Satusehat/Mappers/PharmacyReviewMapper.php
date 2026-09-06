<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR QuestionnaireResponse untuk TELAAH FARMASI
 * (satu_sehat_kirim_questionresponse_telaah_farmasi).
 *
 * Telaah resep adalah pemeriksaan apoteker terhadap resep dokter sebelum
 * obat diserahkan: interaksi antarobat, duplikasi terapi, kesesuaian dosis,
 * alergi. Kemenkes memodelkannya sebagai QuestionnaireResponse — jawaban
 * atas daftar pertanyaan telaah, bukan sebagai catatan bebas.
 *
 * TEMUAN DIKIRIM APA ADANYA, TERMASUK KETIKA KOSONG. Telaah yang tidak
 * menemukan apa-apa dan telaah yang belum dikerjakan adalah dua pernyataan
 * yang sama sekali berbeda: yang pertama berarti apoteker sudah memeriksa
 * dan menyatakan aman, yang kedua berarti belum ada yang memeriksa.
 * Mengirim keduanya sebagai "tidak ada temuan" menghapus perbedaan itu,
 * jadi resource ini hanya disusun untuk telaah yang benar-benar terjadi.
 *
 * TELAAH YANG MENOLAK RESEP TETAP DIKIRIM. Justru itu telaah yang paling
 * berarti secara klinis — apoteker menemukan sesuatu yang membuat resep
 * tidak boleh diserahkan, dan fasilitas lain berhak tahu alasannya.
 */
class PharmacyReviewMapper
{
    /**
     * @return array<string, mixed>
     */
    public function build(stdClass $telaah, string $patientId, string $encounterId): array
    {
        $resource = [
            'resourceType' => 'QuestionnaireResponse',
            'identifier' => [
                'system' => 'http://sys-ids.kemkes.go.id/pharmacy-review',
                'use' => 'official',
                'value' => $telaah->prescription_number . '-telaah-' . $telaah->review_id,
            ],
            'status' => 'completed',
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'authored' => Carbon::parse($telaah->reviewed_at)->toIso8601String(),
            'author' => ['display' => $telaah->reviewer_name],
            'item' => $this->butir($telaah),
        ];

        return $resource;
    }

    /**
     * Butir jawaban telaah.
     *
     * @return array<int, array<string, mixed>>
     */
    private function butir(stdClass $telaah): array
    {
        $item = [[
            'linkId' => 'hasil-telaah',
            'text' => 'Hasil telaah resep',
            'answer' => [['valueString' => $telaah->outcome === 'disetujui' ? 'Disetujui' : 'Ditolak']],
        ]];

        $temuan = $this->temuan($telaah->findings);

        // Kosong berarti "diperiksa, tidak ada temuan" — bukan "belum
        // diperiksa", karena resource ini hanya dibuat untuk telaah yang
        // sudah terjadi. Perbedaannya dinyatakan, bukan disamarkan.
        $item[] = [
            'linkId' => 'temuan',
            'text' => 'Temuan telaah',
            'answer' => $temuan === []
                ? [['valueString' => 'Tidak ada temuan']]
                : array_map(fn (string $t) => ['valueString' => $t], $temuan),
        ];

        if (! empty($telaah->pharmacist_note)) {
            $item[] = [
                'linkId' => 'catatan-apoteker',
                'text' => 'Catatan apoteker',
                'answer' => [['valueString' => $telaah->pharmacist_note]],
            ];
        }

        return $item;
    }

    /**
     * Temuan disimpan sebagai JSON di pharmacy.prescription_reviews dan
     * bentuknya bisa berupa daftar teks maupun daftar objek; keduanya
     * diterima, karena yang salah bentuk lebih baik ikut terkirim sebagai
     * teks daripada hilang diam-diam.
     *
     * @return array<int, string>
     */
    private function temuan(mixed $findings): array
    {
        if (is_string($findings)) {
            $findings = json_decode($findings, true);
        }

        if (! is_array($findings)) {
            return [];
        }

        $hasil = [];

        foreach ($findings as $kunci => $baris) {
            if (is_string($baris)) {
                $hasil[] = $baris;

                continue;
            }

            if (is_array($baris)) {
                $teks = $baris['message'] ?? $baris['description'] ?? $baris['text'] ?? null;
                $hasil[] = is_string($teks) ? $teks : (is_string($kunci) ? $kunci : json_encode($baris));
            }
        }

        return array_values(array_filter($hasil, fn ($t) => is_string($t) && trim($t) !== ''));
    }
}
