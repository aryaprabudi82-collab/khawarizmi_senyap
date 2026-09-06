<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use stdClass;

/**
 * Menyusun resource FHIR Medication — OBATNYA sendiri, bukan pemberiannya
 * (satu_sehat_kirim_medication).
 *
 * Medication adalah pangkal rantai farmasi: MedicationRequest menunjuknya
 * sebagai obat yang diresepkan, MedicationDispense sebagai obat yang
 * diserahkan. Keduanya kehilangan artinya kalau obatnya sendiri tidak
 * dikenal platform nasional.
 *
 * KODE KFA WAJIB, DAN TIDAK PERNAH DITEBAK. Kamus Farmasi dan Alat
 * Kesehatan menentukan zat aktif, kekuatan, dan bentuk sediaannya sekaligus
 * — salah kode berarti melaporkan obat lain, dengan dosis lain, kepada
 * fasilitas yang kelak merawat pasien yang sama. Ini golongan kesalahan
 * yang bisa mencelakakan orang, bukan sekadar mengotori laporan.
 *
 * SUMBER KODENYA SATU: pemetaan 'obat' milik item D. Kolom kfa_code di
 * master obat sengaja TIDAK dibaca langsung sebagai sumber kedua — dua
 * sumber untuk satu hal berarti keduanya bisa berbeda, dan yang terkirim
 * justru yang salah. Kolom itu dipakai sebagai BAHAN AWAL pemetaan (lihat
 * MedicationSyncService::seedMappingsFromDrugMaster), bukan sebagai saingan.
 */
class MedicationMapper
{
    public function __construct(private readonly CodeMappingService $codes) {}

    /**
     * @return array<string, mixed>|null null kalau kode KFA-nya belum dipetakan
     */
    public function build(stdClass $obat, string $organizationId): ?array
    {
        $kode = $this->codes->resolve('obat', $obat->drug_code);

        if ($kode === null) {
            return null;
        }

        $resource = [
            'resourceType' => 'Medication',
            'meta' => [
                'profile' => ['https://fhir.kemkes.go.id/r4/StructureDefinition/Medication'],
            ],
            'identifier' => [[
                'system' => "http://sys-ids.kemkes.go.id/medication/{$organizationId}",
                'use' => 'official',
                'value' => $obat->drug_code,
            ]],
            'code' => [
                'coding' => [[
                    'system' => SatusehatCodeMapping::URI[$kode['system']] ?? null,
                    'code' => $kode['code'],
                    'display' => $kode['display'] ?? $obat->drug_name,
                ]],
            ],
            'status' => 'active',
            'manufacturer' => ['reference' => "Organization/{$organizationId}"],
        ];

        if (! empty($obat->form)) {
            $resource['form'] = ['text' => $obat->form];
        }

        // Kekuatan sediaan dikirim sebagai teks bila ada: "500 mg" adalah
        // pembeda yang menentukan antara dua obat bernama sama.
        if (! empty($obat->strength)) {
            $resource['ingredient'] = [[
                'itemCodeableConcept' => [
                    'text' => trim(($obat->generic_name ?: $obat->drug_name) . ' ' . $obat->strength),
                ],
                'isActive' => true,
            ]];
        }

        return $resource;
    }
}
