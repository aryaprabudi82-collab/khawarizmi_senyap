<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item D: memaparkan stempel tahap pelayanan pada kontrak kunjungan.
 *
 * v_registration_summary sudah jadi kontrak yang dibaca banyak konteks,
 * jadi kolom tahapan ditambahkan ke situ alih-alih menerbitkan view baru —
 * pertanyaannya masih tentang kunjungan yang sama, bukan entitas lain.
 *
 * Penyaring status <> 'batal' TIDAK diubah: kunjungan batal tetap tidak
 * boleh muncul di kontrak ini, invarian yang sudah dijaga sejak domain J
 * item A dan sengaja tidak dilonggarkan demi laporan mana pun.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        $this->rebuild(', r.called_at, r.served_at, r.finished_at');
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $tahapan): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_triage_summary');
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_registration_summary');

        DB::statement('CREATE VIEW ' . self::S . '.v_registration_summary AS
            SELECT r.id, r.registration_number, r.patient_id, r.patient_mrn, r.patient_name,
                   r.unit_id, r.unit_name, r.practitioner_id, r.practitioner_name,
                   r.payer_id, r.payer_name, r.service_date, r.registered_at, r.queue_number,
                   r.care_type, r.status, r.registration_fee, r.payment_status' . $tahapan . '
              FROM ' . self::S . ".registrations r
             WHERE r.status <> 'batal'");

        // v_triage_summary bergantung pada view di atas, jadi ikut dibangun ulang.
        DB::statement('CREATE VIEW ' . self::S . '.v_triage_summary AS
            SELECT t.id, t.registration_id, t.triage_level, t.chief_complaint, t.triaged_at,
                   r.patient_id, r.patient_name, r.service_date, r.unit_name, r.care_type
              FROM ' . self::S . '.igd_triages t
              JOIN ' . self::S . '.v_registration_summary r ON r.id = t.registration_id');
    }
};
