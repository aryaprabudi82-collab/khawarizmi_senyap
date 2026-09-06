<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item E: klasifikasi pasien ranap ikut dipaparkan.
 *
 * Kolom inpatient_classification ditambahkan saat audit domain identity
 * (keempat kode ber-tag context=identity ternyata belum punya kolom sama
 * sekali), tapi belum pernah dipaparkan pada kontrak — sehingga ketiga
 * kode laporan klasifikasi pasien ranap tidak bisa membacanya.
 *
 * Ditaruh di akhir daftar kolom karena CREATE OR REPLACE VIEW PostgreSQL
 * hanya mengizinkan penambahan di belakang; menyisipkan di tengah menuntut
 * DROP yang akan menjatuhkan view dependen.
 */
return new class extends Migration
{
    private const S = 'identity';

    public function up(): void
    {
        $this->rebuild(', p.inpatient_classification, p.ethnicity, p.language');
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $tambahan): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_patient_summary AS
            SELECT p.id, p.medical_record_number, p.nik, p.name, p.sex,
                   p.birth_place, p.birth_date, p.phone, p.email, p.address,
                   p.rt_rw, p.village_code, p.village_name, p.district_name,
                   p.city_name, p.province_name, p.postal_code,
                   p.special_precautions, p.special_precautions_color, p.updated_at' . $tambahan . '
              FROM ' . self::S . '.patients p');
    }
};
