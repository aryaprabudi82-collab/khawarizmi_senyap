<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * deposit_pasien dan perkiraan_biaya_ranap (Khanza domain A, kelas DlgDeposit
 * dan DlgPerkiraanBiayaRanap, keduanya berpaket Java "keuangan") — tercatat
 * context=encounter di katalog (domain A Khanza mencampur registrasi dengan
 * menu keuangan dalam satu grup), sungguhan dibangun di finance sesuai
 * paketnya, pola sama dengan tindakan_ranap/diet_pasien yang direlokasi ke
 * inpatient.
 *
 * deposit_pasien: penerimaan titipan uang muka pasien, dijurnal (Kas debit,
 * Titipan Deposit Pasien/utang kredit) — bukan pendapatan sampai benar-benar
 * dipakai. "Dipakai"-nya (status 'terpakai') SENGAJA belum diimplementasikan
 * di sini: itu kelas Khanza terpisah, DlgPengembalianDepositPasien /
 * pengembalian_deposit_pasien, domain K, menyusul saat domain itu digarap.
 * Kolom status tetap disiapkan menerima nilai itu supaya migrasi domain K
 * nanti tidak perlu ALTER CHECK lagi.
 *
 * perkiraan_biaya_ranap: bukan uang sungguhan, cuma kutipan/estimasi
 * (tarif kamar rata-rata per kelas x perkiraan lama rawat + perkiraan biaya
 * lain-lain) untuk dikomunikasikan ke pasien sebelum admisi — tidak
 * menghasilkan jurnal apa pun. Snapshot daily_rate disimpan di baris supaya
 * perubahan tarif kamar berikutnya tidak mengubah makna estimasi yang sudah
 * diberikan ke pasien, pola sama dengan orders.order_items menyalin rentang
 * rujukan/harga saat order dibuat.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.chart_of_accounts DROP CONSTRAINT accounts_type_check');
        DB::statement("ALTER TABLE " . self::S . ".chart_of_accounts ADD CONSTRAINT accounts_type_check
            CHECK (type IN ('kas','piutang','pendapatan','beban','utang'))");

        $this->createDeposits();
        $this->createCostEstimates();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.inpatient_cost_estimates');
        Schema::dropIfExists(self::S . '.deposits');

        DB::statement('ALTER TABLE ' . self::S . '.chart_of_accounts DROP CONSTRAINT accounts_type_check');
        DB::statement("ALTER TABLE " . self::S . ".chart_of_accounts ADD CONSTRAINT accounts_type_check
            CHECK (type IN ('kas','piutang','pendapatan','beban'))");
    }

    private function createDeposits(): void
    {
        Schema::create(self::S . '.deposits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('deposit_number', 24)->unique();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->string('payer_name', 150);

            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('aktif')->comment('aktif, terpakai (ditulis pengembalian_deposit_pasien, domain K)');
            $table->string('note', 255)->nullable();

            $table->timestampTz('deposited_at');
            $table->unsignedBigInteger('deposited_by')->nullable();
            $table->string('deposited_by_name', 150)->nullable();

            $table->timestampTz('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();

            $table->timestampsTz();

            $table->index(['registration_id']);
            $table->index(['status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".deposits ADD CONSTRAINT deposits_amount_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE " . self::S . ".deposits ADD CONSTRAINT deposits_status_check
            CHECK (status IN ('aktif','terpakai'))");
    }

    private function createCostEstimates(): void
    {
        Schema::create(self::S . '.inpatient_cost_estimates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('estimate_number', 24)->unique();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->string('payer_name', 150);

            $table->string('room_class', 20)->comment('vip, kelas-1, kelas-2, kelas-3, icu, isolasi');
            $table->unsignedInteger('estimated_days');
            $table->decimal('daily_rate', 12, 2)->comment('Snapshot inpatient.v_room_class_rate saat estimasi dibuat');
            $table->decimal('other_charges', 14, 2)->default(0)->comment('Perkiraan biaya lain-lain (obat/tindakan/dll), angka tunggal Wave 1');
            $table->decimal('total_estimate', 14, 2);
            $table->string('note', 255)->nullable();

            $table->timestampTz('prepared_at');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->string('prepared_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->index(['registration_id']);
        });

        DB::statement("ALTER TABLE " . self::S . ".inpatient_cost_estimates ADD CONSTRAINT cost_estimates_room_class_check
            CHECK (room_class IN ('vip','kelas-1','kelas-2','kelas-3','icu','isolasi'))");
        DB::statement("ALTER TABLE " . self::S . ".inpatient_cost_estimates ADD CONSTRAINT cost_estimates_days_check
            CHECK (estimated_days > 0)");
        DB::statement("ALTER TABLE " . self::S . ".inpatient_cost_estimates ADD CONSTRAINT cost_estimates_charges_check
            CHECK (other_charges >= 0 AND daily_rate >= 0 AND total_estimate >= 0)");
    }
};
