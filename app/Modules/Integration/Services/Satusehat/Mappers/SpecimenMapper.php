<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;

/**
 * Menyusun resource FHIR Specimen — BAHAN yang diperiksa
 * (satu_sehat_kirim_specimen_lab).
 *
 * SPECIMEN HANYA DISUSUN KALAU BAHANNYA MEMANG ADA. Pemeriksaan
 * laboratorium mengambil sesuatu dari pasien: darah, urin, dahak, jaringan.
 * Foto rontgen tidak — ia bekerja dengan modalitas, bukan bahan. Menyusun
 * Specimen untuk foto toraks berarti melaporkan pengambilan bahan yang
 * tidak pernah terjadi pada pasien, ke platform nasional, dan tidak ada
 * satu pun catatan di sistem kita yang bisa membantahnya kelak.
 *
 * JENIS BAHAN DIKIRIM SEBAGAI TEKS BILA BELUM DIPETAKAN. Berbeda dari kode
 * pemeriksaan, jenis spesimen tidak menentukan arti hasilnya — 'darah vena'
 * yang terbaca manusia jauh lebih berguna daripada Specimen yang batal
 * dikirim, dan tidak menyesatkan siapa pun.
 */
class SpecimenMapper
{
    /** Kode SNOMED CT untuk jenis spesimen yang lazim dipakai. */
    private const SNOMED = [
        'darah' => ['119297000', 'Blood specimen'],
        'darah-vena' => ['122555007', 'Venous blood specimen'],
        'serum' => ['119364003', 'Serum specimen'],
        'plasma' => ['119361006', 'Plasma specimen'],
        'urin' => ['122575003', 'Urine specimen'],
        'feses' => ['119339001', 'Stool specimen'],
        'dahak' => ['119334006', 'Sputum specimen'],
        'sputum' => ['119334006', 'Sputum specimen'],
        'jaringan' => ['119376003', 'Tissue specimen'],
        'usap' => ['461911000124106', 'Swab specimen'],
        'swab' => ['461911000124106', 'Swab specimen'],
    ];

    /**
     * @param  array<int, string>  $serviceRequestIds  ServiceRequest yang bahan ini diambil untuknya
     * @return array<string, mixed>
     */
    public function build(
        string $specimenType,
        string $patientId,
        array $serviceRequestIds,
        ?string $collectedAt = null,
    ): array {
        $resource = [
            'resourceType' => 'Specimen',
            'status' => 'available',
            'subject' => ['reference' => "Patient/{$patientId}"],
        ];

        $kode = self::SNOMED[mb_strtolower(trim($specimenType))] ?? null;

        $resource['type'] = $kode === null
            // Belum dipetakan: dikirim sebagai teks, lihat catatan kelas.
            ? ['text' => $specimenType]
            : [
                'coding' => [[
                    'system' => 'http://snomed.info/sct',
                    'code' => $kode[0],
                    'display' => $kode[1],
                ]],
                'text' => $specimenType,
            ];

        if ($collectedAt !== null) {
            $resource['collection'] = [
                'collectedDateTime' => Carbon::parse($collectedAt)->toIso8601String(),
            ];
        }

        if ($serviceRequestIds !== []) {
            $resource['request'] = array_map(
                fn (string $id) => ['reference' => "ServiceRequest/{$id}"],
                array_values($serviceRequestIds)
            );
        }

        return $resource;
    }

    /** Apakah pemeriksaan ini memang mengambil bahan dari pasien. */
    public function applies(?string $specimenType): bool
    {
        return $specimenType !== null && trim($specimenType) !== '';
    }
}
