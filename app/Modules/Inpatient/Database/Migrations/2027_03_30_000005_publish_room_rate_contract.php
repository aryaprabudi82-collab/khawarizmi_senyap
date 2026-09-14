<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan tarif akomodasi per KAMAR — `inpatient.v_room_rate`.
 *
 * MENGAPA KONTRAK BARU PADAHAL SUDAH ADA TIGA VIEW KAMAR. Karena tidak
 * satu pun di antaranya menerbitkan tarif akomodasi sebagai MASTER:
 *
 *   v_room_daily_charge — biaya harian TAMBAHAN yang menempel pada kamar
 *                         (oksigen sentral, laundry, asuhan keperawatan).
 *                         Memakainya sebagai tarif kamar berarti menagih
 *                         biaya oksigen sebagai harga kamar.
 *
 *   v_room_class_rate   — RATA-RATA tarif per kelas. Rata-rata tidak boleh
 *                         jadi dasar tagihan: dua kamar VIP bertarif beda
 *                         akan menagih keduanya di angka tengah yang tidak
 *                         pernah diputuskan siapa pun, dan totalnya tetap
 *                         terlihat wajar sehingga tidak ada yang memeriksa.
 *
 *   v_room_charge       — biaya kamar per hari untuk PASIEN YANG SEDANG
 *                         DIRAWAT. Itu transaksi, bukan master; CDM butuh
 *                         daftar apa yang BISA ditagihkan, termasuk kamar
 *                         yang sedang kosong.
 *
 * Maka view ini: satu baris per kamar, tarif sebenarnya, tanpa agregasi.
 *
 * TARIF KAMAR TIDAK DIPINDAH KE CDM, dan Migration Map saya ubah dari
 * `move` jadi `extend` karenanya — sama seperti koreksi `catalog.tariffs`
 * di Discovery §10. `daily_rate` melekat pada kamar, dan kamar dipakai
 * papan ketersediaan tempat tidur, perhitungan lama rawat, dan pemindahan
 * kamar — seluruhnya di luar keuangan. Memindahkan kolomnya berarti rawat
 * inap harus menanyakan keuangan setiap kali menampilkan daftar kamar.
 *
 * CATATAN YANG HARUS DIPUTUSKAN NANTI: `rooms.daily_rate` TIDAK BERPERIODE
 * — tidak ada valid_from/valid_until. Menaikkan tarif kamar hari ini akan
 * mengubah nilai rawat inap yang sedang berjalan dan yang sudah lewat,
 * karena tidak ada cara mengetahui tarif yang berlaku bulan lalu. Ini
 * cacat nyata, tapi memperbaikinya berarti membuat tabel tarif kamar
 * berperiode — pekerjaan tersendiri yang menyentuh billing. Dicatat di
 * OPEN-QUESTIONS sebagai Q14, bukan diperbaiki diam-diam di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW inpatient.v_room_rate AS
            SELECT
                r.id                                AS room_id,
                r.room_number,
                r.room_class,
                r.unit_id,
                r.unit_name,
                r.daily_rate,
                r.is_active,
                'AKM-' || upper(r.room_class)       AS accommodation_code,
                'Akomodasi ' || r.room_class || ' — ' || r.room_number AS accommodation_name
              FROM inpatient.rooms r
        ");

        DB::statement("COMMENT ON VIEW inpatient.v_room_rate IS
            'Kontrak terbitan: tarif akomodasi PER KAMAR — nominal sebenarnya, bukan rata-rata per kelas
             seperti v_room_class_rate, dan bukan biaya harian tambahan seperti v_room_daily_charge.
             Dipakai CDM keuangan untuk menaut kode global & pemetaan akun. Belum berperiode: lihat Q14.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS inpatient.v_room_rate');
    }
};
