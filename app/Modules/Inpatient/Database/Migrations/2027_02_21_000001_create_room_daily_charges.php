<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya harian tambahan per kamar (Khanza `biaya_harian`, domain U).
 *
 * Selain tarif kamar itu sendiri, tiap kamar lazim membawa biaya harian
 * lain yang ikut tertagih tiap hari rawat: asuhan keperawatan, oksigen
 * sentral, laundry, listrik ruang. Khanza mencatatnya di `biaya_harian`
 * berkunci (kd_kamar, nama_biaya).
 *
 * NAMA BIAYA TIDAK BOLEH JADI KUNCI. Dengan `nama_biaya` sebagai bagian
 * primary key, memperbaiki ejaan "Asuhan Keperawtan" jadi "Asuhan
 * Keperawatan" bukan mengoreksi baris — ia MEMBUAT baris kedua, dan yang
 * salah eja tinggal di sana ikut tertagih. Rekap biaya harian lalu
 * menampilkan dua pos untuk satu hal, dan yang menjumlahkannya per nama
 * mendapat angka yang benar totalnya tapi salah rinciannya.
 *
 * Bentuk cacat yang sama sudah ditemui pada `setting.nama_instansi`
 * (identitas dikunci namanya sendiri) dan `set_pjlab` (kunci gabungan dari
 * isi yang berubah-ubah). Ketiganya satu kesalahan: memakai nilai yang
 * boleh berubah sebagai identitas.
 *
 * BERLAKU SEJAK TANGGAL, DAN DIBEKUKAN SAAT MENAGIH. Biaya harian ikut
 * masuk tagihan tiap hari rawat; kalau besarannya dibaca ulang saat
 * tagihan dicetak, pasien yang dirawat sebelum kenaikan akan ditagih
 * dengan tarif sesudahnya — dan seluruh tagihan lampau berubah sendiri
 * setiap kali angkanya disesuaikan. Aturan yang sama dipakai HPP penjualan
 * (domain S) dan tarif embalase (domain U item A).
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        Schema::create(self::S.'.room_daily_charges', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('room_id')->constrained(self::S.'.rooms')->cascadeOnDelete();

            // Kode, bukan nama, yang jadi penanda. Nama boleh diperbaiki
            // ejaannya tanpa melahirkan pos biaya kedua.
            $table->string('code', 20);
            $table->string('name', 80);

            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->date('effective_from');

            /*
             * Kosong berarti MASIH BERLAKU. Biaya yang dihentikan ditutup
             * dengan tanggal, tidak dihapus: hari rawat yang sudah lewat
             * tetap harus bisa dihitung ulang dengan biaya yang memang
             * berlaku saat itu.
             */
            $table->date('effective_until')->nullable();

            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['room_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE '.self::S.'.room_daily_charges
            ADD CONSTRAINT room_daily_charges_amount_check CHECK (amount >= 0)');

        DB::statement('ALTER TABLE '.self::S.'.room_daily_charges
            ADD CONSTRAINT room_daily_charges_period_check
            CHECK (effective_until IS NULL OR effective_until >= effective_from)');

        // Satu pos biaya berjalan per kamar per kode.
        DB::statement('CREATE UNIQUE INDEX room_daily_charges_berjalan_unique
            ON '.self::S.'.room_daily_charges (room_id, code) WHERE effective_until IS NULL');

        /*
         * Diterbitkan untuk billing, yang sudah membaca v_room_charge.
         * Satu baris per kamar per hari per pos biaya, sejalan bentuknya
         * dengan v_room_charge supaya keduanya bisa digabung tanpa
         * penyesuaian bentuk di sisi billing.
         */
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_room_daily_charge AS
            SELECT c.id,
                   c.room_id,
                   r.room_number,
                   r.room_class,
                   c.code,
                   c.name,
                   c.amount,
                   c.quantity,
                   (c.amount * c.quantity)::numeric(14,2) AS total,
                   c.effective_from,
                   c.effective_until
              FROM '.self::S.'.room_daily_charges c
              JOIN '.self::S.'.rooms r ON r.id = c.room_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_room_daily_charge');
        Schema::dropIfExists(self::S.'.room_daily_charges');
    }
};
