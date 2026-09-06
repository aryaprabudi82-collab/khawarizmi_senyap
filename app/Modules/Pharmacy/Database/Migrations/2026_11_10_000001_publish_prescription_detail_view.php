<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks pharmacy: RINCIAN resep per baris obat.
 *
 * Tiga view resep yang sudah ada masing-masing punya bentuknya sendiri dan
 * tak satu pun cocok untuk menyusun resource SATUSEHAT:
 * v_prescription_charge hanya memuat obat yang SUDAH DISERAHKAN berikut
 * nilainya (MedicationRequest justru perlu yang baru diresepkan, dan tidak
 * peduli harganya), sedangkan v_prescription_duration berbentuk satu baris
 * per resep tanpa isi obatnya sama sekali.
 *
 * ATURAN PAKAI OBAT IKUT DITERBITKAN, dan itu yang membedakan view ini dari
 * ketiganya. MedicationRequest tanpa dosage_instruction adalah resep yang
 * menyebut obatnya tapi tidak menyebut cara memakainya — persis bagian yang
 * paling berbahaya bila hilang saat pasien berobat di fasilitas lain.
 *
 * JUMLAH DIRESEPKAN DAN JUMLAH DISERAHKAN DITERBITKAN TERPISAH, tidak
 * dijadikan satu kolom. Keduanya memang sering sama, tapi saat berbeda
 * justru itu yang penting: obat yang diresepkan 30 tapi diserahkan 10
 * karena stok kurang harus terbaca apa adanya di kedua resource yang
 * berbeda (MedicationRequest memakai yang diresepkan, MedicationDispense
 * yang diserahkan). Menggabungkannya membuat salah satunya berbohong.
 *
 * SUBSTITUSI IKUT DITERBITKAN karena MedicationDispense punya tempat
 * khusus untuknya: obat yang diganti apoteker adalah kejadian yang wajib
 * terbaca fasilitas lain, bukan detail administratif internal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW pharmacy.v_prescription_detail AS
            SELECT
                i.id                        AS item_id,
                p.id                        AS prescription_id,
                p.prescription_number,
                p.registration_id,
                p.patient_id,
                p.patient_mrn,
                p.patient_name,
                p.kind,
                p.status,
                p.prescriber_id,
                p.prescriber_name,
                p.prescribed_at,
                p.reviewed_at,
                p.dispensed_at,
                p.dispensed_by_name,
                i.drug_id,
                i.drug_name,
                i.drug_unit,
                d.code                      AS drug_code,
                d.generic_name,
                d.kfa_code,
                d.form,
                d.strength,
                d.is_narcotic,
                d.is_psychotropic,
                d.is_high_alert,
                i.quantity                  AS prescribed_quantity,
                i.dispensed_quantity,
                i.dosage_instruction,
                i.note,
                i.substituted_from_drug_id,
                s.name                      AS substituted_from_drug_name
            FROM pharmacy.prescriptions p
            JOIN pharmacy.prescription_items i ON i.prescription_id = p.id
            LEFT JOIN pharmacy.drugs d ON d.id = i.drug_id
            LEFT JOIN pharmacy.drugs s ON s.id = i.substituted_from_drug_id
            WHERE p.deleted_at IS NULL');

        // Master obat diterbitkan TERBATAS pada yang dibutuhkan pemetaan
        // kode: identitas obatnya, bukan harga, stok, maupun margin. Konsumen
        // hanya perlu tahu obat apa saja yang ada dan bagaimana menyebutnya —
        // memaparkan harga dari sini melahirkan sumber kedua bagi angka yang
        // sudah dimiliki billing.
        DB::statement('CREATE OR REPLACE VIEW pharmacy.v_drug_catalog AS
            SELECT
                id          AS drug_id,
                code        AS drug_code,
                name        AS drug_name,
                generic_name,
                kfa_code,
                form,
                strength,
                unit,
                is_narcotic,
                is_psychotropic,
                is_high_alert,
                is_active
            FROM pharmacy.drugs');

        DB::statement('CREATE OR REPLACE VIEW pharmacy.v_prescription_review AS
            SELECT
                r.id                        AS review_id,
                r.prescription_id,
                p.registration_id,
                p.patient_id,
                p.prescription_number,
                r.outcome,
                r.findings,
                r.pharmacist_note,
                r.reviewer_id,
                r.reviewer_name,
                r.reviewed_at
            FROM pharmacy.prescription_reviews r
            JOIN pharmacy.prescriptions p ON p.id = r.prescription_id
            WHERE p.deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS pharmacy.v_prescription_review');
        DB::statement('DROP VIEW IF EXISTS pharmacy.v_drug_catalog');
        DB::statement('DROP VIEW IF EXISTS pharmacy.v_prescription_detail');
    }
};
