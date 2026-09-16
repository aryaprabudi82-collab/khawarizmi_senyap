<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use stdClass;

/**
 * Menyusun resource FHIR Patient dari identity.v_patient_summary.
 *
 * PERINGATAN: URL system/extension SATUSEHAT (NIK, kode wilayah Dukcapil,
 * dsb.) berubah mengikuti Implementation Guide resmi mereka. Nilai di bawah
 * mengikuti pola yang paling umum didokumentasikan SATUSEHAT per penulisan
 * ini, tapi WAJIB dicocokkan ulang terhadap IG resmi yang berlaku saat
 * kredensial faskes diterbitkan — sebelum itu tidak ada trafik nyata yang
 * lewat sini (lihat IntegrationServiceProvider).
 */
class PatientMapper
{
    private const SYSTEM_NIK = 'https://fhir.kemkes.go.id/id/nik';

    public function build(stdClass $patient): array
    {
        $resource = [
            'resourceType' => 'Patient',
            'meta' => ['profile' => ['https://fhir.kemkes.go.id/r4/StructureDefinition/Patient']],
            'identifier' => array_values(array_filter([
                filled($patient->nik) ? ['use' => 'official', 'system' => self::SYSTEM_NIK, 'value' => $patient->nik] : null,
            ])),
            'active' => true,
            'name' => [['use' => 'official', 'text' => $patient->name]],
            /*
             * TIGA NILAI, BUKAN DUA.
             *
             * Sebelumnya `$patient->sex === 'L' ? 'male' : 'female'` — dan sejak
             * jenis kelamin boleh NULL (data warisan HSN), ungkapan itu mengirim
             * 110.740 pasien ke SATUSEHAT sebagai PEREMPUAN. Bukan karena ada
             * yang memutuskan begitu, melainkan karena `else` menampung apa saja
             * yang bukan 'L'.
             *
             * FHIR R4 memang menyediakan 'unknown' untuk keadaan ini, dan itulah
             * yang jujur: kami tidak tahu, bukan kami menebak perempuan.
             */
            'gender' => match ($patient->sex) {
                'L' => 'male',
                'P' => 'female',
                default => 'unknown',
            },
            'birthDate' => $patient->birth_date,
        ];

        $telecom = array_values(array_filter([
            filled($patient->phone) ? ['system' => 'phone', 'value' => $patient->phone, 'use' => 'mobile'] : null,
            filled($patient->email) ? ['system' => 'email', 'value' => $patient->email] : null,
        ]));

        if ($telecom !== []) {
            $resource['telecom'] = $telecom;
        }

        if (filled($patient->address)) {
            $resource['address'] = [[
                'use' => 'home',
                'line' => [$patient->address],
                'city' => $patient->city_name,
                'district' => $patient->district_name,
                'state' => $patient->province_name,
                'postalCode' => $patient->postal_code,
                'country' => 'ID',
                // Kode wilayah Kemendagri (village_code) dan RT/RW dikirim lewat
                // extension khusus SATUSEHAT — URL persisnya perlu dicocokkan
                // terhadap IG resmi, lihat peringatan di docblock kelas.
                'extension' => array_values(array_filter([
                    filled($patient->village_code) ? [
                        'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/administrativeCode',
                        'valueCode' => $patient->village_code,
                    ] : null,
                    filled($patient->rt_rw) ? [
                        'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/rt-rw',
                        'valueString' => $patient->rt_rw,
                    ] : null,
                ])),
            ]];
        }

        return $resource;
    }
}
