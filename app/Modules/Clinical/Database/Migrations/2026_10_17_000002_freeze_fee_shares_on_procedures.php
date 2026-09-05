<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain I item C: porsi jasa medis dibekukan pada tindakan yang sudah
 * dilakukan (dikonfirmasi user).
 *
 * Alasannya sama seperti unit_price yang sudah dibekukan di tabel ini,
 * biaya parkir, total tagihan, dan pokok piutang: kalau tarif naik bulan
 * depan, remunerasi dokter bulan lalu tidak boleh ikut bergerak. Untuk
 * uang yang dibayarkan ke orang, angka yang pernah dilaporkan tidak boleh
 * bergeser di belakang.
 *
 * Nilainya diambil dari komponen tarif yang berlaku saat tindakan
 * dicatat, lalu dikali kuantitas — sekali, di tempat yang sama dengan
 * pembekuan unit_price, bukan dihitung ulang setiap laporan dibuka.
 *
 * v_procedure_charge ikut diperluas supaya billing bisa menyusun rekap JM
 * tanpa menyentuh tabel clinical secara langsung.
 */
return new class extends Migration
{
    private const S = 'clinical';

    private const KOMPONEN = [
        'share_facility', 'share_bhp', 'share_doctor',
        'share_paramedic', 'share_kso', 'share_management',
    ];

    public function up(): void
    {
        Schema::table(self::S . '.procedures', function (Blueprint $table) {
            foreach (self::KOMPONEN as $kolom) {
                $table->decimal($kolom, 14, 2)->default(0);
            }
        });

        $jumlah = implode(' + ', self::KOMPONEN);

        // Sama seperti di tarif: belum dirinci itu sah, rincian yang tidak
        // menjumlah tidak.
        DB::statement('ALTER TABLE ' . self::S . ".procedures ADD CONSTRAINT procedures_shares_check
            CHECK (({$jumlah}) = 0 OR ({$jumlah}) = amount)");

        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_procedure_charge');
        DB::statement('CREATE VIEW ' . self::S . '.v_procedure_charge AS
            SELECT registration_id, id AS item_id, service_name, quantity, unit_price, amount, performed_at,
                   practitioner_id, practitioner_name,
                   ' . implode(', ', self::KOMPONEN) . '
            FROM ' . self::S . '.procedures');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_procedure_charge');
        DB::statement('CREATE VIEW ' . self::S . '.v_procedure_charge AS
            SELECT registration_id, id AS item_id, service_name, quantity, unit_price, amount, performed_at
            FROM ' . self::S . '.procedures');

        DB::statement('ALTER TABLE ' . self::S . '.procedures DROP CONSTRAINT procedures_shares_check');

        Schema::table(self::S . '.procedures', function (Blueprint $table) {
            $table->dropColumn(self::KOMPONEN);
        });
    }
};
