<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item D: tahap tengah permintaan penunjang ikut dipaparkan.
 *
 * v_order_summary dibuat di domain J item A untuk MENGHITUNG permintaan,
 * jadi ia hanya membawa requested_at dan verified_at. Lama pelayanan
 * radiologi/lab menanyakan hal berbeda — di tahap mana waktunya habis —
 * sehingga processed_at dan resulted_at perlu ikut terpapar.
 *
 * Kolom ditambahkan ke kontrak yang sudah ada, bukan diterbitkan sebagai
 * view baru: pertanyaannya masih tentang permintaan penunjang yang sama.
 *
 * Ditaruh di AKHIR daftar kolom, bukan pada urutan alaminya di tengah,
 * karena CREATE OR REPLACE VIEW PostgreSQL hanya mengizinkan penambahan
 * di belakang. Menyisipkan di tengah menuntut DROP lebih dulu, dan itu
 * akan ikut menjatuhkan view lain yang bergantung padanya — harga yang
 * tidak sebanding dengan urutan kolom yang toh tidak dibaca siapa pun.
 */
return new class extends Migration
{
    private const S = 'orders';

    public function up(): void
    {
        $this->rebuild(', o.processed_at, o.resulted_at');
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $tahap): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_order_summary AS
            SELECT o.id AS order_id, o.order_number, o.registration_id, o.patient_id,
                   o.patient_mrn, o.patient_name, o.unit_name, o.category, o.status,
                   o.requesting_practitioner_id, o.requesting_practitioner_name,
                   o.requested_at, o.verified_at' . $tahap . '
              FROM ' . self::S . '.orders o
             WHERE o.deleted_at IS NULL');
    }
};
