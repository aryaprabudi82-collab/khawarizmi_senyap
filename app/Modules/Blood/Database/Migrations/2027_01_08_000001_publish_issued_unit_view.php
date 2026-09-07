<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak kantong darah yang dikeluarkan (domain N item B).
 *
 * MELUNASI UTANG YANG DICATAT PADA DOMAIN M ITEM R. Pemantauan reaksi
 * transfusi di clinical menyimpan bag_number sebagai teks bebas, dengan
 * catatan terang-terangan bahwa ia belum bisa diperiksa terhadap
 * kantong yang benar-benar dikeluarkan — karena domain N belum digarap.
 * Sekarang sudah, dan pemeriksaannya bisa dipasang.
 *
 * Tanpa kontrak ini, rantai dari donor sampai pasien putus di tengah:
 * blood tahu kantong mana keluar untuk siapa, clinical tahu pasien mana
 * bereaksi terhadap kantong bernomor berapa, dan tidak ada yang
 * menyambungkan keduanya. Reaksi transfusi yang tidak bisa ditelusuri
 * sampai ke donornya adalah reaksi yang tidak bisa dicegah terulang.
 *
 * YANG DITERBITKAN HANYA IDENTITAS KANTONG DAN PENERIMANYA — bukan
 * data donornya. Ruang perawatan tidak perlu tahu siapa pendonornya,
 * dan menerbitkannya akan membuka identitas donor kepada siapa pun yang
 * bisa membaca rekam medis pasien. Penelusuran ke donor tetap lewat
 * blood, oleh petugas UTD, dengan jejaknya sendiri.
 */
return new class extends Migration
{
    private const S = 'blood';

    public function up(): void
    {
        DB::statement('CREATE VIEW '.self::S.'.v_issued_unit AS
            SELECT i.id                AS issue_id,
                   i.issue_number,
                   u.unit_number,
                   u.blood_type,
                   u.rhesus,
                   u.component,
                   u.volume_ml,
                   u.expiry_date,
                   i.patient_id,
                   i.registration_id,
                   i.patient_name,
                   i.indication,
                   i.issued_at
              FROM '.self::S.'.transfusion_issues i
              JOIN '.self::S.'.blood_units u ON u.id = i.blood_unit_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_issued_unit');
    }
};
