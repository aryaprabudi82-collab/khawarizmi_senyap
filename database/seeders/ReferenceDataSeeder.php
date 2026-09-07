<?php

namespace Database\Seeders;

use App\Modules\Catalog\Database\Seeders\FluidItemSeeder;
use App\Modules\Catalog\Database\Seeders\ObservationCatalogSeeder;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Data referensi minimum agar modul A bisa dijalankan dan didemokan.
 *
 * Isinya contoh, bukan data RSP UI yang sebenarnya. Penggantiannya nanti lewat
 * impor data master, bukan dengan mengubah seeder ini.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPayers();
        $this->seedUnits();
        $this->seedPractitioners();
        $this->seedRegistrationTariffs();
        $this->seedProcedureTariffs();
        $this->seedOperationTariffs();

        // Katalog observasi jadi data sejak domain M item D: 12 kode
        // catatan_observasi_* Khanza adalah panel berbeda dari pengukuran
        // yang sama, dan panel yang jadi kode berarti tiap unit baru
        // menuntut migrasi basis data.
        $this->call(ObservationCatalogSeeder::class);

        // Jenis cairan masuk/keluar (domain M item E): Khanza memasang
        // kolom per jenis di dua tabel yang isinya nyaris sama; di sini
        // jenis baru cukup baris baru.
        $this->call(FluidItemSeeder::class);

        $this->command?->info('Data referensi: penjamin, unit, praktisi, dan tarif registrasi, tindakan & operasi disiapkan.');
        $this->command?->warn('Tarif tindakan/operasi contoh, hanya untuk penjamin Umum — perlu ditinjau ulang bersama bagian keuangan sebelum dipakai melayani pasien.');
    }

    private function seedPayers(): void
    {
        $payers = [
            ['code' => 'UMUM', 'name' => 'Umum / Bayar Sendiri', 'kind' => 'umum'],
            ['code' => 'BPJS', 'name' => 'BPJS Kesehatan', 'kind' => 'bpjs'],
            ['code' => 'BPJS-KTG', 'name' => 'BPJS Ketenagakerjaan', 'kind' => 'bpjs'],
            ['code' => 'INHEALTH', 'name' => 'Mandiri Inhealth', 'kind' => 'asuransi'],
            ['code' => 'UI', 'name' => 'Universitas Indonesia', 'kind' => 'perusahaan', 'company_name' => 'Universitas Indonesia'],
        ];

        foreach ($payers as $payer) {
            Payer::query()->updateOrCreate(['code' => $payer['code']], $payer);
        }
    }

    private function seedUnits(): void
    {
        $units = [
            ['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'kind' => 'igd', 'daily_quota' => null],
            ['code' => 'POL-UMUM', 'name' => 'Poliklinik Umum', 'kind' => 'poliklinik', 'daily_quota' => 120],
            ['code' => 'POL-PD', 'name' => 'Poliklinik Penyakit Dalam', 'kind' => 'poliklinik', 'daily_quota' => 80],
            ['code' => 'POL-ANAK', 'name' => 'Poliklinik Anak', 'kind' => 'poliklinik', 'daily_quota' => 80],
            ['code' => 'POL-OBGYN', 'name' => 'Poliklinik Kebidanan dan Kandungan', 'kind' => 'poliklinik', 'daily_quota' => 60],
            ['code' => 'POL-BEDAH', 'name' => 'Poliklinik Bedah', 'kind' => 'poliklinik', 'daily_quota' => 60],
            ['code' => 'POL-GIGI', 'name' => 'Poliklinik Gigi dan Mulut', 'kind' => 'poliklinik', 'daily_quota' => 50],
            ['code' => 'POL-MATA', 'name' => 'Poliklinik Mata', 'kind' => 'poliklinik', 'daily_quota' => 50],
            ['code' => 'POL-SARAF', 'name' => 'Poliklinik Saraf', 'kind' => 'poliklinik', 'daily_quota' => 50],
            ['code' => 'LAB', 'name' => 'Laboratorium Patologi Klinik', 'kind' => 'penunjang-medis'],
            ['code' => 'RAD', 'name' => 'Radiologi', 'kind' => 'penunjang-medis'],
            ['code' => 'FARMASI', 'name' => 'Instalasi Farmasi', 'kind' => 'penunjang'],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(['code' => $unit['code']], $unit);
        }
    }

    private function seedPractitioners(): void
    {
        $practitioners = [
            ['code' => 'DR001', 'name' => 'Andi Wijaya', 'title' => 'dr.', 'specialty' => 'Dokter Umum', 'unit' => 'POL-UMUM'],
            ['code' => 'DR002', 'name' => 'Siti Rahmawati', 'title' => 'dr.', 'specialty' => 'Penyakit Dalam', 'unit' => 'POL-PD'],
            ['code' => 'DR003', 'name' => 'Bambang Sutrisno', 'title' => 'dr.', 'specialty' => 'Anak', 'unit' => 'POL-ANAK'],
            ['code' => 'DR004', 'name' => 'Ratna Kusuma', 'title' => 'dr.', 'specialty' => 'Kebidanan dan Kandungan', 'unit' => 'POL-OBGYN'],
            ['code' => 'DR005', 'name' => 'Hendra Gunawan', 'title' => 'dr.', 'specialty' => 'Bedah Umum', 'unit' => 'POL-BEDAH'],
            ['code' => 'DR006', 'name' => 'Maya Puspita', 'title' => 'drg.', 'specialty' => 'Gigi dan Mulut', 'unit' => 'POL-GIGI'],
        ];

        foreach ($practitioners as $data) {
            $practitioner = Practitioner::query()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'title' => $data['title'],
                    'specialty' => $data['specialty'],
                    'is_active' => true,
                    'active_from' => now()->startOfYear()->toDateString(),
                ]
            );

            $unit = Unit::query()->where('code', $data['unit'])->first();

            if ($unit !== null) {
                $practitioner->units()->syncWithoutDetaching([$unit->id => ['is_primary' => true]]);
            }
        }
    }

    private function seedRegistrationTariffs(): void
    {
        $service = Service::query()->updateOrCreate(
            ['code' => 'REG-RALAN'],
            ['name' => 'Registrasi Rawat Jalan', 'category' => 'registrasi', 'is_active' => true]
        );

        // Pasien lama umumnya lebih murah karena berkasnya sudah ada.
        $tariffs = [
            'UMUM' => ['amount' => 50000, 'amount_returning' => 35000],
            'BPJS' => ['amount' => 0, 'amount_returning' => 0],
            'BPJS-KTG' => ['amount' => 0, 'amount_returning' => 0],
            'INHEALTH' => ['amount' => 45000, 'amount_returning' => 30000],
            'UI' => ['amount' => 25000, 'amount_returning' => 15000],
        ];

        foreach ($tariffs as $payerCode => $amounts) {
            $payer = Payer::query()->where('code', $payerCode)->first();

            if ($payer === null) {
                continue;
            }

            Tariff::query()->updateOrCreate(
                [
                    'service_id' => $service->id,
                    'payer_id' => $payer->id,
                    'care_class' => '-',
                    'valid_until' => null,
                ],
                $amounts + ['valid_from' => now()->startOfYear()->toDateString()]
            );
        }
    }

    /**
     * tindakan_ralan — lihat catatan migrasi clinical.procedures. Contoh
     * tindakan rawat jalan umum, hanya tarif Umum yang diisi (bukan matriks
     * lengkap tiap penjamin seperti tarif registrasi) supaya fitur bisa
     * langsung didemokan tanpa berpura-pura sudah selesai ditinjau keuangan.
     */
    private function seedProcedureTariffs(): void
    {
        $umum = Payer::query()->where('code', 'UMUM')->first();

        if ($umum === null) {
            return;
        }

        $tindakan = [
            ['code' => 'TDK-GANTI-VERBAN', 'name' => 'Ganti Verban', 'amount' => 35000],
            ['code' => 'TDK-INJEKSI-IM', 'name' => 'Injeksi Intramuskular', 'amount' => 20000],
            ['code' => 'TDK-NEBULIZER', 'name' => 'Nebulizer', 'amount' => 50000],
            ['code' => 'TDK-JAHIT-LUKA', 'name' => 'Jahit Luka (s.d. 5 jahitan)', 'amount' => 150000],
            ['code' => 'TDK-EKG', 'name' => 'Pemeriksaan EKG', 'amount' => 75000],
            ['code' => 'TDK-ANGKAT-JAHITAN', 'name' => 'Angkat Jahitan', 'amount' => 25000],
        ];

        foreach ($tindakan as $t) {
            $service = Service::query()->updateOrCreate(
                ['code' => $t['code']],
                ['name' => $t['name'], 'category' => 'tindakan', 'is_active' => true]
            );

            Tariff::query()->updateOrCreate(
                [
                    'service_id' => $service->id,
                    'payer_id' => $umum->id,
                    'care_class' => '-',
                    'valid_until' => null,
                ],
                ['amount' => $t['amount'], 'amount_returning' => null, 'valid_from' => now()->startOfYear()->toDateString()]
            );
        }
    }

    /**
     * operasi — lihat catatan migrasi clinical.operations. Tarif lump-sum
     * per tindakan operasi, sama seperti seedProcedureTariffs(), belum
     * dipecah per peran tim bedah.
     */
    private function seedOperationTariffs(): void
    {
        $umum = Payer::query()->where('code', 'UMUM')->first();

        if ($umum === null) {
            return;
        }

        $operasi = [
            ['code' => 'OPR-APENDEKTOMI', 'name' => 'Apendektomi', 'amount' => 5000000],
            ['code' => 'OPR-SC', 'name' => 'Seksio Sesarea', 'amount' => 7500000],
            ['code' => 'OPR-HERNIOTOMI', 'name' => 'Herniotomi', 'amount' => 4500000],
            ['code' => 'OPR-KATARAK', 'name' => 'Ekstraksi Katarak', 'amount' => 3500000],
        ];

        foreach ($operasi as $o) {
            $service = Service::query()->updateOrCreate(
                ['code' => $o['code']],
                ['name' => $o['name'], 'category' => 'operasi', 'is_active' => true]
            );

            Tariff::query()->updateOrCreate(
                [
                    'service_id' => $service->id,
                    'payer_id' => $umum->id,
                    'care_class' => '-',
                    'valid_until' => null,
                ],
                ['amount' => $o['amount'], 'amount_returning' => null, 'valid_from' => now()->startOfYear()->toDateString()]
            );
        }
    }
}
