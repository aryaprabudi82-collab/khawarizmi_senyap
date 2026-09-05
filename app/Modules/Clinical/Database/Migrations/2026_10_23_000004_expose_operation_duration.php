<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Domain J item D: lama operasi ikut dipaparkan pada kontrak kegiatan bedah. */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->rebuild(', o.started_at, o.finished_at');
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $durasi): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_operation_summary AS
            SELECT o.id, o.registration_id, o.patient_id, o.service_name, o.surgeon_name,
                   o.anesthesia_type, o.operating_room, o.performed_at' . $durasi . '
              FROM ' . self::S . '.operations o');
    }
};
