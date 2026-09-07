<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nomor kantong transfusi kini diperiksa (domain N item B).
 *
 * Saat domain M item R ditulis, kolom bag_number diberi komentar bahwa
 * ia BELUM bisa diperiksa terhadap kantong yang benar-benar dikeluarkan
 * UTD, karena domain N belum digarap. Utang itu sekarang lunas:
 * blood.v_issued_unit sudah terbit dan ClinicalMonitoringService
 * mencocokkannya.
 *
 * Komentarnya diperbarui, bukan dibiarkan — komentar basi yang menyebut
 * sebuah pemeriksaan belum ada akan dipercaya pembacanya, dan yang
 * membacanya berikutnya bisa menambahkan pemeriksaan kedua yang sudah
 * ada, atau lebih buruk, menganggap datanya masih tidak bisa dipercaya
 * padahal sudah bisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("COMMENT ON COLUMN clinical.transfusion_monitorings.bag_number IS
            'Diperiksa terhadap blood.v_issued_unit sejak domain N item B: kantong harus benar-benar dikeluarkan UTD dan untuk kunjungan ini'");
    }

    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN clinical.transfusion_monitorings.bag_number IS
            'Belum bisa diperiksa terhadap kantong yang dikeluarkan UTD — domain N belum digarap'");
    }
};
