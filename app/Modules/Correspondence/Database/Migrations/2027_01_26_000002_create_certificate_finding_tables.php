<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surat keterangan yang menyatakan temuan, bukan kesimpulan (domain P item C).
 *
 * Lima kode: surat_sakit_pihak_2, surat_hamil (keterangan tidak hamil),
 * surat_keterangan_rawat_inap, surat_bebas_tato, skdp_bpjs (surat kontrol).
 *
 * SATU HAL YANG KHANZA SIMPAN DAN KITA TIDAK: HASIL PEMERIKSAANNYA.
 *
 * `surat_hamil` Khanza punya enum "tidak ditemukan tanda-tanda kehamilan"
 * / "ditemukan tanda-tanda kehamilan"; `surat_bebas_tato` punya "Bebas
 * Tato" / "Bertato". Tabel kita cuma punya `content` bebas. Artinya layar
 * "Surat Bebas Tato" kita hanya bisa menerbitkan surat yang menyatakan
 * bebas — hasil pemeriksaannya ditentukan oleh nama formulirnya, bukan
 * oleh pemeriksanya.
 *
 * Itu terdengar sepele sampai diingat untuk apa surat-surat ini dipakai:
 * seleksi kerja, pendaftaran sekolah kedinasan, syarat terbang. Surat
 * keterangan yang secara struktur tidak bisa memuat temuan yang tidak
 * diinginkan bukan surat keterangan — ia formulir kelulusan.
 *
 * Karena itu ditambahkan `examination_result` (temuan dengan kata-kata
 * pemeriksa) dan `is_clear` (apakah temuan itu mendukung pernyataan yang
 * disiratkan nama suratnya). Keduanya WAJIB untuk jenis yang memang
 * menyatakan ketiadaan sesuatu — bebas narkoba, bebas TBC, bebas tato,
 * tidak hamil, COVID, buta warna — dan HARUS kosong untuk jenis yang
 * tidak memeriksa apa pun (keterangan rawat inap sekadar menyebut periode
 * dirawat).
 *
 * Dan yang menentukan: JUDUL SURAT DITURUNKAN DARI TEMUAN, BUKAN DARI
 * JENISNYA. Surat bertipe bebas_tato dengan is_clear=false dicetak
 * sebagai "Surat Keterangan Hasil Pemeriksaan Tato" dan menyebutkan
 * temuannya; ia tidak pernah dicetak berbunyi "bebas". Kalau judulnya
 * ikut jenis, satu-satunya cara menerbitkan hasil yang tidak diinginkan
 * adalah tidak menerbitkannya sama sekali.
 *
 * DIAGNOSIS PASIEN TIDAK BOLEH MUNCUL DI SURAT SAKIT PIHAK KEDUA.
 * `suratsakitpihak2` Khanza adalah surat untuk orang LAIN — keluarga yang
 * perlu izin kerja karena menunggui pasien — lengkap dengan pekerjaan dan
 * instansinya, karena surat itu diserahkan ke atasan orang tersebut.
 * Menuliskan diagnosis pasien di situ berarti menyerahkan rahasia medis
 * seorang pasien kepada perusahaan tempat kerabatnya bekerja.
 *
 * Larangan itu tidak bisa ditegakkan pada teks bebas, jadi diagnosis
 * dipindahkan ke kolomnya sendiri dan CHECK melarang kolom itu terisi
 * pada surat sakit pihak kedua. Aturan kerahasiaan yang cuma jadi imbauan
 * di kepala petugas akan dilanggar pada hari yang sibuk; aturan yang jadi
 * batasan basis data tidak.
 *
 * PERIODE RAWAT INAP DIAMBIL DARI ADMISI, TIDAK DIKETIK. Surat keterangan
 * rawat inap dipakai untuk klaim asuransi dan izin kerja. Tanggal yang
 * diketik ulang bisa berbeda dari tanggal admisi tanpa ada yang tahu, dan
 * yang harus menyangkal suratnya sendiri belakangan adalah rumah sakit.
 * Karena itu `inpatient` menerbitkan kontrak `v_admission_period` dan
 * service menyalin tanggalnya dari sana — aturan yang sama seperti arah
 * kas, arah cairan, dan jenis persetujuan: disalin dari sumbernya, tidak
 * diterima dari pemanggil.
 *
 * SURAT KONTROL PUNYA SIKLUS HIDUP, JADI TABELNYA SENDIRI. `skdp_bpjs`
 * bukan surat keterangan: ia menyebut tanggal kontrol berikutnya dan
 * berstatus menunggu / sudah periksa / batal. Memaksanya masuk
 * medical_certificates berarti menambahkan kolom status dan tanggal
 * kontrol yang tidak berarti apa-apa bagi sebelas jenis lainnya.
 *
 * YANG SENGAJA TIDAK DIBANGUN: penyaluran ke Vclaim/BPJS. Kredensial
 * bridging BPJS ditangguhkan sejak awal proyek dan itu tidak berubah di
 * sini. Surat kontrol ini adalah dokumen rumah sakit; nomor SEP dan
 * antrean BPJS-nya bukan.
 *
 * DAN SATU LAGI YANG TIDAK DIBANGUN, DINYATAKAN TERANG-TERANGAN: surat
 * kontrol TIDAK membuat booking kunjungan. Booking hidup di konteks
 * encounter dan correspondence tidak boleh menulis ke sana. Akibatnya
 * pasien tetap harus didaftarkan seperti biasa saat datang. Menyambungkan
 * keduanya adalah pekerjaan konteks encounter, bukan pekerjaan surat.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    /**
     * Jenis surat yang MENYATAKAN KETIADAAN sesuatu — hasil pemeriksaannya
     * wajib dicatat, karena hasilnya bisa berlawanan dengan nama suratnya.
     */
    private const JENIS_BERTEMUAN = [
        'bebas_narkoba', 'bebas_tbc', 'buta_warna', 'covid',
        'bebas_tato', 'tidak_hamil',
    ];

    private const STATUS_KONTROL = ['menunggu', 'sudah-periksa', 'batal'];

    public function up(): void
    {
        Schema::table(self::S.'.medical_certificates', function (Blueprint $table) {
            // Temuan dengan kata-kata pemeriksa.
            $table->text('examination_result')->nullable();

            /*
             * Apakah temuan mendukung pernyataan yang disiratkan nama surat.
             * Boleh null HANYA untuk jenis yang tidak memeriksa apa pun.
             */
            $table->boolean('is_clear')->nullable();

            // Dipindahkan dari teks bebas supaya bisa dilarang muncul di
            // surat sakit pihak kedua.
            $table->string('diagnosis', 200)->nullable();

            // Blok pihak kedua — orang yang membutuhkan suratnya, bukan pasien.
            $table->string('third_party_name', 150)->nullable();
            $table->string('third_party_relationship', 20)->nullable();
            $table->date('third_party_birth_date')->nullable();
            $table->string('third_party_sex', 10)->nullable();
            $table->string('third_party_address', 200)->nullable();
            $table->string('third_party_occupation', 60)->nullable();
            $table->string('third_party_institution', 100)->nullable();
        });

        DB::statement('ALTER TABLE '.self::S.'.medical_certificates DROP CONSTRAINT medical_certificates_type_check');
        DB::statement('ALTER TABLE '.self::S.".medical_certificates ADD CONSTRAINT medical_certificates_type_check
            CHECK (certificate_type IN (
                'sehat','sakit','berobat',
                'bebas_narkoba','bebas_tbc','buta_warna','layak_terbang','kewaspadaan_kesehatan','covid','cuti_hamil',
                'sakit_pihak_kedua','tidak_hamil','rawat_inap','bebas_tato'
            ))");

        /*
         * Jenis yang menyatakan ketiadaan sesuatu WAJIB mencatat temuan dan
         * kesimpulannya; jenis lain tidak boleh punya keduanya, supaya
         * kolom is_clear tidak pernah terbaca sebagai penilaian atas surat
         * yang memang tidak menilai apa-apa.
         */
        DB::statement('ALTER TABLE '.self::S.".medical_certificates
            ADD CONSTRAINT medical_certificates_finding_check
            CHECK (
                (certificate_type IN ('".implode("','", self::JENIS_BERTEMUAN)."')
                    AND is_clear IS NOT NULL
                    AND examination_result IS NOT NULL AND btrim(examination_result) <> '')
                OR (certificate_type NOT IN ('".implode("','", self::JENIS_BERTEMUAN)."')
                    AND is_clear IS NULL)
            )");

        // Rahasia medis pasien tidak ikut ke atasan kerabatnya.
        DB::statement('ALTER TABLE '.self::S.".medical_certificates
            ADD CONSTRAINT medical_certificates_third_party_diagnosis_check
            CHECK (certificate_type <> 'sakit_pihak_kedua' OR diagnosis IS NULL)");

        // Blok pihak kedua hanya untuk surat pihak kedua, dan wajib di sana.
        DB::statement('ALTER TABLE '.self::S.".medical_certificates
            ADD CONSTRAINT medical_certificates_third_party_check
            CHECK (
                (certificate_type = 'sakit_pihak_kedua'
                    AND third_party_name IS NOT NULL AND third_party_relationship IS NOT NULL)
                OR (certificate_type <> 'sakit_pihak_kedua'
                    AND third_party_name IS NULL AND third_party_relationship IS NULL
                    AND third_party_birth_date IS NULL AND third_party_sex IS NULL
                    AND third_party_address IS NULL AND third_party_occupation IS NULL
                    AND third_party_institution IS NULL)
            )");

        DB::statement('ALTER TABLE '.self::S.".medical_certificates
            ADD CONSTRAINT medical_certificates_third_party_relationship_check
            CHECK (third_party_relationship IS NULL OR third_party_relationship IN (
                'diri-sendiri','suami','istri','ayah','ibu','anak','saudara-kandung','pengampu','lainnya'
            ))");

        DB::statement('ALTER TABLE '.self::S.".medical_certificates
            ADD CONSTRAINT medical_certificates_third_party_sex_check
            CHECK (third_party_sex IS NULL OR third_party_sex IN ('L','P'))");

        // ------------------------------------------------- surat kontrol

        Schema::create(self::S.'.control_letters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('letter_number', 24)->unique();

            $table->unsignedBigInteger('registration_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('patient_name', 150);

            $table->string('diagnosis', 200);
            $table->text('therapy');

            // Mengapa masih perlu kontrol — inti SKDP, dan yang dinilai
            // penjamin bila suratnya dipakai untuk itu.
            $table->text('control_reason');
            $table->text('follow_up_plan')->comment('Rencana tindak lanjut (RTL)');

            $table->date('control_date');
            $table->unsignedBigInteger('practitioner_id')->nullable()->comment('ID praktisi organization, referensi longgar');
            $table->string('practitioner_name', 150);

            $table->string('status', 20)->default('menunggu');
            $table->text('status_note')->nullable()->comment('Wajib bila batal');
            $table->timestampTz('status_changed_at')->nullable();

            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('issued_by_name', 150)->nullable();
            $table->timestampTz('issued_at');

            $table->timestampsTz();

            $table->index(['status', 'control_date']);
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE '.self::S.".control_letters ADD CONSTRAINT control_letters_status_check
            CHECK (status IN ('".implode("','", self::STATUS_KONTROL)."'))");

        /*
         * Surat kontrol bertanggal mundur bukan rujukan kontrol, ia salah
         * ketik — dan salah ketik yang lolos akan tampil di daftar "pasien
         * tidak datang kontrol" pada hari yang sama ia diterbitkan.
         */
        DB::statement('ALTER TABLE '.self::S.'.control_letters ADD CONSTRAINT control_letters_date_check
            CHECK (control_date >= issued_at::date)');

        DB::statement('ALTER TABLE '.self::S.".control_letters ADD CONSTRAINT control_letters_cancel_check
            CHECK (status <> 'batal' OR (status_note IS NOT NULL AND btrim(status_note) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.control_letters');

        foreach ([
            'medical_certificates_third_party_sex_check',
            'medical_certificates_third_party_relationship_check',
            'medical_certificates_third_party_check',
            'medical_certificates_third_party_diagnosis_check',
            'medical_certificates_finding_check',
        ] as $constraint) {
            DB::statement('ALTER TABLE '.self::S.'.medical_certificates DROP CONSTRAINT '.$constraint);
        }

        DB::statement('ALTER TABLE '.self::S.'.medical_certificates DROP CONSTRAINT medical_certificates_type_check');
        DB::statement('ALTER TABLE '.self::S.".medical_certificates ADD CONSTRAINT medical_certificates_type_check
            CHECK (certificate_type IN (
                'sehat','sakit','berobat',
                'bebas_narkoba','bebas_tbc','buta_warna','layak_terbang','kewaspadaan_kesehatan','covid','cuti_hamil'
            ))");

        Schema::table(self::S.'.medical_certificates', function (Blueprint $table) {
            $table->dropColumn([
                'examination_result', 'is_clear', 'diagnosis',
                'third_party_name', 'third_party_relationship', 'third_party_birth_date',
                'third_party_sex', 'third_party_address', 'third_party_occupation',
                'third_party_institution',
            ]);
        });
    }
};
