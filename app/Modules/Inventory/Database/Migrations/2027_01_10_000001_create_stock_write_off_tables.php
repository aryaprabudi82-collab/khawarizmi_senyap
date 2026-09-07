<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penghapusan stok rusak & kedaluwarsa (domain N item C).
 *
 * Menaungi utd_medis_rusak dan utd_penunjang_rusak — dua dari empat
 * kode BHP UTD. Dua sisanya (pengambilan_utd2 dan
 * pengambilan_penunjang_utd2) SUDAH TERTUTUP inventory.requisitions,
 * yang menerima unit mana pun sebagai peminta; UTD salah satunya, dan
 * membuatkannya mekanisme pengambilan tersendiri hanya melahirkan
 * gudang kedua yang saldonya bisa berbeda.
 *
 * DIBANGUN DI INVENTORY, BUKAN DI BLOOD, meski katalog menandai kedua
 * kodenya context=blood. Alasannya sama dengan domain I item E yang
 * dibangun di finance: yang menentukan rumah sebuah mekanisme adalah
 * apa yang dikerjakannya, bukan siapa yang kebetulan memakainya lebih
 * dulu. Membuang barang habis pakai adalah urusan gudang, dan UTD cuma
 * satu unit di antara banyak unit yang melakukannya.
 *
 * DAN INI BUKAN LUBANG KHAS UTD — ini lubang seluruh konteks inventory,
 * dengan dua lapis.
 *
 * Lapis pertama: tidak ada satu pun operasi yang mencatat barang
 * dibuang. Yang ada cuma issue() untuk pengeluaran biasa, sehingga
 * barang rusak tak terbedakan dari yang dipakai melayani pasien, dan
 * pertanyaan "berapa banyak yang kita buang tahun ini" tidak bisa
 * dijawab.
 *
 * Lapis kedua, yang baru ketahuan saat diperiksa langsung ke
 * pg_constraint: kolom source SELAMA INI TIDAK PUNYA CHECK SAMA SEKALI.
 * Kosakatanya cuma ditulis pada komentar kolom — pembelian, hibah,
 * opname, retur-suplier, permintaan-unit — dan tidak ada yang
 * menegakkannya, sehingga string apa pun bisa masuk dan laporan yang
 * mengelompokkan menurut source diam-diam bergantung pada kedisiplinan
 * pemanggilnya. Migrasi ini memasang CHECK itu untuk pertama kali,
 * sekaligus menambahkan nilai yang memang dibutuhkan.
 *
 * RUSAK DAN KEDALUWARSA DIBEDAKAN SEJAK AWAL. Keduanya sama-sama
 * dibuang, tapi yang pertama menunjuk masalah penyimpanan atau
 * penanganan dan yang kedua menunjuk masalah perencanaan pembelian.
 * Menyatukannya membuat pertanyaan "kenapa kita banyak membuang" tidak
 * bisa dijawab — dan itu pertanyaan yang jawabannya menentukan tindakan
 * yang sama sekali berbeda.
 *
 * PENGHAPUSAN MENUNTUT PERSETUJUAN. Membuang barang adalah peristiwa
 * keuangan: nilainya hilang dari neraca. Yang mencatat boleh petugas
 * gudang, tapi yang menyetujui harus orang lain — pemisahan itu yang
 * menahan stok hilang dicatat sebagai "rusak".
 *
 * NILAI DIBEKUKAN SAAT PENGHAPUSAN, tidak dibaca ulang dari harga
 * terakhir: barang yang dibuang tahun lalu hilang senilai harga tahun
 * lalu, dan membacanya ulang dari harga hari ini membuat kerugian masa
 * lalu ikut berubah setiap kali harga naik.
 */
return new class extends Migration
{
    private const S = 'inventory';

    private const SUMBER_BARU = [
        'pembelian', 'hibah', 'opname', 'retur-suplier', 'permintaan-unit',
        'rusak', 'kedaluwarsa', 'hilang',
    ];

    public function up(): void
    {
        $this->gantiSourceCheck(self::SUMBER_BARU);
        $this->createWriteOffs();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.stock_write_off_items');
        Schema::dropIfExists(self::S.'.stock_write_offs');

        // Dibatalkan berarti KEMBALI TANPA CHECK, bukan kembali ke
        // kosakata lama — kolomnya memang tidak pernah punya CHECK
        // sebelum migrasi ini. Memasang SUMBER_LAMA di sini akan
        // menyisakan aturan yang tidak pernah ada, dan itu bukan
        // pembatalan melainkan perubahan lain.
        DB::statement('ALTER TABLE inventory.stock_movements
            DROP CONSTRAINT IF EXISTS stock_movements_source_check');
    }

    private function createWriteOffs(): void
    {
        Schema::create(self::S.'.stock_write_offs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('write_off_number', 24)->unique();
            $table->date('write_off_date');

            $table->string('reason', 20)->comment('rusak, kedaluwarsa, hilang');
            $table->text('reason_detail');

            $table->unsignedBigInteger('unit_id')->nullable()
                ->comment('Unit yang menyimpan barangnya; referensi longgar ke organization');
            $table->string('unit_name', 150)->nullable();

            $table->string('disposal_method', 40)->nullable()
                ->comment('Bagaimana barangnya dimusnahkan — insinerator, pihak ketiga, dikembalikan');
            $table->string('decision_number', 60)->nullable();

            // Dibekukan saat penghapusan — lihat catatan kelas.
            $table->decimal('total_value', 16, 2)->default(0);

            $table->string('status', 20)->default('diajukan')
                ->comment('diajukan, disetujui, ditolak');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 150);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 150)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['status', 'write_off_date']);
            $table->index(['reason', 'write_off_date']);
        });

        DB::statement('ALTER TABLE '.self::S.".stock_write_offs
            ADD CONSTRAINT stock_write_offs_reason_check
            CHECK (reason IN ('rusak','kedaluwarsa','hilang'))");

        DB::statement('ALTER TABLE '.self::S.".stock_write_offs
            ADD CONSTRAINT stock_write_offs_status_check
            CHECK (status IN ('diajukan','disetujui','ditolak'))");

        // Yang menyetujui harus disebut, dan yang menolak harus beralasan.
        DB::statement('ALTER TABLE '.self::S.".stock_write_offs
            ADD CONSTRAINT stock_write_offs_decision_check
            CHECK ((status <> 'disetujui'
                    OR (approved_by_name IS NOT NULL AND decided_at IS NOT NULL))
                   AND (status <> 'ditolak'
                        OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''
                            AND decided_at IS NOT NULL)))");

        Schema::create(self::S.'.stock_write_off_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('write_off_id')->constrained(self::S.'.stock_write_offs');
            $table->foreignId('item_id')->constrained(self::S.'.items');

            $table->string('item_name', 200)->comment('Disalin saat pengajuan');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2)->default(0)
                ->comment('Harga pada saat penghapusan, dibekukan');
            $table->decimal('amount', 16, 2)->default(0);

            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestampsTz();

            $table->index('write_off_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.stock_write_off_items
            ADD CONSTRAINT stock_write_off_items_quantity_check
            CHECK (quantity > 0)');

        // Barang kedaluwarsa yang tidak menyebut tanggal kedaluwarsanya
        // tidak bisa ditinjau: pertanyaan berikutnya selalu "kapan
        // kedaluwarsanya, dan sejak kapan ia menganggur di gudang".
        DB::statement('CREATE UNIQUE INDEX stock_write_off_items_unique
            ON '.self::S.".stock_write_off_items (write_off_id, item_id, COALESCE(batch_number, ''))");
    }

    /**
     * Memasang CHECK pada kolom source.
     *
     * Constraint lama dicari lewat pg_constraint lebih dulu — pelajaran
     * yang sudah tercatat sejak domain M item G: menebak namanya lalu
     * memakai DROP IF EXISTS akan "berhasil" tanpa menghapus apa pun.
     * Di sini pencariannya memang tidak menemukan apa-apa (kolomnya
     * belum pernah dibatasi), tapi jalurnya tetap sama supaya migrasi
     * ini aman dijalankan pada basis data yang sudah punya CHECK-nya.
     *
     * @param  array<int, string>  $nilai
     */
    private function gantiSourceCheck(array $nilai): void
    {
        foreach (DB::select("SELECT conname FROM pg_constraint
            WHERE conrelid = 'inventory.stock_movements'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%source%'") as $constraint) {
            DB::statement("ALTER TABLE inventory.stock_movements DROP CONSTRAINT {$constraint->conname}");
        }

        DB::statement("ALTER TABLE inventory.stock_movements
            ADD CONSTRAINT stock_movements_source_check
            CHECK (source IN ('".implode("','", $nilai)."'))");
    }
};
