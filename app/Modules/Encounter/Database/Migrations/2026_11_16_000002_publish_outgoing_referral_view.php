<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks encounter: RUJUKAN KELUAR.
 *
 * Diterbitkan supaya integration bisa MENGIRIM rujukan yang sudah tercatat
 * ke Sisrute (domain L item P) tanpa menyalin isinya. Rujukan itu sendiri
 * tetap milik encounter; yang dicatat integration cuma pengirimannya dan
 * jawaban rumah sakit tujuan.
 *
 * Tanpa kontrak ini, satu-satunya jalan adalah menyalin isi rujukan ke
 * tabel integration — dan salinan berarti dua sumber kebenaran untuk satu
 * rujukan yang sama, dengan yang dikirim ke Sisrute justru bisa jadi yang
 * sudah basi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW encounter.v_outgoing_referral AS
            SELECT
                id,
                referral_number,
                registration_id,
                patient_id,
                patient_mrn,
                patient_name,
                destination_facility_name,
                destination_facility_code,
                reason,
                diagnosis,
                practitioner_id,
                practitioner_name,
                referred_at,
                status
            FROM encounter.outgoing_referrals');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS encounter.v_outgoing_referral');
    }
};
