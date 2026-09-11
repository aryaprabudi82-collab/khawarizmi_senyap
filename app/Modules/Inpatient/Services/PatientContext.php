<?php

namespace App\Modules\Inpatient\Services;

use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks inpatient menyentuh data milik konteks
 * identity, lewat kontrak terbitannya — bukan tabel pasiennya.
 *
 * Dibuat saat verifikasi domain M, untuk satu keperluan sempit: menyalin
 * kategori pasien ke admisi supaya rekap klasifikasi tidak berubah
 * belakangan. Sengaja tidak mengembalikan seluruh baris pasien — admisi
 * sudah menyimpan nomor rekam medis dan namanya sendiri, dan kontrak yang
 * membawa lebih banyak daripada yang dibutuhkan akan dipakai lebih banyak
 * daripada yang dimaksudkan.
 */
class PatientContext
{
    public function category(int $patientId): ?string
    {
        $nilai = DB::table('identity.v_patient_summary')
            ->where('id', $patientId)
            ->value('inpatient_classification');

        /*
         * Kosong dikembalikan sebagai null, bukan string kosong. Keduanya
         * berarti "belum dikategorikan", dan dua bentuk untuk satu keadaan
         * berarti setiap pengelompokan harus ingat memeriksa dua-duanya.
         */
        return $nilai === null || trim((string) $nilai) === '' ? null : $nilai;
    }
}
