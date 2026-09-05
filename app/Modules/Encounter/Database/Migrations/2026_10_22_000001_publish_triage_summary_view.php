<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item C: kontrak baca triase IGD untuk RL 3.2 (Rawat Darurat).
 *
 * RL 3.2 menghitung kunjungan gawat darurat menurut tingkat kegawatannya.
 * Datanya ada di igd_triages, tapi reporting tidak boleh menyentuhnya
 * langsung.
 *
 * Kunjungan yang dibatalkan ikut tersaring di sini lewat join ke
 * registrasi yang sah — aturannya sudah dipegang kontrak encounter, jadi
 * reporting tidak perlu mengulangnya.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_triage_summary AS
            SELECT t.registration_id,
                   t.triage_level,
                   t.triaged_at,
                   r.patient_id,
                   r.unit_name,
                   r.payer_name,
                   r.service_date
              FROM ' . self::S . '.igd_triages t
              JOIN ' . self::S . '.v_registration_summary r ON r.id = t.registration_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_triage_summary');
    }
};
