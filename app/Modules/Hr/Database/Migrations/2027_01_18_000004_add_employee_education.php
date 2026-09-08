<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pendidikan pegawai (domain O item D).
 *
 * Domain O punya sembilan grafik kepegawaian; hr.employees hanya punya
 * position, employment_type, dan unit_id, sehingga cuma tiga di
 * antaranya bisa digambar.
 *
 * YANG DITAMBAHKAN CUMA PENDIDIKAN, DAN ITU DISENGAJA. Jenjang
 * pendidikan punya daftar yang sudah ditetapkan di luar rumah sakit
 * (SD sampai S3, plus profesi dan spesialis), dibutuhkan pelaporan
 * ketenagaan ke Kemenkes, dan tidak menuntut RSP UI memutuskan apa pun
 * lebih dulu.
 *
 * LIMA DIMENSI SISANYA TIDAK DIKARANG. Jenjang jabatan, kelompok
 * jabatan, status wajib pajak, risiko kerja, dan emergency index
 * semuanya menuntut RSP UI menetapkan kosakatanya sendiri — berapa
 * jenjang, apa namanya, siapa masuk kelompok mana. Membuat kolomnya
 * sekarang berarti menebak struktur kepegawaian sebuah rumah sakit
 * demi menggambar batang, dan yang dihasilkan grafik yang tampak resmi
 * dengan kategori yang tidak pernah disepakati siapa pun. Kelimanya
 * dicatat di ChartCatalog::pendingData() supaya terlihat sebagai
 * keputusan, bukan kelalaian.
 *
 * KOLOM BARU DI UJUNG VIEW — pelajaran yang sudah berulang.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        Schema::table(self::S.'.employees', function (Blueprint $table) {
            $table->string('education', 30)->nullable()->after('position')
                ->comment('Jenjang pendidikan terakhir; daftarnya ditetapkan di luar rumah sakit');
        });

        $this->rebuild(', education, employment_type');
    }

    public function down(): void
    {
        $this->rebuild('');

        Schema::table(self::S.'.employees', function (Blueprint $table) {
            $table->dropColumn('education');
        });
    }

    private function rebuild(string $tambahan): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_employee_summary AS
            SELECT id, employee_number, name, position, unit_id, is_active'.$tambahan.'
            FROM '.self::S.'.employees
            WHERE is_active = true');
    }
};
