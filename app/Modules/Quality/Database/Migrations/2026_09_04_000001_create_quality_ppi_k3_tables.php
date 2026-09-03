<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPI (Pencegahan & Pengendalian Infeksi) dan K3RS — 34 kapabilitas yang
 * ditemukan salah tempat di domain C ("SDM/Kepegawaian") saat konteks hr
 * dibangun: audit_bundle_iadp/ido/isk/plabsi/vap, audit_cuci_tangan_medis,
 * audit_kepatuhan_apd, dst. (audit PPI), dan bagian_tubuh_k3rs/
 * dampak_cidera_k3rs/dst. (dimensi insiden K3) — konseptual manajemen
 * mutu & keselamatan, bukan kepegawaian. Dipindah ke sini.
 *
 * ppi_audits generik lewat kolom audit_type — 17 kode Khanza masing-
 * masing jadi menu sendiri, di sini digabung jadi satu tabel dengan
 * kategori, sama seperti pola environmental_measurements di konteks
 * asset. k3_incidents sebaliknya SATU insiden dengan banyak dimensi
 * (bagian_tubuh_k3rs, dampak_cidera_k3rs, jenis_cidera_k3rs, dst. yang di
 * Khanza masing-masing jadi menu referensi terpisah) — di sini jadi
 * kolom-kolom pada satu baris kejadian, bukan tabel referensi terpisah.
 */
return new class extends Migration
{
    private const S = 'quality';

    public function up(): void
    {
        Schema::create(self::S . '.ppi_audits', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('audit_type', 40)->comment('bundle-iadp/ido/isk/plabsi/vap, cuci-tangan-medis, kepatuhan-apd, dst.');
            $table->date('audited_on');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');

            $table->decimal('compliance_rate', 5, 2)->nullable()->comment('Persentase kepatuhan, null untuk audit yang tidak berbentuk skor');
            $table->text('findings');
            $table->text('corrective_action')->nullable();

            $table->unsignedBigInteger('auditor_id')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index(['audit_type', 'audited_on']);
        });

        DB::statement("ALTER TABLE " . self::S . ".ppi_audits ADD CONSTRAINT ppi_audits_type_check
            CHECK (audit_type IN (
                'bundle-iadp','bundle-ido','bundle-isk','bundle-plabsi','bundle-vap',
                'cuci-tangan-medis','fasilitas-apd','fasilitas-kebersihan-tangan','kamar-jenazah',
                'kepatuhan-apd','pembuangan-benda-tajam','pembuangan-limbah',
                'pembuangan-limbah-cair-infeksius','penanganan-darah','penempatan-pasien',
                'pengelolaan-linen-kotor','sterilisasi-alat'
            ))");
        DB::statement("ALTER TABLE " . self::S . ".ppi_audits ADD CONSTRAINT ppi_audits_compliance_rate_check
            CHECK (compliance_rate IS NULL OR (compliance_rate >= 0 AND compliance_rate <= 100))");

        Schema::create(self::S . '.k3_incidents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('incident_number', 24)->unique();
            $table->unsignedBigInteger('employee_id')->nullable()->comment('ID pegawai hr, referensi longgar — korban belum tentu pegawai tercatat (mis. tenaga alih daya)');

            $table->timestampTz('occurred_at');
            $table->string('location', 150);
            $table->string('body_part', 100)->comment('Bagian tubuh terdampak');
            $table->string('injury_impact', 100)->comment('Dampak cidera');
            $table->string('injury_type', 100)->comment('Jenis cidera');
            $table->string('job_type', 100)->nullable()->comment('Jenis pekerjaan korban saat kejadian');
            $table->string('cause', 150)->comment('Penyebab kecelakaan');

            $table->text('description');
            $table->string('status', 20)->default('dilaporkan');
            $table->unsignedBigInteger('reported_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('corrective_action')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE " . self::S . ".k3_incidents ADD CONSTRAINT k3_incidents_status_check
            CHECK (status IN ('dilaporkan','ditinjau','ditutup'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.k3_incidents');
        Schema::dropIfExists(self::S . '.ppi_audits');
    }
};
