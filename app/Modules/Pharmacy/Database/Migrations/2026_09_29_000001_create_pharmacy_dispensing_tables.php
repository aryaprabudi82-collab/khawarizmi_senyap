<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain D Khanza item 4 dari 6 sub-order yang disepakati — permintaan
 * farmasi ruangan/pasien. 8 kode, semua context "pharmacy" di katalog
 * (dikonfirmasi lewat Khanza_Functional_Dependency_Map.xlsx).
 *
 * Desain dikonfirmasi user (AskUserQuestion) sebelum dibangun:
 *
 *  - resep_dokter (Daftar Resep Dokter) SUDAH terpenuhi layar Antrean
 *    Resep yang ada (resep.index, dibangun jauh sebelum domain D) —
 *    tidak ada tabel/kode baru untuknya.
 *  - permintaan_resep_pulang cukup filter 'kind' baru di layar yang
 *    sama (prescriptions.kind sudah ada sejak migrasi 2026_09_15) —
 *    tidak ada tabel baru, lihat PrescriptionController::index().
 *  - pengeluaran_stok_apotek (2 dialog Khanza: "Permintaan Obat & BHP"
 *    dan "Stok Keluar Medis" — pola dual-dialog-satu-flag sama seperti
 *    bayar_pemesanan_obat di item 2) dan pengambilan_utd digabung SATU
 *    mekanisme "permintaan stok ruangan/departemen" generik
 *    (ward_stock_requests) — UTD diperlakukan sebagai salah satu unit
 *    tujuan lewat organization.v_unit_summary, bukan tabel sendiri.
 *    Beda arah dari drug_requisitions (item 2, depo->suplier): ini
 *    depo->ruangan.
 *  - permintaan_stok_obat_pasien (Daftar Permintaan Stok Pasien) dan
 *    stok_obat_pasien (Stok Pasien) digabung mekanisme serupa tapi
 *    terikat registrasi/pasien tertentu, bukan unit
 *    (patient_stock_requests) — permintaan_stok_obat_pasien gerbang
 *    literal (daftar+ajukan), stok_obat_pasien jadi tampilan "stok
 *    pasien saat ini" pada layar yang sama.
 *  - resep_luar (Resep Luar — resep ditulis di luar RS, dilayani
 *    sebagai walk-in) fitur baru sendiri (external_prescriptions),
 *    tidak terikat registration_id karena pasiennya belum tentu
 *    tercatat identity.patients.
 *  - penggunaan_bhp_ok (BHP dipakai saat tindakan OK/VK) fitur baru
 *    sendiri (procedure_bhp_usages), operation_id referensi longgar ke
 *    clinical.operations (opsional — banyak tindakan VK/kebidanan
 *    kecil tidak selalu tercatat di clinical.operations).
 *
 * Seluruh pengeluaran stok di sini lewat StockLedger::issue() (FEFO,
 * guard atomik) dari lokasi DEPO-RJ — sama seperti resep_obat/beri_obat.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        $this->createWardStockRequests();
        $this->createPatientStockRequests();
        $this->createExternalPrescriptions();
        $this->createProcedureBhpUsages();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.procedure_bhp_usage_items');
        Schema::dropIfExists(self::S . '.procedure_bhp_usages');
        Schema::dropIfExists(self::S . '.external_prescription_items');
        Schema::dropIfExists(self::S . '.external_prescriptions');
        Schema::dropIfExists(self::S . '.patient_stock_request_items');
        Schema::dropIfExists(self::S . '.patient_stock_requests');
        Schema::dropIfExists(self::S . '.ward_stock_request_items');
        Schema::dropIfExists(self::S . '.ward_stock_requests');
    }

    /** pengeluaran_stok_apotek + pengambilan_utd — permintaan stok ruangan/departemen, bukan untuk pasien tertentu. */
    private function createWardStockRequests(): void
    {
        Schema::create(self::S . '.ward_stock_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 24)->unique();
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar — termasuk UTD kalau terdaftar sebagai unit');
            $table->string('unit_name', 150)->comment('Disalin saat pengajuan');

            $table->string('status', 20)->default('diajukan')->comment('diajukan, dikeluarkan, ditolak');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".ward_stock_requests ADD CONSTRAINT ward_stock_requests_status_check
            CHECK (status IN ('diajukan','dikeluarkan','ditolak'))");

        Schema::create(self::S . '.ward_stock_request_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('request_id')->constrained(self::S . '.ward_stock_requests')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200)->comment('Disalin saat pengajuan');
            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_issued', 12, 2)->default(0);
            $table->index('request_id');
        });
    }

    /** permintaan_stok_obat_pasien + stok_obat_pasien — sama pola dengan ward_stock_requests, tapi terikat registrasi/pasien tertentu. */
    private function createPatientStockRequests(): void
    {
        Schema::create(self::S . '.patient_stock_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 24)->unique();
            $table->unsignedBigInteger('registration_id')->comment('ID encounter.registrations, referensi longgar');
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->string('status', 20)->default('diajukan')->comment('diajukan, dikeluarkan, ditolak');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();
            $table->index('status');
            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".patient_stock_requests ADD CONSTRAINT patient_stock_requests_status_check
            CHECK (status IN ('diajukan','dikeluarkan','ditolak'))");

        Schema::create(self::S . '.patient_stock_request_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('request_id')->constrained(self::S . '.patient_stock_requests')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200)->comment('Disalin saat pengajuan');
            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_issued', 12, 2)->default(0);
            $table->index('request_id');
        });
    }

    /** resep_luar — resep ditulis di luar RS, dilayani sebagai walk-in. Tidak terikat registration_id, pasien belum tentu tercatat identity.patients. */
    private function createExternalPrescriptions(): void
    {
        Schema::create(self::S . '.external_prescriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('prescription_number', 24)->unique();
            $table->string('patient_name', 150);
            $table->string('patient_identity_number', 40)->nullable()->comment('No. KTP/identitas pasien luar');
            $table->string('prescriber_name', 150);
            $table->string('prescriber_license', 60)->nullable()->comment('No. SIP dokter penulis resep asli');
            $table->date('issued_date')->comment('Tanggal resep asli diterbitkan, bisa beda dari tanggal dilayani');

            $table->string('status', 20)->default('diterima')->comment('diterima, diserahkan, dibatalkan');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('received_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('dispensed_by')->nullable();
            $table->timestampTz('dispensed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".external_prescriptions ADD CONSTRAINT external_prescriptions_status_check
            CHECK (status IN ('diterima','diserahkan','dibatalkan'))");

        Schema::create(self::S . '.external_prescription_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('external_prescription_id')->constrained(self::S . '.external_prescriptions')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->index('external_prescription_id');
        });
    }

    /** penggunaan_bhp_ok — BHP dipakai saat tindakan di OK/VK, dicatat begitu dipakai (bukan diminta dulu), langsung memotong stok. */
    private function createProcedureBhpUsages(): void
    {
        Schema::create(self::S . '.procedure_bhp_usages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('usage_number', 24)->unique();
            $table->unsignedBigInteger('operation_id')->nullable()->comment('ID clinical.operations, referensi longgar — opsional, tidak semua tindakan VK tercatat di sana');
            $table->string('room', 10)->comment('OK atau VK');
            $table->string('patient_name', 150);
            $table->string('patient_mrn', 20)->nullable();

            $table->timestampTz('used_at');
            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".procedure_bhp_usages ADD CONSTRAINT procedure_bhp_usages_room_check
            CHECK (room IN ('OK','VK'))");

        Schema::create(self::S . '.procedure_bhp_usage_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('usage_id')->constrained(self::S . '.procedure_bhp_usages')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->index('usage_id');
        });
    }
};
