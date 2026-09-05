<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak baca biaya kamar untuk billing — domain I item A.
 *
 * Sampai sekarang biaya kamar rawat inap tidak pernah sampai ke tagihan:
 * InvoiceService punya lima sumber biaya (registrasi, resep, order
 * penunjang, tindakan, operasi) dan tidak satu pun kamar. Itulah celah
 * sesungguhnya di balik kode pembayaran_ranap — bukan tagihan rawat inap
 * yang belum ada (admisi sudah punya registration_id sendiri, dan
 * billing.invoices memang berkunci pada registrasi), melainkan biayanya
 * yang tidak pernah masuk.
 *
 * SATU BARIS PER HARI MENGINAP (dikonfirmasi user), bukan satu baris
 * untuk seluruh masa rawat: itu yang lazim dipertanggungjawabkan ke
 * pasien/penjamin saat rinciannya ditanyakan, dan satu baris tidak bisa
 * mewakili dua tarif berbeda kalau pasien pindah kelas kamar.
 *
 * Aturan hari yang ditagih:
 *  - pasien sudah pulang : dari tanggal masuk sampai sehari sebelum
 *    tanggal pulang (menghitung malam yang ditempati), minimal 1 hari
 *    supaya masuk-pulang di hari yang sama tetap tertagih;
 *  - pasien masih dirawat: dari tanggal masuk sampai hari ini.
 *
 * BATASAN YANG DIAKUI: inpatient belum punya riwayat pindah bed —
 * admissions.bed_id hanya menyimpan bed terkini. Jadi seluruh hari
 * memakai tarif kamar terkini, dan kalau pasien pindah kelas di tengah
 * rawat, hari-hari sebelumnya ikut berubah harga. Memperbaikinya butuh
 * tabel riwayat penempatan bed di konteks inpatient, bukan tambalan di
 * sisi billing.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_room_charge AS
            SELECT
                a.registration_id,
                a.id                              AS admission_id,
                hari::date                        AS charge_date,
                r.room_number,
                r.room_class,
                b.bed_number,
                r.daily_rate                      AS unit_price,
                1                                 AS quantity,
                r.daily_rate                      AS amount
            FROM ' . self::S . '.admissions a
            JOIN ' . self::S . '.beds  b ON b.id = a.bed_id
            JOIN ' . self::S . '.rooms r ON r.id = b.room_id
            CROSS JOIN LATERAL generate_series(
                a.admitted_at::date,
                CASE
                    WHEN a.discharged_at IS NULL THEN now()::date
                    ELSE GREATEST(a.admitted_at::date, a.discharged_at::date - 1)
                END,
                interval \'1 day\'
            ) AS hari');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_room_charge');
    }
};
