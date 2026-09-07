<?php

namespace App\Modules\Integration\Services;

/**
 * Daftar sistem luar yang diintegrasikan, berikut kolom yang dibutuhkan
 * masing-masing.
 *
 * INI SATU-SATUNYA TEMPAT yang tahu sistem apa saja yang ada dan apa yang
 * diperlukan tiap sistem. Formulir pengisian kredensial dirender dari
 * sini, kesiapan dihitung dari sini, dan pemilihan adapter asli atau palsu
 * diputuskan dari sini. Menambah sistem baru cukup menambah satu entri —
 * tanpa migrasi, tanpa mengubah formulir, tanpa menyentuh provider.
 *
 * KOLOM RAHASIA DITANDAI TERANG. Yang bertanda secret tidak pernah
 * dikembalikan utuh ke layar setelah tersimpan; yang tampil cuma penanda
 * bahwa ia sudah terisi. Kredensial yang bisa dibaca ulang dari layar
 * adalah kredensial yang bisa disalin siapa pun yang lewat.
 *
 * KOLOM WAJIB MENENTUKAN KESIAPAN. Sistem dianggap siap hanya kalau
 * SELURUH kolom wajibnya terisi — sistem yang setengah terisi akan gagal
 * pada panggilan pertama dengan pesan yang tidak masuk akal, dan itu jauh
 * lebih membingungkan daripada sistem yang jelas-jelas belum disetel.
 */
class IntegrationRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     doc: string,
     *     fields: array<string, array{label: string, secret: bool, required: bool, hint: string}>
     * }>
     */
    public static function systems(): array
    {
        return [
            'bpjs' => [
                'label' => 'BPJS VClaim',
                'description' => 'Eligibilitas peserta, SEP, rujukan, surat kontrol, dan monitoring klaim. '
                    . 'Satu kredensial dipakai bersama seluruh layanan VClaim, termasuk Antrean Mobile JKN.',
                'doc' => 'Diterbitkan BPJS Kesehatan untuk faskes yang sudah terdaftar, lewat Kantor Cabang.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL VClaim', 'secret' => false, 'required' => true,
                        'hint' => 'Alamat layanan VClaim; BEDA antara sandbox dan produksi.'],
                    'cons_id' => ['label' => 'Consumer ID', 'secret' => false, 'required' => true,
                        'hint' => 'X-cons-id pada header permintaan.'],
                    'secret_key' => ['label' => 'Consumer Secret', 'secret' => true, 'required' => true,
                        'hint' => 'Dipakai menandatangani permintaan dan mendekripsi jawabannya.'],
                    'user_key' => ['label' => 'User Key', 'secret' => true, 'required' => true,
                        'hint' => 'Berbeda per layanan BPJS; pastikan memakai user key VClaim.'],
                    'ppk_code' => ['label' => 'Kode PPK Faskes', 'secret' => false, 'required' => true,
                        'hint' => 'Kode faskes RSP UI di BPJS, dipakai Aplicares dan iCare.'],
                ],
            ],

            'satusehat' => [
                'label' => 'SATUSEHAT',
                'description' => 'Platform pertukaran data kesehatan nasional Kemenkes, berbasis FHIR R4. '
                    . 'Mengirim Patient, Encounter, Condition, Observation, Procedure, dan AllergyIntolerance.',
                'doc' => 'Didaftarkan lewat portal SATUSEHAT Kemenkes; Organization ID diberikan saat faskes terdaftar.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL FHIR', 'secret' => false, 'required' => true,
                        'hint' => 'Alamat endpoint FHIR; BEDA antara staging dan produksi.'],
                    'auth_url' => ['label' => 'URL Autentikasi', 'secret' => false, 'required' => true,
                        'hint' => 'Endpoint penukaran token OAuth2.'],
                    'client_id' => ['label' => 'Client ID', 'secret' => false, 'required' => true, 'hint' => ''],
                    'client_secret' => ['label' => 'Client Secret', 'secret' => true, 'required' => true, 'hint' => ''],
                    'organization_id' => ['label' => 'Organization ID', 'secret' => false, 'required' => true,
                        'hint' => 'ID Organization SATUSEHAT milik RSP UI; tetap sejak registrasi, bukan dibuat lewat API.'],
                ],
            ],

            'eklaim' => [
                'label' => 'E-Klaim INA-CBG',
                'description' => 'Grouper INA-CBG Kemenkes. Menerima kode CBG dan tarifnya — '
                    . 'sistem ini TIDAK PERNAH menghitung kode CBG sendiri.',
                'doc' => 'Aplikasi E-Klaim dipasang terpisah oleh Kemenkes; alamat dan kunci diberikan saat instalasi.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL E-Klaim', 'secret' => false, 'required' => true,
                        'hint' => 'Alamat instalasi E-Klaim, biasanya di jaringan rumah sakit sendiri.'],
                    'key' => ['label' => 'Kunci E-Klaim', 'secret' => true, 'required' => true,
                        'hint' => 'Kunci enkripsi E-Klaim; skemanya BERBEDA dari VClaim.'],
                    'hospital_code' => ['label' => 'Kode Rumah Sakit', 'secret' => false, 'required' => true, 'hint' => ''],
                ],
            ],

            'siranap' => [
                'label' => 'SIRANAP Kemenkes',
                'description' => 'Pelaporan ketersediaan tempat tidur ke Kemenkes. '
                    . 'Terpisah dari Aplicares BPJS meski isinya serupa.',
                'doc' => 'Akun SIRANAP diberikan Dinas Kesehatan / Kemenkes.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL SIRANAP', 'secret' => false, 'required' => true, 'hint' => ''],
                    'token' => ['label' => 'Token', 'secret' => true, 'required' => true, 'hint' => ''],
                    'hospital_code' => ['label' => 'Kode RS', 'secret' => false, 'required' => true, 'hint' => ''],
                ],
            ],

            'sisrute' => [
                'label' => 'Sisrute Kemenkes',
                'description' => 'Sistem Rujukan Terintegrasi: mengajukan rujukan keluar dan menjawab '
                    . 'rujukan masuk dari rumah sakit lain. Berbeda dari rujukan BPJS yang bersifat '
                    . 'administratif, Sisrute bersifat klinis dan dua arah.',
                'doc' => 'Akun Sisrute diberikan Kementerian Kesehatan lewat Dinas Kesehatan provinsi.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL Sisrute', 'secret' => false, 'required' => true, 'hint' => ''],
                    'username' => ['label' => 'Username', 'secret' => false, 'required' => true, 'hint' => ''],
                    'password' => ['label' => 'Password', 'secret' => true, 'required' => true, 'hint' => ''],
                    'facility_code' => [
                        'label' => 'Kode Faskes',
                        'secret' => false,
                        'required' => true,
                        'hint' => 'Menentukan rujukan masuk mana yang ditujukan ke rumah sakit ini.',
                    ],
                ],
            ],

            'inhealth' => [
                'label' => 'Mandiri Inhealth',
                'description' => 'Eligibilitas dan referensi peserta Inhealth.',
                'doc' => 'Diterbitkan Mandiri Inhealth berdasarkan perjanjian kerja sama.',
                'fields' => [
                    'base_url' => ['label' => 'Base URL', 'secret' => false, 'required' => true, 'hint' => ''],
                    'username' => ['label' => 'Username', 'secret' => false, 'required' => true, 'hint' => ''],
                    'password' => ['label' => 'Password', 'secret' => true, 'required' => true, 'hint' => ''],
                    'provider_code' => ['label' => 'Kode Provider', 'secret' => false, 'required' => true, 'hint' => ''],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::systems());
    }

    public static function has(string $system): bool
    {
        return isset(self::systems()[$system]);
    }

    /**
     * @return array{label: string, description: string, doc: string, fields: array<string, array<string, mixed>>}
     *
     * @throws IntegrationException
     */
    public static function system(string $system): array
    {
        return self::systems()[$system]
            ?? throw new IntegrationException("Sistem integrasi '{$system}' tidak dikenal.");
    }

    /** @return array<int, string> */
    public static function requiredFields(string $system): array
    {
        return array_keys(array_filter(
            self::system($system)['fields'],
            fn ($f) => $f['required']
        ));
    }

    public static function isSecret(string $system, string $field): bool
    {
        return (bool) (self::system($system)['fields'][$field]['secret'] ?? false);
    }

    public static function hasField(string $system, string $field): bool
    {
        return isset(self::system($system)['fields'][$field]);
    }

    /**
     * Nama konfigurasi lama di config/services.php, dipakai sebagai
     * cadangan supaya pemasangan yang sudah terlanjur memakai .env tidak
     * mendadak berhenti bekerja saat rumah ini dipasang.
     */
    public static function legacyConfigKey(string $system, string $field): ?string
    {
        $peta = [
            'bpjs' => 'services.bpjs.',
            'satusehat' => 'services.satusehat.',
        ];

        return isset($peta[$system]) ? $peta[$system] . $field : null;
    }
}
