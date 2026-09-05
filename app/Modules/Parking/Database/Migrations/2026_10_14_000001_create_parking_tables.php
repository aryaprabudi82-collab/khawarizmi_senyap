<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks parking: parkir kendaraan pengunjung & pegawai (domain H Khanza,
 * 3 kode: parkir_jenis, parkir_barcode, parkir_in).
 *
 * Bentuk tabelnya diturunkan dari maksud skema Khanza sendiri, bukan dari
 * daftar menunya. Khanza tidak punya menu "parkir keluar", tapi tabel
 * `parkir`-nya sudah menyimpan tgl_keluar/jam_keluar/lama_parkir/ttl_biaya
 * — jadi satu baris = satu sesi parkir yang diisi saat masuk lalu
 * dilengkapi saat keluar, bukan dua entitas terpisah. Yang benar-benar
 * didelegasikan Khanza ke pihak luar cuma rekap keluarnya
 * (duta_parkir_rekap_keluar, domain L, context=integration, vendor Duta
 * Parking); SIMRS Mandiri tidak terikat vendor itu, jadi sisi keluar dan
 * perhitungan biaya dilayani sendiri di sini.
 *
 * parkir_barcode di Khanza cuma dua kolom (kode_barcode -> nomer_kartu),
 * tanpa timestamp maupun kendaraan: itu stok kartu fisik yang didaftarkan
 * admin sekali, bukan transaksi. Karena itu ia jadi tabel master di sini
 * dan berbagi layar (serta gerbang parkir_jenis) dengan jenis/tarif,
 * bukan menempel ke layar petugas gerbang.
 */
return new class extends Migration
{
    private const S = 'parking';

    public function up(): void
    {
        Schema::create(self::S . '.rates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 8)->unique()->comment('Padanan kd_parkir Khanza');
            $table->string('name', 60)->comment('mis. Motor, Mobil, Bus');
            $table->unsignedInteger('fee')->comment('Rupiah penuh; per jam atau per hari tergantung basis');
            $table->string('basis', 8)->comment("jam atau harian — padanan enum('Jam','Harian') Khanza");
            $table->unsignedInteger('free_minutes')->default(0)
                ->comment('Toleransi menit pertama yang tidak ditagih, mis. antar-jemput pasien');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ' . self::S . ".rates ADD CONSTRAINT rates_basis_check
            CHECK (basis IN ('jam','harian'))");

        // Stok kartu barcode fisik. Satu kartu = satu barcode = satu nomor
        // kartu pendek yang dibacakan petugas. Kartunya dipakai berulang
        // lintas sesi, jadi tidak menyimpan kendaraan/waktu apa pun.
        Schema::create(self::S . '.barcode_cards', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('barcode', 32)->unique()->comment('Padanan kode_barcode Khanza');
            $table->string('card_number', 8)->unique()->comment('Padanan nomer_kartu Khanza');
            $table->boolean('is_active')->default(true)->comment('false = kartu hilang/rusak, tidak boleh dipakai lagi');

            $table->timestampsTz();
        });

        // Satu baris = satu sesi parkir. exited_at null berarti kendaraan
        // masih di dalam — itu juga yang menjaga satu kartu tidak dipakai
        // dua kendaraan sekaligus (unique parsial di bawah).
        Schema::create(self::S . '.sessions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('rate_id')->constrained(self::S . '.rates');
            $table->foreignId('barcode_card_id')->nullable()->constrained(self::S . '.barcode_cards')
                ->comment('Boleh kosong kalau kartu habis dan petugas mencatat manual');
            $table->string('vehicle_number', 15)->comment('Padanan no_kendaraan Khanza');

            $table->timestampTz('entered_at');
            $table->timestampTz('exited_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable()->comment('Diisi saat keluar');
            $table->unsignedInteger('total_fee')->nullable()->comment('Rupiah penuh, diisi saat keluar');

            // Referensi longgar ke platform.users, tanpa FK — pola yang sama
            // seperti blood.unit_status_logs.changed_by: FK lintas schema akan
            // mengikat konteks ini ke platform dan melanggar batasnya.
            $table->unsignedBigInteger('entered_by')->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('exited_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->string('notes', 255)->nullable();

            $table->timestampsTz();

            $table->index('vehicle_number');
            $table->index('entered_at');
        });

        // Satu kartu hanya boleh menempel pada satu sesi yang masih terbuka.
        DB::statement('CREATE UNIQUE INDEX sessions_kartu_terbuka_unique ON ' . self::S . '.sessions (barcode_card_id)
            WHERE exited_at IS NULL AND barcode_card_id IS NOT NULL');

        // Begitu pula satu kendaraan tidak boleh punya dua sesi terbuka.
        DB::statement('CREATE UNIQUE INDEX sessions_kendaraan_terbuka_unique ON ' . self::S . '.sessions (vehicle_number)
            WHERE exited_at IS NULL');

        DB::statement('ALTER TABLE ' . self::S . '.sessions ADD CONSTRAINT sessions_keluar_setelah_masuk_check
            CHECK (exited_at IS NULL OR exited_at >= entered_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.sessions');
        Schema::dropIfExists(self::S . '.barcode_cards');
        Schema::dropIfExists(self::S . '.rates');
    }
};
