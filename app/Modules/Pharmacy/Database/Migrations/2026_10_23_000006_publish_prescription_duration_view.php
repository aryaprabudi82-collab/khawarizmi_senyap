<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item D: rantai waktu resep diterbitkan untuk laporan lama pelayanan.
 *
 * pharmacy.prescriptions sudah menyimpan empat tahap lengkap
 * (prescribed_at -> submitted_at -> reviewed_at -> dispensed_at), jadi
 * lama_pelayanan_apotek tidak butuh pencatatan baru — cukup kontraknya.
 *
 * Diterbitkan TERPISAH dari v_prescription_charge yang sudah ada karena
 * yang itu berbentuk penagihan (per baris obat berikut nilainya) dan akan
 * menggandakan resep menjadi sebanyak jumlah obatnya — rata-rata lama
 * pelayanan jadi condong ke resep yang isinya paling banyak, tanpa
 * terlihat salah. Kontrak ini satu baris per RESEP.
 *
 * Resep yang dibatalkan dikecualikan: resep yang tidak jadi diserahkan
 * bukan pelayanan yang lamanya bisa diukur.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_prescription_duration AS
            SELECT p.id, p.prescription_number, p.registration_id, p.patient_id,
                   p.unit_name, p.kind, p.status,
                   p.prescribed_at, p.submitted_at, p.reviewed_at, p.dispensed_at
              FROM ' . self::S . ".prescriptions p
             WHERE p.deleted_at IS NULL
               AND p.status <> 'batal'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_prescription_duration');
    }
};
