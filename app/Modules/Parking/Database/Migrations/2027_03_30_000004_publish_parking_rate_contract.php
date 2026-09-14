<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan tarif parkir — `parking.v_rate`.
 *
 * TARIF PARKIR BERBEDA BENTUK dari seluruh tarif lain di rumah sakit, dan
 * itu alasan terkuat mengapa CDM tidak boleh menyeragamkan bentuknya:
 *
 *   basis 'jam'    — tarif dikali jumlah jam, dengan `free_minutes`
 *                    menit pertama yang tidak ditagih
 *   basis 'harian' — tarif tetap sekali, berapa pun lamanya
 *
 * Memaksakan keduanya ke kolom `amount` tunggal berarti seseorang harus
 * mengingat bahwa untuk parkir angka itu berarti "per jam" — dan yang
 * lupa tidak menerima galat, ia menagih tarif sejam untuk parkir seharian.
 *
 * `free_menit` ikut diterbitkan karena ia bagian dari TARIFNYA, bukan
 * kebijakan operasional terpisah: tarif tanpa menit bebasnya adalah tarif
 * yang salah, dan memisahkannya membuat pengantar pasien yang berhenti
 * lima menit ditagih satu jam penuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW parking.v_rate AS
            SELECT
                r.id      AS rate_id,
                r.code,
                r.name,
                r.fee,
                r.basis,
                r.free_minutes,
                r.is_active
              FROM parking.rates r
        ');

        DB::statement("COMMENT ON VIEW parking.v_rate IS
            'Kontrak terbitan: tarif parkir berikut BASIS-nya (jam/harian) dan menit bebas biaya.
             Basis wajib ikut dibaca — nominal tanpa basisnya tidak punya arti, dan menagihnya
             sebagai tarif tetap berarti parkir seharian dibayar seharga sejam.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS parking.v_rate');
    }
};
