<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis kelamin boleh TIDAK DIKETAHUI — syarat migrasi data HSN.
 *
 * MENGAPA DILONGGARKAN.
 *
 * Migrasi 348.880 pasien dari sistem HSN menemukan kenyataan yang tidak bisa
 * ditawar: jenis kelamin tidak ikut terekspor. Kolomnya ada di sembilan tabel
 * sumber — `_Member_`, `AfyaMobile_SisUser`, `BedManagement`, dan lainnya —
 * tetapi seluruhnya nol baris. Satu-satunya yang berisi (`BillingPenjaminHeader`,
 * 58.859 baris) ternyata tidak punya satu pun kunci yang menyambung ke pasien.
 *
 * Yang bisa dipulihkan hanya 238.143 pasien (68,2%), disimpulkan dari sufiks
 * gelar pada nama (NY/NN → P, TN → L) dengan akurasi terverifikasi 99,97%
 * terhadap data gender sungguhan. Sisanya 110.740 pasien — bersufiks AN/BY
 * (anak/bayi, penanda umur bukan gender) atau tanpa sufiks sama sekali —
 * tidak punya dasar apa pun untuk ditentukan.
 *
 * MENGAPA NULL, BUKAN NILAI TEBAKAN.
 *
 * Mengisi 110.740 pasien dengan 'L' akan membuat kolom ini tetap NOT NULL dan
 * migrasi berjalan mulus — dan itu justru bahayanya. Jenis kelamin menentukan
 * rentang rujukan laboratorium, perhitungan dosis, dan skrining klinis. Nilai
 * yang salah tidak pernah muncul sebagai galat; ia hanya menghasilkan angka
 * yang terlihat wajar. NULL berteriak "belum diketahui"; 'L' yang ditebak
 * berbohong dengan tenang.
 *
 * CHECK-nya TIDAK dihapus, hanya diberi kelonggaran untuk NULL. Nilai selain
 * 'L' dan 'P' tetap ditolak basis data — yang berubah cuma: boleh kosong.
 *
 * PASIEN BARU TETAP WAJIB MENGISI. Kelonggaran ini untuk data warisan yang
 * memang tidak lengkap, bukan izin bagi loket pendaftaran untuk melewatinya:
 * PatientController masih memvalidasi `required|in:L,P`, dan formulirnya masih
 * menandai kolom itu wajib.
 */
return new class extends Migration
{
    private const S = 'identity';

    public function up(): void
    {
        /*
         * Urutannya: lepas CHECK lama dulu, baru longgarkan kolomnya.
         * Terbalik pun jalan di PostgreSQL, tapi urutan ini membuat setiap
         * langkah tetap sah bila yang berikutnya gagal di tengah.
         */
        DB::statement('ALTER TABLE '.self::S.'.patients DROP CONSTRAINT IF EXISTS patients_sex_check');

        DB::statement('ALTER TABLE '.self::S.'.patients ALTER COLUMN sex DROP NOT NULL');

        DB::statement("ALTER TABLE ".self::S.".patients ADD CONSTRAINT patients_sex_check
            CHECK (sex IS NULL OR sex IN ('L','P'))");

        DB::statement("COMMENT ON COLUMN ".self::S.".patients.sex IS
            'L atau P. NULL berarti BELUM DIKETAHUI — dipakai data warisan HSN yang
             jenis kelaminnya tidak ikut terekspor. Pasien baru tetap wajib mengisi.'");
    }

    public function down(): void
    {
        /*
         * Mengembalikan NOT NULL akan GAGAL selama masih ada baris ber-sex NULL,
         * dan itu memang seharusnya: memaksa kolom ini terisi lagi berarti
         * seseorang harus memutuskan nilai untuk 110.740 pasien, dan keputusan
         * itu bukan milik sebuah migration.
         */
        DB::statement('ALTER TABLE '.self::S.'.patients DROP CONSTRAINT IF EXISTS patients_sex_check');

        DB::statement('ALTER TABLE '.self::S.'.patients ALTER COLUMN sex SET NOT NULL');

        DB::statement("ALTER TABLE ".self::S.".patients ADD CONSTRAINT patients_sex_check
            CHECK (sex IN ('L','P'))");

        DB::statement("COMMENT ON COLUMN ".self::S.".patients.sex IS 'L atau P'");
    }
};
