<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan & pernyataan pasien (domain P item B).
 *
 * DELAPAN KODE, DAN ENAM DI ANTARANYA TIDAK PUNYA TABEL DI KHANZA.
 *
 * Menelusuri `sik_schema.sql` untuk kedelapan kode ini menghasilkan temuan
 * yang tidak diduga: permohonan privasi, permintaan perlindungan dari
 * kekerasan, permintaan bimbingan rohani, permintaan second opinion, dan
 * pengajuan cuti perawatan SEMUANYA punya menu dan hak akses di Khanza,
 * tapi tidak punya tabel penyimpanan sama sekali (begitu pula surat
 * penolakan resusitasi). Yang ada tabelnya cuma tiga: pernyataan pasien
 * umum, pernyataan memilih DPJP, dan serah terima barang/anggota tubuh.
 *
 * Jadi di sini kita TIDAK sedang menyalin Khanza — kita membangun apa yang
 * menunya janjikan tapi penyimpanannya tidak sediakan. Itu perlu ditulis
 * terang-terangan supaya tidak ada yang mengira bentuk tabel di bawah
 * punya padanan di sana.
 *
 * MENGAPA TETAP DIBANGUN. Kelimanya bukan kelengkapan administratif: ini
 * hak pasien yang harus bisa dibuktikan pemenuhannya. Standar akreditasi
 * HPK (Hak Pasien dan Keluarga) menuntut rumah sakit menghormati
 * kebutuhan privasi, melindungi pasien dari kekerasan, memfasilitasi
 * bimbingan kerohanian, dan memberi jalan bagi pendapat kedua. Semua itu
 * dinilai dari BUKTI, dan bukti yang tidak disimpan di mana pun sama
 * dengan tidak ada.
 *
 * SATU TABEL UNTUK LIMA JENIS, BUKAN LIMA TABEL. Bentuknya memang satu:
 * seseorang meminta sesuatu atas nama pasien, lalu rumah sakit menjawab.
 * Yang berbeda cuma isi permintaannya, dan itu memang isi teks. Pola yang
 * sama sudah dipakai untuk consent_type dan certificate_type.
 *
 * PENOLAKAN WAJIB BERALASAN, PEMENUHAN TIDAK — dan ketaksimetrisan ini
 * ada dasarnya. Permintaan yang DIPENUHI meninggalkan bukti pada
 * perbuatannya sendiri: rohaniwan yang datang, tirai yang dipasang,
 * dokter kedua yang memeriksa. Permintaan yang DITOLAK tidak
 * meninggalkan apa-apa selain catatan ini. Kalau catatan itu boleh
 * kosong, penolakan hak pasien menjadi peristiwa yang tidak berjejak —
 * dan justru itulah peristiwa yang paling perlu berjejak.
 *
 * TANGGAL CUTI HANYA UNTUK CUTI. Ditegakkan CHECK, bukan disiplin
 * pengisi: permohonan privasi yang punya tanggal mulai dan selesai akan
 * terbaca sebagai "privasi berlaku sampai tanggal sekian", padahal
 * kolomnya cuma salah terisi.
 *
 * SERAH TERIMA PUNYA DUA ARAH, DAN ITU YANG MEMBUATNYA BISA
 * DIREKONSILIASI. Khanza mencatat satu arah saja (rumah sakit menyerahkan
 * kepada keluarga). Tabel satu arah tidak pernah bisa menjawab pertanyaan
 * yang sebenarnya diajukan orang: "apakah masih ada barang pasien ini
 * yang dipegang rumah sakit?" — karena penyerahan tidak punya pembanding.
 * Karena itu penitipan ikut dicatat, dan penyerahan boleh menunjuk
 * penitipan yang dilunasinya. Sisa titipan DIHITUNG, tidak disimpan.
 *
 * LABEL WADAH WAJIB UNTUK ANGGOTA TUBUH. Bukan formalitas: wadah
 * jaringan tanpa label tidak bisa dibedakan dari wadah mana pun, dan
 * begitulah jaringan yang salah sampai ke keluarga yang salah. Untuk
 * barang pribadi labelnya boleh kosong — dompet tidak tertukar dengan
 * cara yang sama.
 *
 * KONDISI SAAT SERAH TERIMA WAJIB. Itu satu-satunya pembanding ketika
 * belakangan ada yang menyatakan barangnya rusak atau kurang. Catatan
 * serah terima tanpa kondisi hanya membuktikan ada sesuatu yang
 * berpindah tangan, bukan sesuatu yang mana.
 *
 * PERNYATAAN MEMILIH DPJP TIDAK MENGUBAH DPJP. Penugasan DPJP tercatat di
 * `inpatient.dpjp_history` dan itu keputusan rumah sakit; yang dicatat di
 * sini adalah PILIHAN PASIEN, dan keduanya bisa berbeda — dokter yang
 * diminta bisa saja tidak tersedia. Menulis pilihan pasien ke riwayat
 * penugasan akan membuat catatan itu menyatakan sesuatu yang tidak pernah
 * terjadi. (Aturan batas konteks proyek ini juga melarang correspondence
 * menulis ke schema inpatient — tapi alasan pertamanya yang menentukan.)
 */
return new class extends Migration
{
    private const S = 'correspondence';

    /** Sama persis dengan daftar pada persetujuan — satu kosakata, bukan dua. */
    private const HUBUNGAN = [
        'diri-sendiri', 'suami', 'istri', 'ayah', 'ibu',
        'anak', 'saudara-kandung', 'pengampu', 'lainnya',
    ];

    private const JENIS_PERMINTAAN = [
        'binrohtal',              // bimbingan rohani & mental
        'perlindungan-kekerasan', // perlindungan dari kekerasan
        'privasi',                // permohonan privasi
        'second-opinion',         // permintaan pendapat kedua
        'cuti-perawatan',         // pengajuan cuti dari rawat inap
    ];

    private const STATUS_PERMINTAAN = ['diminta', 'dipenuhi', 'ditolak'];

    private const JENIS_SERAH = ['barang-pasien', 'anggota-tubuh'];

    private const ARAH_SERAH = ['dititipkan', 'diserahkan'];

    public function up(): void
    {
        // ------------------------------------------------ permintaan pasien

        Schema::create(self::S.'.patient_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 24)->unique();
            $table->string('request_type', 30);

            $table->unsignedBigInteger('registration_id')->nullable()->comment('ID registrasi encounter, referensi longgar');
            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar');
            $table->string('patient_name', 150);

            $table->timestampTz('requested_at');
            $table->string('requester_name', 150);

            // Siapa yang meminta ikut dicatat karena permintaan atas nama
            // pasien yang diajukan orang tanpa hubungan apa pun bukan
            // permintaan pasien.
            $table->string('requester_relationship', 20);

            $table->text('detail')->comment('Apa yang diminta, dengan kata-kata peminta');

            // Hanya untuk cuti perawatan — ditegakkan CHECK di bawah.
            $table->date('leave_starts_at')->nullable();
            $table->date('leave_ends_at')->nullable();

            $table->string('status', 20)->default('diminta');
            $table->text('response_note')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->string('responded_by_name', 150)->nullable();
            $table->timestampTz('responded_at')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['request_type', 'status']);
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_type_check
            CHECK (request_type IN ('".implode("','", self::JENIS_PERMINTAAN)."'))");

        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_status_check
            CHECK (status IN ('".implode("','", self::STATUS_PERMINTAAN)."'))");

        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_relationship_check
            CHECK (requester_relationship IN ('".implode("','", self::HUBUNGAN)."'))");

        // Tanggal cuti hanya untuk cuti, lengkap berdua, dan tidak terbalik.
        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_leave_check
            CHECK (
                (request_type = 'cuti-perawatan'
                    AND leave_starts_at IS NOT NULL AND leave_ends_at IS NOT NULL
                    AND leave_ends_at >= leave_starts_at)
                OR (request_type <> 'cuti-perawatan'
                    AND leave_starts_at IS NULL AND leave_ends_at IS NULL)
            )");

        /*
         * Penolakan tanpa alasan ditolak di tingkat basis data. Permintaan
         * yang dipenuhi meninggalkan bukti pada perbuatannya sendiri;
         * permintaan yang ditolak tidak meninggalkan apa pun selain baris
         * ini.
         */
        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_refusal_check
            CHECK (status <> 'ditolak' OR (response_note IS NOT NULL AND btrim(response_note) <> ''))");

        // Jawaban apa pun harus punya waktu — "sudah dipenuhi" tanpa kapan
        // tidak bisa dibandingkan dengan kapan diminta.
        DB::statement('ALTER TABLE '.self::S.".patient_requests ADD CONSTRAINT patient_requests_answer_time_check
            CHECK (status = 'diminta' OR responded_at IS NOT NULL)");

        // ------------------------------------------------ serah terima

        Schema::create(self::S.'.property_handovers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('handover_number', 24)->unique();

            $table->string('kind', 20)->comment('barang-pasien atau anggota-tubuh');
            $table->string('direction', 20)->comment('dititipkan ke RS atau diserahkan ke keluarga');

            $table->unsignedBigInteger('registration_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('patient_name', 150);

            $table->text('description');
            $table->string('quantity', 50)->nullable()->comment('mis. "1 buah", "sepasang"');

            // Wajib: satu-satunya pembanding bila belakangan ada yang
            // menyatakan barangnya rusak atau kurang.
            $table->string('condition', 200);

            // Wajib untuk anggota tubuh — lihat catatan kelas.
            $table->string('container_label', 100)->nullable();

            $table->string('counterparty_name', 150);
            $table->string('counterparty_relationship', 20);
            $table->string('counterparty_id_number', 30)->nullable();
            $table->string('counterparty_phone', 30)->nullable();
            $table->string('counterparty_address', 200)->nullable();

            $table->unsignedBigInteger('officer_id')->nullable();
            $table->string('officer_name', 150);

            $table->timestampTz('occurred_at');

            // Penyerahan boleh menunjuk penitipan yang dilunasinya, supaya
            // sisa titipan bisa DIHITUNG dan bukan ditebak.
            $table->unsignedBigInteger('settles_handover_id')->nullable();

            $table->timestampsTz();

            $table->index(['patient_id', 'kind']);
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE '.self::S.".property_handovers ADD CONSTRAINT property_handovers_kind_check
            CHECK (kind IN ('".implode("','", self::JENIS_SERAH)."'))");

        DB::statement('ALTER TABLE '.self::S.".property_handovers ADD CONSTRAINT property_handovers_direction_check
            CHECK (direction IN ('".implode("','", self::ARAH_SERAH)."'))");

        DB::statement('ALTER TABLE '.self::S.".property_handovers ADD CONSTRAINT property_handovers_relationship_check
            CHECK (counterparty_relationship IN ('".implode("','", self::HUBUNGAN)."'))");

        DB::statement('ALTER TABLE '.self::S.".property_handovers ADD CONSTRAINT property_handovers_label_check
            CHECK (kind <> 'anggota-tubuh' OR (container_label IS NOT NULL AND btrim(container_label) <> ''))");

        DB::statement('ALTER TABLE '.self::S.'.property_handovers
            ADD CONSTRAINT property_handovers_settles_fk
            FOREIGN KEY (settles_handover_id) REFERENCES '.self::S.'.property_handovers (id)');

        // Satu penitipan hanya bisa dilunasi sekali. Tanpa ini, dua penyerahan
        // bisa menunjuk titipan yang sama dan sisa titipan jadi negatif.
        DB::statement('CREATE UNIQUE INDEX property_handovers_settles_unique
            ON '.self::S.'.property_handovers (settles_handover_id)
            WHERE settles_handover_id IS NOT NULL');

        // -------------------------------------- pernyataan pada persetujuan

        Schema::table(self::S.'.patient_consents', function (Blueprint $table) {
            $table->unsignedBigInteger('chosen_practitioner_id')->nullable()
                ->comment('ID praktisi organization, referensi longgar — PILIHAN pasien, bukan penugasan RS');
            $table->string('chosen_practitioner_name', 150)->nullable();
        });

        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_type_check');
        DB::statement('ALTER TABLE '.self::S.".patient_consents ADD CONSTRAINT patient_consents_type_check
            CHECK (consent_type IN (
                'tindakan','penolakan-anjuran-medis','resusitasi','umum',
                'pemeriksaan-hiv','penundaan-pelayanan','rawat-inap','pulang-permintaan-sendiri',
                'pernyataan-pasien-umum','memilih-dpjp'
            ))");

        /*
         * Dokter pilihan hanya sah pada pernyataan memilih DPJP. Persetujuan
         * tindakan yang menyimpan "dokter pilihan" akan terbaca sebagai
         * penunjukan operator, dan itu keputusan yang sama sekali lain.
         */
        DB::statement('ALTER TABLE '.self::S.".patient_consents ADD CONSTRAINT patient_consents_chosen_dpjp_check
            CHECK (
                (consent_type = 'memilih-dpjp' AND chosen_practitioner_name IS NOT NULL)
                OR (consent_type <> 'memilih-dpjp' AND chosen_practitioner_id IS NULL AND chosen_practitioner_name IS NULL)
            )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_chosen_dpjp_check');
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_type_check');
        DB::statement('ALTER TABLE '.self::S.".patient_consents ADD CONSTRAINT patient_consents_type_check
            CHECK (consent_type IN (
                'tindakan','penolakan-anjuran-medis','resusitasi','umum',
                'pemeriksaan-hiv','penundaan-pelayanan','rawat-inap','pulang-permintaan-sendiri'
            ))");

        Schema::table(self::S.'.patient_consents', function (Blueprint $table) {
            $table->dropColumn(['chosen_practitioner_id', 'chosen_practitioner_name']);
        });

        Schema::dropIfExists(self::S.'.property_handovers');
        Schema::dropIfExists(self::S.'.patient_requests');
    }
};
