<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain I item C lanjutan: kode fee_ralan, fee_visit_dokter, dan
 * fee_bacaan_ekg.
 *
 * Ketiganya ternyata bukan jenis fee baru, melainkan potongan berbeda dari
 * jasa dokter yang sudah dibekukan: fee_ralan disaring ke rawat jalan,
 * fee_visit_dokter ke rawat inap, fee_bacaan_ekg ke layanan EKG. Yang
 * kurang cuma dua penyaring.
 *
 * service_code dipaparkan supaya penyaringan layanan berpegang pada kode,
 * bukan pada nama layanan yang bisa diubah kapan saja tanpa terasa.
 * Jenis rawatnya tidak ikut disalin ke sini — billing sudah membaca
 * encounter.v_registration_summary, jadi ia menyambungkannya lewat
 * registration_id, tetap sesama kontrak terbitan.
 */
return new class extends Migration
{
    private const S = 'clinical';

    private const KOMPONEN = 'share_facility, share_bhp, share_doctor, share_paramedic, share_kso, share_management';

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_procedure_charge');
        DB::statement('CREATE VIEW ' . self::S . '.v_procedure_charge AS
            SELECT registration_id, id AS item_id, service_code, service_name, quantity, unit_price, amount, performed_at,
                   practitioner_id, practitioner_name,
                   ' . self::KOMPONEN . '
            FROM ' . self::S . '.procedures');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_procedure_charge');
        DB::statement('CREATE VIEW ' . self::S . '.v_procedure_charge AS
            SELECT registration_id, id AS item_id, service_name, quantity, unit_price, amount, performed_at,
                   practitioner_id, practitioner_name,
                   ' . self::KOMPONEN . '
            FROM ' . self::S . '.procedures');
    }
};
