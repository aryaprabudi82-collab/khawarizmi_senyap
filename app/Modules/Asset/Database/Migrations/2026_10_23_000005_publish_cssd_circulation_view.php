<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item D: sirkulasi CSSD diterbitkan untuk laporan lama pelayanan.
 *
 * asset.cssd_circulations ternyata sudah menyimpan rantai empat tahap
 * lengkap (received_at -> processed_at -> sterilized_at -> distributed_at),
 * jadi lama_pelayanan_cssd tidak butuh pencatatan baru sama sekali —
 * cukup diterbitkan sebagai kontrak supaya reporting tidak menyentuh
 * tabel asset langsung.
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_cssd_circulation AS
            SELECT c.id, c.circulation_number, c.cssd_item_id, c.unit_name, c.status,
                   c.received_at, c.processed_at, c.sterilized_at, c.distributed_at,
                   c.sterilization_method
              FROM ' . self::S . '.cssd_circulations c');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_cssd_circulation');
    }
};
