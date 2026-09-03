<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat kepegawaian: jabatan, gaji, pendidikan, catatan (penghargaan/surat
 * peringatan), dan penilaian SKP. Menggenapi domain C Khanza — dari 24 kode
 * genuinely-kepegawaian (58 dikurangi 34 audit PPI/K3 yang sudah dipindah ke
 * quality), Wave 1 sebelumnya baru membangun 3 (pegawai_user, pengajuan_cuti,
 * presensi_harian).
 *
 * Empat tabel pertama digerbangi permission 'pegawai_user' yang sudah ada —
 * sama seperti pcra_icra_pengkajian_risiko_prakonstruksi jadi gerbang tunggal
 * untuk 10 kapabilitas ICRA granular Khanza, di sini pegawai_user jadi
 * gerbang tunggal untuk seluruh facet data pegawai (bukan pintu masuk
 * terpisah per riwayat_jabatan/riwayat_naik_gaji/riwayat_pendidikan/
 * riwayat_penghargaan/riwayat_surat_peringatan). performance_appraisals
 * (SKP) sengaja diberi gerbangnya sendiri, skp_penilaian — penilaian
 * kinerja punya kebutuhan pembatasan akses yang lebih ketat dari sekadar
 * "boleh lihat data pegawai" (idealnya cuma atasan langsung/HR senior).
 *
 * skp_kategori_penilaian, skp_kriteria_penilaian, skp_rekapitulasi_penilaian
 * (3 kode referensi/rekap Khanza terpisah untuk SKP) sengaja tidak jadi tabel
 * referensi sendiri — dikoleps jadi satu skor + predikat pada satu baris
 * performance_appraisals, sama seperti pola pengkajian_risiko_prakonstruksi.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        $this->createPositionHistory();
        $this->createSalaryHistory();
        $this->createEducations();
        $this->createEmployeeRecords();
        $this->createPerformanceAppraisals();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.performance_appraisals');
        Schema::dropIfExists(self::S . '.employee_records');
        Schema::dropIfExists(self::S . '.employee_educations');
        Schema::dropIfExists(self::S . '.employee_salary_history');
        Schema::dropIfExists(self::S . '.employee_position_history');
    }

    /**
     * Satu baris per periode jabatan. end_date null berarti jabatan yang
     * sedang berjalan — hanya boleh satu baris terbuka per pegawai, ditutup
     * otomatis oleh EmployeeHistoryService saat baris baru dicatat, bukan
     * lewat CHECK constraint (PostgreSQL tidak punya "unique where end_date
     * is null" partial constraint yang portable lewat Blueprint biasa, dan
     * penutupan butuh tanggal end_date yang dihitung, bukan sekadar dicegah).
     */
    private function createPositionHistory(): void
    {
        Schema::create(self::S . '.employee_position_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees')->cascadeOnDelete();
            $table->string('position', 100);
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');

            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->string('sk_number', 60)->nullable()->comment('Nomor SK mutasi/promosi');
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'effective_date']);
        });

        DB::statement('ALTER TABLE ' . self::S . '.employee_position_history ADD CONSTRAINT employee_position_history_date_order_check
            CHECK (end_date IS NULL OR end_date >= effective_date)');
    }

    /**
     * Append-only dengan sengaja — tidak ada method update pada modelnya.
     * Riwayat gaji adalah jejak audit; koreksi berarti baris baru dengan
     * catatan, bukan menimpa baris lama.
     */
    private function createSalaryHistory(): void
    {
        Schema::create(self::S . '.employee_salary_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees')->cascadeOnDelete();
            $table->date('effective_date');
            $table->decimal('base_salary', 14, 2);
            $table->string('sk_number', 60)->nullable();
            $table->text('note')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'effective_date']);
        });

        DB::statement('ALTER TABLE ' . self::S . '.employee_salary_history ADD CONSTRAINT employee_salary_history_positive_check
            CHECK (base_salary > 0)');
    }

    private function createEducations(): void
    {
        Schema::create(self::S . '.employee_educations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees')->cascadeOnDelete();
            $table->string('education_level', 20);
            $table->string('institution_name', 150);
            $table->string('major', 100)->nullable();
            $table->smallInteger('graduation_year');
            $table->string('certificate_number', 60)->nullable();

            $table->timestampsTz();

            $table->index('employee_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".employee_educations ADD CONSTRAINT employee_educations_level_check
            CHECK (education_level IN ('sd','smp','sma','d3','d4','s1','profesi','s2','spesialis','s3'))");
    }

    /**
     * Tabel kategori-generik: penghargaan dan surat peringatan sama-sama
     * "catatan kepegawaian bertanggal dengan judul, deskripsi, dan nomor
     * dokumen" — dua kode Khanza (riwayat_penghargaan, riwayat_surat_
     * peringatan) untuk satu bentuk data, sama seperti 6 kategori kesling
     * jadi satu asset.environmental_measurements berkolom category.
     */
    private function createEmployeeRecords(): void
    {
        Schema::create(self::S . '.employee_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees')->cascadeOnDelete();
            $table->string('record_type', 20)->comment('penghargaan, peringatan');
            $table->date('record_date');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('document_number', 60)->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'record_type']);
        });

        DB::statement("ALTER TABLE " . self::S . ".employee_records ADD CONSTRAINT employee_records_type_check
            CHECK (record_type IN ('penghargaan','peringatan'))");
    }

    /**
     * SKP (Sasaran Kinerja Pegawai) — status draft memuat skor sementara,
     * final mengunci penilaian (tidak boleh diubah lagi), sama pola
     * kunci-setelah-selesai dengan IKP/K3 (dilaporkan-ditinjau-ditutup),
     * hanya dua status karena SKP bukan alur eskalasi bertingkat.
     */
    private function createPerformanceAppraisals(): void
    {
        Schema::create(self::S . '.performance_appraisals', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees')->cascadeOnDelete();
            $table->string('period', 20)->comment('mis. 2026 atau 2026-S1');
            $table->decimal('score', 5, 2);
            $table->text('note')->nullable();

            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('assessed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('finalized_at')->nullable();

            $table->timestampsTz();

            $table->unique(['employee_id', 'period']);
        });

        DB::statement("ALTER TABLE " . self::S . ".performance_appraisals ADD CONSTRAINT performance_appraisals_score_check
            CHECK (score >= 0 AND score <= 100)");
        DB::statement("ALTER TABLE " . self::S . ".performance_appraisals ADD CONSTRAINT performance_appraisals_status_check
            CHECK (status IN ('draft','final'))");
    }
};
