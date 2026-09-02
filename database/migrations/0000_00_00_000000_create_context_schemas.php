<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Membuat satu schema PostgreSQL untuk tiap bounded context yang aktif.
 *
 * Berjalan paling awal (prefiks 0000) karena seluruh migrasi modul membuat
 * tabelnya di dalam schema ini. Daftarnya diambil dari config/contexts.php
 * supaya manifes tetap jadi satu-satunya sumber kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->schemas() as $schema) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS "' . $schema . '"');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->schemas()) as $schema) {
            DB::statement('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        }
    }

    /** @return list<string> */
    private function schemas(): array
    {
        return array_values(array_map(
            fn (array $ctx): string => $ctx['schema'],
            config('contexts.active', [])
        ));
    }
};
