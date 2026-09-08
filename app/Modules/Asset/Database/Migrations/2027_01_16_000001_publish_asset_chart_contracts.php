<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak grafik aset & kesehatan lingkungan (domain O item C).
 *
 * Menaungi 22 kode: 5 grafik_inventaris_*, 3 grafik_pengajuan_aset_*,
 * 4 grafik_perbaikan_inventaris_*, dan 10 grafik kesling (air PDAM, air
 * tanah, limbah B3 padat, limbah B3 cair, limbah domestik — masing-
 * masing per tanggal dan per bulan).
 *
 * URGENSI PENGAJUAN DITAMBAHKAN. asset.requisitions punya unit dan
 * status tapi tidak punya urgensi, sehingga
 * grafik_pengajuan_aset_urgensi tidak punya kolom sama sekali. Dan itu
 * bukan sekadar sumbu grafik yang hilang: pengajuan yang tidak bisa
 * dibedakan mendesaknya akan diproses menurut urutan datang, sehingga
 * permintaan mengganti alat yang rusak di ruang tindakan mengantre di
 * belakang permintaan mengganti kursi kantor.
 *
 * NILAI BAWAANNYA 'rutin', bukan kosong: pengajuan yang sudah tercatat
 * sebelum ini memang tidak menyatakan urgensinya, dan menganggapnya
 * rutin adalah tafsiran yang paling aman — yang mendesak selalu
 * disebutkan, yang tidak disebut biasanya memang tidak mendesak.
 * Berbeda dari jenis luka pada item B yang dibiarkan NULL: di sana
 * menebak berarti mengarang temuan klinis, di sini menebak cuma
 * menentukan urutan antrean yang bisa dikoreksi kapan saja.
 *
 * PENGUKURAN LINGKUNGAN DIJUMLAHKAN, BUKAN DIHITUNG. Grafik pemakaian
 * air dan timbulan limbah menanyakan BERAPA BANYAK, bukan berapa kali
 * dicatat — dan grafik yang menghitung baris akan menampilkan "jumlah
 * pencatatan" dengan label "pemakaian air", angka yang tampak masuk
 * akal dan sepenuhnya salah. Kontrak ini menerbitkan quantity berikut
 * satuannya supaya konsumen bisa menjumlahkannya.
 *
 * SATUAN IKUT DITERBITKAN, dan itu bukan hiasan: menjumlahkan kilogram
 * limbah padat bersama liter limbah cair menghasilkan angka yang tidak
 * berarti apa-apa. Konsumen wajib menyaring per kategori lebih dulu,
 * dan satuannya yang membuktikan penyaringnya benar.
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::table(self::S.'.requisitions', function (Blueprint $table) {
            $table->string('urgency', 20)->default('rutin')->after('unit_name')
                ->comment('rutin, mendesak, darurat — menentukan urutan pemrosesan, bukan cuma sumbu grafik');
        });

        DB::statement('ALTER TABLE '.self::S.".requisitions
            ADD CONSTRAINT requisitions_urgency_check
            CHECK (urgency IN ('rutin','mendesak','darurat'))");

        DB::statement('CREATE VIEW '.self::S.'.v_asset_inventory AS
            SELECT a.id, a.asset_number, a.name, a.brand, a.condition, a.status,
                   a.acquisition_date, a.acquisition_value, a.is_active,
                   c.name AS category_name,
                   t.name AS type_name,
                   m.name AS manufacturer_name,
                   l.name AS location_name
              FROM '.self::S.'.assets a
              LEFT JOIN '.self::S.'.categories c ON c.id = a.category_id
              LEFT JOIN '.self::S.'.types t ON t.id = a.type_id
              LEFT JOIN '.self::S.'.manufacturers m ON m.id = a.manufacturer_id
              LEFT JOIN '.self::S.'.locations l ON l.id = a.location_id');

        DB::statement('CREATE VIEW '.self::S.'.v_asset_requisition AS
            SELECT id, requisition_number, unit_id, unit_name, urgency, status, created_at
              FROM '.self::S.'.requisitions');

        DB::statement('CREATE VIEW '.self::S.'.v_maintenance_request AS
            SELECT r.id, r.request_number, r.status, r.assigned_to,
                   r.started_at, r.completed_at, r.created_at,
                   a.name AS asset_name,
                   l.name AS location_name
              FROM '.self::S.'.maintenance_requests r
              LEFT JOIN '.self::S.'.assets a ON a.id = r.asset_id
              LEFT JOIN '.self::S.'.locations l ON l.id = a.location_id');

        DB::statement('CREATE VIEW '.self::S.'.v_environmental_measurement AS
            SELECT id, category, parameter, measured_on, quantity, unit
              FROM '.self::S.'.environmental_measurements');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_environmental_measurement');
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_maintenance_request');
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_asset_requisition');
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_asset_inventory');

        DB::statement('ALTER TABLE '.self::S.'.requisitions
            DROP CONSTRAINT IF EXISTS requisitions_urgency_check');

        Schema::table(self::S.'.requisitions', function (Blueprint $table) {
            $table->dropColumn('urgency');
        });
    }
};
