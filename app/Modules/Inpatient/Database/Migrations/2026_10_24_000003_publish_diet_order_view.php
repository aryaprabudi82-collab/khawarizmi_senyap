<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item E: permintaan diet diterbitkan untuk laporan gizi.
 *
 * inpatient.diet_orders sudah ada sejak modul ranap, jadi tiga dari empat
 * kode gizi (rekap_permintaan_diet, jumlah_macam_diet, jumlah_porsi_diet)
 * tidak butuh pencatatan baru.
 *
 * Yang diterbitkan menyertakan hari-diet — selisih start_date dan
 * end_date — karena "porsi" pada Khanza dihitung per hari pemberian,
 * bukan per baris permintaan. Satu permintaan diet lima hari adalah lima
 * hari-diet, dan menghitungnya sebagai satu akan membuat angka gizi jauh
 * lebih kecil daripada kenyataannya tanpa terlihat salah.
 *
 * TIDAK ada penyaring status. Status diet hanya 'aktif' dan
 * 'dihentikan', dan yang dihentikan TETAP sempat diberikan sampai
 * tanggal berhentinya — membuangnya akan menghilangkan porsi yang sungguh
 * dimasak. Penyaring status di sini adalah kesalahan yang mudah terjadi
 * karena tampak hati-hati, padahal justru mengurangi angka.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_diet_order AS
            SELECT d.id, d.admission_id, d.diet_type, d.status,
                   d.start_date, d.end_date, d.ordered_by_name,
                   (coalesce(d.end_date, current_date) - d.start_date) + 1 AS hari_diet
              FROM ' . self::S . '.diet_orders d');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_diet_order');
    }
};
