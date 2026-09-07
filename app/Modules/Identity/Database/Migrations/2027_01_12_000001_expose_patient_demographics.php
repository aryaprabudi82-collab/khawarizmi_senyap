<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Demografi pasien ikut diterbitkan (domain O item A).
 *
 * Domain O Khanza berisi 113 grafik, dan sebagiannya mengelompokkan
 * kunjungan menurut pekerjaan, pendidikan, agama, dan status
 * perkawinan pasien — grafik_kunjungan_perpekerjaan,
 * _perpendidikan, _per_agama. Keempat kolomnya sudah ada di
 * identity.patients sejak lama tapi tidak pernah diterbitkan, jadi
 * konteks reporting tidak bisa mengelompokkan apa pun menurutnya.
 *
 * KOLOM BARU DITAMBAHKAN DI UJUNG, dan itu bukan selera melainkan
 * keharusan: CREATE OR REPLACE VIEW tidak bisa menyisipkan kolom di
 * tengah, dan gagal dengan pesan yang menunjuk kolom LAIN. Pelajaran
 * ini sudah tercatat sejak domain M item F dan terulang sekali lagi
 * setelahnya; urutannya di sini mengikuti view yang sudah ada persis.
 *
 * YANG TIDAK IKUT DITERBITKAN: nomor telepon, surel, dan alamat rinci
 * sudah ada di kontrak ini sejak awal karena integration
 * membutuhkannya untuk menyusun resource Patient SATUSEHAT. Yang
 * ditambahkan sekarang hanya empat kolom demografi yang memang jadi
 * sumbu pengelompokan grafik — bukan seluruh isi tabel pasien.
 */
return new class extends Migration
{
    private const S = 'identity';

    public function up(): void
    {
        $this->rebuild(', p.inpatient_classification, p.ethnicity, p.language,
                   p.religion, p.occupation, p.education, p.marital_status');
    }

    public function down(): void
    {
        $this->rebuild(', p.inpatient_classification, p.ethnicity, p.language');
    }

    private function rebuild(string $tambahan): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_patient_summary AS
            SELECT p.id, p.medical_record_number, p.nik, p.name, p.sex,
                   p.birth_place, p.birth_date, p.phone, p.email, p.address,
                   p.rt_rw, p.village_code, p.village_name, p.district_name,
                   p.city_name, p.province_name, p.postal_code,
                   p.special_precautions, p.special_precautions_color, p.updated_at'.$tambahan.'
              FROM '.self::S.'.patients p');
    }
};
