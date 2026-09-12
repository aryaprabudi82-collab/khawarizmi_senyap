<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak penjamin berperiode — Modul A butir 1.8.
 *
 * APA YANG SUDAH ADA DAN APA YANG BELUM. `catalog.payers` menyimpan
 * IDENTITAS penjamin: kode, nama, jenis (umum/bpjs/asuransi/perusahaan),
 * perusahaan, telepon. Itu tetap di sana dan TIDAK dipindahkan — identitas
 * penjamin dipakai pendaftaran dan billing setiap hari, dan memindahkannya
 * berarti memutus keduanya demi kerapian.
 *
 * Yang belum ada sama sekali adalah KONTRAKNYA: sejak kapan sampai kapan
 * kerja samanya berlaku, berapa plafon per episode, berapa bagian yang
 * ditanggung pasien, layanan apa yang dikecualikan, berapa lama tagihan
 * boleh diajukan. Semua itu keputusan yang berubah tiap perpanjangan
 * kontrak, dan menyimpannya sebagai kolom pada penjamin berarti kontrak
 * tahun lalu tertimpa kontrak tahun ini — lalu tagihan lama tidak bisa
 * dijelaskan lagi.
 *
 * BERPERIODE, DAN ITU INTINYA. Tagihan yang terbit Maret harus dinilai
 * dengan kontrak yang berlaku Maret, bukan kontrak yang berlaku saat
 * laporannya dibuka. Pola yang sama sudah dipakai catalog.tariffs dan
 * terbukti benar.
 *
 * COST-SHARING DISIMPAN SEBAGAI DUA CARA, BUKAN SATU. Ada kontrak yang
 * menetapkan persentase (pasien menanggung 10%), ada yang menetapkan
 * nominal tetap per episode, dan ada yang keduanya dengan batas atas.
 * Memaksakan satu bentuk membuat yang lain harus diakali di kode
 * pemanggil — dan akal-akalan itu akan berbeda di tiap tempat.
 *
 * PENGECUALIAN LAYANAN JADI TABEL, BUKAN TEKS. Daftar layanan yang tidak
 * ditanggung harus bisa DIPERIKSA MESIN saat charge dibentuk; kalau ia
 * teks bebas, satu-satunya yang bisa memeriksanya adalah manusia yang
 * membaca kontrak — dan itu tidak terjadi pada 2.000 pasien per hari.
 */
return new class extends Migration
{
    private const S = 'keuangan_master';

    /** Dasar perhitungan bagian pasien. */
    private const DASAR_COST_SHARING = ['tidak-ada', 'persentase', 'nominal', 'persentase-berbatas'];

    public function up(): void
    {
        Schema::create(self::S.'.payer_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payer_id')
                ->comment('catalog.payers, referensi longgar lintas konteks');

            $table->string('contract_number', 60);
            $table->string('name', 200)->comment('Mis. "PKS BPJS 2026" atau "Corporate XYZ Gold"');

            $table->date('valid_from');
            $table->date('valid_until')->nullable()->comment('NULL berarti masih berjalan');

            /*
             * PLAFON per episode perawatan. NULL berarti TIDAK ADA batas —
             * dan itu sengaja dibedakan dari nol, yang berarti penjamin
             * tidak menanggung apa pun. Menyamakan keduanya membuat
             * kontrak tanpa plafon dibaca sebagai kontrak tanpa jaminan.
             */
            $table->decimal('plafon_per_episode', 19, 2)->nullable();
            $table->decimal('plafon_per_tahun', 19, 2)->nullable();

            /* Cost-sharing: bagian yang ditanggung PASIEN, bukan penjamin. */
            $table->string('cost_sharing_basis', 25)->default('tidak-ada');
            $table->decimal('cost_sharing_percent', 5, 2)->nullable()
                ->comment('Persen yang ditanggung pasien, 0-100');
            $table->decimal('cost_sharing_amount', 19, 2)->nullable()
                ->comment('Nominal tetap per episode');
            $table->decimal('cost_sharing_cap', 19, 2)->nullable()
                ->comment('Batas atas bagian pasien, untuk basis persentase-berbatas');

            /*
             * BATAS WAKTU PENGAJUAN. Tagihan yang lewat tenggat ditolak
             * penjamin, dan penolakan itu baru ketahuan berbulan-bulan
             * kemudian saat uangnya tidak kunjung masuk. Disimpan supaya
             * sistem bisa memperingatkan SEBELUM tenggatnya lewat.
             */
            $table->unsignedSmallInteger('batas_hari_pengajuan')->nullable();
            $table->unsignedSmallInteger('batas_hari_pembayaran')->nullable()
                ->comment('Janji penjamin membayar setelah klaim disetujui — dasar proyeksi kas');

            $table->boolean('butuh_penjaminan_awal')->default(false)
                ->comment('Layanan elektif wajib dijamin lebih dulu sebelum dikerjakan');

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['payer_id', 'contract_number']);
            $table->index(['payer_id', 'valid_from']);
        });

        DB::statement('ALTER TABLE '.self::S.'.payer_contracts ADD CONSTRAINT payer_contracts_basis_check
            CHECK (cost_sharing_basis IN (\''.implode("','", self::DASAR_COST_SHARING).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.payer_contracts ADD CONSTRAINT payer_contracts_period_check
            CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        DB::statement('ALTER TABLE '.self::S.'.payer_contracts ADD CONSTRAINT payer_contracts_percent_check
            CHECK (cost_sharing_percent IS NULL OR (cost_sharing_percent >= 0 AND cost_sharing_percent <= 100))');

        /*
         * BASIS MENENTUKAN KOLOM MANA YANG WAJIB TERISI, dan ini
         * ditegakkan basis data — bukan diserahkan ke disiplin pemanggil.
         *
         * Kontrak berbasis persentase tanpa angka persennya, atau berbasis
         * nominal tanpa nominalnya, akan menghitung bagian pasien sebagai
         * NOL. Pasien tidak ditagih apa-apa, tidak ada galat, dan
         * selisihnya baru ketahuan saat rekonsiliasi penjamin.
         */
        DB::statement('ALTER TABLE '.self::S.'.payer_contracts ADD CONSTRAINT payer_contracts_basis_lengkap
            CHECK (
                (cost_sharing_basis = \'tidak-ada\')
                OR (cost_sharing_basis = \'persentase\' AND cost_sharing_percent IS NOT NULL)
                OR (cost_sharing_basis = \'nominal\' AND cost_sharing_amount IS NOT NULL)
                OR (cost_sharing_basis = \'persentase-berbatas\'
                    AND cost_sharing_percent IS NOT NULL AND cost_sharing_cap IS NOT NULL)
            )');

        /*
         * SATU PENJAMIN, SATU KONTRAK BERJALAN. Indeks parsial karena
         * valid_until NULL berarti "masih berjalan", dan di PostgreSQL
         * NULL tidak sama dengan NULL — indeks unik biasa akan membiarkan
         * dua kontrak berjalan bersamaan untuk penjamin yang sama, lalu
         * tidak ada cara menentukan mana yang berlaku.
         */
        DB::statement('CREATE UNIQUE INDEX payer_contracts_berjalan_unique
            ON '.self::S.'.payer_contracts (payer_id)
            WHERE valid_until IS NULL AND is_active = true');

        // ------------------------------------------------ pengecualian layanan

        Schema::create(self::S.'.contract_exclusions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('contract_id')->constrained(self::S.'.payer_contracts')->cascadeOnDelete();

            /*
             * Dikecualikan bisa per ITEM CDM atau per GOLONGAN. Keduanya
             * dibutuhkan: "kosmetik tidak ditanggung" adalah golongan,
             * "kacamata tidak ditanggung" adalah item.
             */
            $table->unsignedBigInteger('charge_item_id')->nullable();
            $table->string('golongan', 20)->nullable();

            $table->string('reason', 255)->comment('Dasar pengecualian menurut kontraknya');
            $table->timestampsTz();

            $table->index('contract_id');
            $table->index('charge_item_id');
        });

        /*
         * Harus menyebut salah satu — pengecualian yang tidak menunjuk apa
         * pun tidak mengecualikan apa pun, dan baris seperti itu cuma
         * membuat daftar pengecualian tampak lebih panjang daripada
         * kenyataannya.
         */
        DB::statement('ALTER TABLE '.self::S.'.contract_exclusions ADD CONSTRAINT exclusions_menyebut_sasaran
            CHECK ((charge_item_id IS NOT NULL) <> (golongan IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.contract_exclusions');
        Schema::dropIfExists(self::S.'.payer_contracts');
    }
};
