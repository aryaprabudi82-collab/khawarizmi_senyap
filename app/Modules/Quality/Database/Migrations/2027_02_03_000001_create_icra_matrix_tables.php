<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kosakata & matriks ICRA (domain R item A).
 *
 * Enam kode: jenis aktivitas proyek, lokasi & kelompok risiko area, kelas
 * risiko/pencegahan, tindakan pengendalian, persyaratan harus dipenuhi,
 * dan pengkajian risiko pra-konstruksi itu sendiri (diperdalam).
 *
 * DUDUK PERKARANYA. Delapan tabel PCRA di `sik_schema.sql` SEMUANYA cuma
 * daftar dua kolom (kode + nama), dan tabel PENGKAJIANNYA sendiri tidak
 * ada — sama seperti enam kode domain P yang punya menu tapi tidak punya
 * penyimpanan. Jadi Khanza menyediakan kosakatanya tanpa tempat memakainya.
 *
 * Sistem kita justru kebalikannya: `quality.icra_assessments` sudah ada
 * sejak awal, tapi kedelapan kosakata itu diciutkan jadi teks bebas
 * (`project_type`, `location`, `required_precautions`, `control_measures`)
 * atau enum tetap (empat kolom `*_risk_level`, `risk_class`). Kajiannya
 * tersimpan; DASARNYA tidak.
 *
 * YANG PALING MENENTUKAN: `risk_class` SELAMA INI DIKETIK.
 *
 * Kelas pencegahan ICRA bukan pendapat. Ia hasil MATRIKS: jenis aktivitas
 * proyek (Tipe A-D, ditentukan seberapa banyak debu dan seberapa lama)
 * disilangkan dengan kelompok risiko pasien di area terdampak (Rendah
 * sampai Sangat Tinggi). Matriks itu ditetapkan di luar rumah sakit —
 * pedoman APIC yang diadopsi pengendalian infeksi Kemenkes — dan seluruh
 * gunanya adalah menutup ruang tawar-menawar.
 *
 * Dengan kelas yang diketik, proyek Tipe D di ruang isolasi bisa tercatat
 * Kelas I dan tidak ada yang menolaknya. Akibatnya bukan salah pencatatan:
 * kelas menentukan pengendalian yang WAJIB dipasang — barrier, tekanan
 * negatif, HEPA — jadi kelas yang terlalu rendah berarti konstruksi
 * berjalan tanpa pengendalian yang seharusnya, di sebelah pasien yang
 * paling rentan. Sesudahnya, dokumen ICRA-nya justru menjadi bukti bahwa
 * rumah sakit sudah menilai dan menyimpulkan boleh.
 *
 * Karena itu kelas DIHITUNG dari matriks dan tidak pernah diterima dari
 * pemanggil — aturan yang sama seperti arah kas, arah cairan, dan jenis
 * persetujuan yang diambil dari templatenya.
 *
 * MATRIKSNYA DATA, BUKAN KODE PROGRAM. Alasannya sama dengan sumbu grafik
 * pada domain O dan ambang skor pada template asesmen domain M: pedoman
 * direvisi tanpa memberi tahu pemrogram, dan IPCN RSP UI harus bisa
 * mencocokkannya dengan acuan mereka sendiri tanpa menunggu migrasi.
 * Isian awalnya mengikuti matriks APIC yang lazim diterbitkan, DAN ITU
 * PERLU DIPERIKSA IPCN sebelum dipakai sungguhan — matriks yang keliru
 * menghasilkan kelas pencegahan resmi yang keliru untuk setiap proyek
 * sesudahnya.
 *
 * ADA SEL YANG SENGAJA TIDAK TUNGGAL, DAN ITU BUKAN KEKURANGAN SUMBERNYA.
 * Beberapa sel matriks berbunyi "Kelas III/IV": standarnya memang
 * menyerahkan pilihan di antara keduanya kepada komite pengendalian
 * infeksi, karena pada rentang itu keputusannya menuntut penilaian
 * manusia. Memaksanya jadi satu kelas akan MENYEMBUNYIKAN keputusan yang
 * standarnya justru mensyaratkan ada. Jadi sel seperti itu menyimpan
 * kelas minimum DAN maksimum, dan pengkajiannya wajib menyebut kelas yang
 * dipilih berikut siapa yang menyetujuinya.
 *
 * AREA LAHIR KOSONG, KELOMPOK RISIKONYA TIDAK. Nama kelompok risiko
 * (Rendah/Sedang/Tinggi/Sangat Tinggi) ditetapkan pedoman; PEMETAAN ruang
 * mana masuk kelompok mana adalah keputusan RSP UI — ruang endoskopi bisa
 * masuk Tinggi di satu rumah sakit dan Sangat Tinggi di rumah sakit lain,
 * tergantung layanan apa yang ada di sebelahnya. Menebaknya berarti
 * menentukan pengendalian konstruksi untuk ruang yang belum pernah
 * ditinjau siapa pun. Garis yang sama dipakai sepanjang proyek ini.
 */
return new class extends Migration
{
    private const S = 'quality';

    /**
     * Tipe aktivitas proyek. Daftar ini BOLEH ditetapkan: batasnya
     * pedoman ICRA, bukan diskresi rumah sakit.
     */
    private const AKTIVITAS = [
        ['A', 'Tipe A — Inspeksi & non-invasif', 'Pemeriksaan visual, pengecatan tanpa pengamplasan, penggantian plafon terbatas untuk inspeksi. Nyaris tanpa debu.'],
        ['B', 'Tipe B — Skala kecil, durasi pendek', 'Pekerjaan kecil berdebu minimal, mis. akses ke ruang antar-dinding, pemotongan terbatas dengan debu terkendali.'],
        ['C', 'Tipe C — Menimbulkan debu sedang-tinggi', 'Pembongkaran atau pembangunan komponen bangunan tetap, mis. pengamplasan dinding, pembongkaran plafon menyeluruh.'],
        ['D', 'Tipe D — Pembongkaran & konstruksi besar', 'Pekerjaan berat, pembongkaran struktur, atau pekerjaan yang menuntut shift berturut-turut.'],
    ];

    /** Kelompok risiko pasien di area terdampak — juga dari pedoman. */
    private const KELOMPOK = [
        ['1', 'Kelompok 1 — Risiko Rendah', 'Area perkantoran, area tanpa pasien.'],
        ['2', 'Kelompok 2 — Risiko Sedang', 'Rawat jalan umum, laundry, gizi, rehabilitasi medik.'],
        ['3', 'Kelompok 3 — Risiko Tinggi', 'Gawat darurat, radiologi, ruang rawat inap, kamar bersalin.'],
        ['4', 'Kelompok 4 — Risiko Sangat Tinggi', 'Kamar operasi, ICU/NICU, ruang isolasi imunokompromais, farmasi steril, CSSD.'],
    ];

    private const KELAS = [
        ['I', 'Kelas I', 'Pengendalian dasar: pekerjaan tidak menimbulkan debu, area dibersihkan setelah selesai.'],
        ['II', 'Kelas II', 'Pengendalian debu aktif: penutup permukaan, pelembapan area kerja, penyedot debu ber-HEPA.'],
        ['III', 'Kelas III', 'Barrier kedap, tekanan negatif, pembuangan udara terkendali, area dilarang dilalui pasien.'],
        ['IV', 'Kelas IV', 'Seluruh pengendalian Kelas III ditambah ruang antara (anteroom), pemantauan tekanan, dan penutupan akses menyeluruh.'],
    ];

    /**
     * Matriks ICRA: [tipe aktivitas, kelompok risiko, kelas minimum, kelas
     * maksimum atau null bila tunggal].
     *
     * Sel bermaksimum bukan sel yang belum diputuskan penyusun tabel ini —
     * standarnya memang menyerahkannya kepada komite pengendalian infeksi.
     *
     * PERIKSA INI TERHADAP ACUAN IPCN RSP UI SEBELUM DIPAKAI SUNGGUHAN.
     */
    private const MATRIKS = [
        ['A', '1', 'I', null],   ['A', '2', 'I', null],   ['A', '3', 'I', null],    ['A', '4', 'II', null],
        ['B', '1', 'II', null],  ['B', '2', 'II', null],  ['B', '3', 'II', null],   ['B', '4', 'III', 'IV'],
        ['C', '1', 'II', null],  ['C', '2', 'III', null], ['C', '3', 'III', 'IV'],  ['C', '4', 'III', 'IV'],
        ['D', '1', 'III', 'IV'], ['D', '2', 'III', 'IV'], ['D', '3', 'III', 'IV'],  ['D', '4', 'IV', null],
    ];

    public function up(): void
    {
        // ---------------------------------------------------- kosakata

        foreach ([
            'icra_activity_types' => 'Tipe aktivitas proyek ICRA (A-D) — dari pedoman, bukan diskresi RS',
            'icra_risk_groups' => 'Kelompok risiko pasien area terdampak (1-4) — dari pedoman',
            'icra_precaution_classes' => 'Kelas pencegahan ICRA (I-IV) — dari pedoman',
        ] as $tabel => $keterangan) {
            Schema::create(self::S.'.'.$tabel, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code', 5)->unique();
                $table->string('name', 80);
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
            });

            DB::statement('COMMENT ON TABLE '.self::S.'.'.$tabel." IS '".$keterangan."'");
        }

        /*
         * Area & pemetaan kelompok risikonya. LAHIR KOSONG: nama kelompoknya
         * dari pedoman, tapi ruang mana masuk kelompok mana adalah keputusan
         * RSP UI — dan menebaknya berarti menentukan pengendalian konstruksi
         * untuk ruang yang belum pernah ditinjau siapa pun.
         */
        Schema::create(self::S.'.icra_areas', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('risk_group_id');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.icra_areas
            ADD CONSTRAINT icra_areas_group_fk
            FOREIGN KEY (risk_group_id) REFERENCES '.self::S.'.icra_risk_groups (id)');

        // ------------------------------------------------------ matriks

        Schema::create(self::S.'.icra_matrix', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('activity_type_id');
            $table->unsignedBigInteger('risk_group_id');

            $table->unsignedBigInteger('min_class_id');

            // Terisi hanya untuk sel yang standarnya menyerahkan pilihan
            // kepada komite pengendalian infeksi.
            $table->unsignedBigInteger('max_class_id')->nullable();

            $table->timestampsTz();

            $table->unique(['activity_type_id', 'risk_group_id']);
        });

        foreach ([
            'activity_type_id' => 'icra_activity_types',
            'risk_group_id' => 'icra_risk_groups',
            'min_class_id' => 'icra_precaution_classes',
            'max_class_id' => 'icra_precaution_classes',
        ] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.icra_matrix
                ADD CONSTRAINT icra_matrix_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        // ----------------------------------- tindakan & persyaratan

        /*
         * Tindakan pengendalian dan persyaratan yang harus dipenuhi:
         * keduanya LAHIR KOSONG. Kalimatnya adalah SPO RSP UI, dan
         * mengarangnya berarti menerbitkan perintah kerja konstruksi yang
         * tidak pernah disahkan siapa pun.
         */
        Schema::create(self::S.'.icra_control_measures', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->unsignedBigInteger('precaution_class_id')->nullable()
                ->comment('Kelas terendah yang mewajibkannya; kosong berarti berlaku umum');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.icra_control_measures
            ADD CONSTRAINT icra_control_measures_class_fk
            FOREIGN KEY (precaution_class_id) REFERENCES '.self::S.'.icra_precaution_classes (id)');

        Schema::create(self::S.'.icra_class_requirements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('precaution_class_id');
            $table->unsignedSmallInteger('position');
            $table->text('requirement');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['precaution_class_id', 'position']);
        });

        DB::statement('ALTER TABLE '.self::S.'.icra_class_requirements
            ADD CONSTRAINT icra_class_requirements_class_fk
            FOREIGN KEY (precaution_class_id) REFERENCES '.self::S.'.icra_precaution_classes (id)');

        // ------------------------------ perluasan pengkajian yang ada

        Schema::table(self::S.'.icra_assessments', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_type_id')->nullable();
            $table->unsignedBigInteger('risk_group_id')->nullable();
            $table->unsignedBigInteger('area_id')->nullable();

            // Kelas hasil matriks, DIHITUNG. `risk_class` lama tetap ada
            // sebagai teks tercetak, tapi nilainya kini berasal dari sini.
            $table->unsignedBigInteger('precaution_class_id')->nullable();

            /*
             * Terisi hanya bila matriksnya memberi rentang: siapa yang
             * memilih di antara dua kelas, dan atas dasar apa. Tanpa ini,
             * pilihan yang standarnya mensyaratkan penilaian manusia akan
             * tampak seperti keluaran otomatis.
             */
            $table->string('class_decided_by', 150)->nullable();
            $table->text('class_decision_reason')->nullable();
        });

        foreach ([
            'activity_type_id' => 'icra_activity_types',
            'risk_group_id' => 'icra_risk_groups',
            'area_id' => 'icra_areas',
            'precaution_class_id' => 'icra_precaution_classes',
        ] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.icra_assessments
                ADD CONSTRAINT icra_assessments_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        /*
         * Kelas yang dipilih dari rentang wajib menyebut siapa yang memilih
         * DAN alasannya. Ditegakkan CHECK karena service bukan satu-satunya
         * pintu ke tabel ini, dan keputusan komite yang tidak berpenanggung
         * jawab sama saja dengan tidak ada keputusan.
         */
        DB::statement('ALTER TABLE '.self::S.".icra_assessments
            ADD CONSTRAINT icra_assessments_class_decision_check
            CHECK (
                class_decided_by IS NULL
                OR (class_decision_reason IS NOT NULL AND btrim(class_decision_reason) <> '')
            )");

        // ------------------------------------------------- isian awal

        $this->seedKosakata();
    }

    private function seedKosakata(): void
    {
        $waktu = ['created_at' => now(), 'updated_at' => now()];

        foreach ([
            'icra_activity_types' => self::AKTIVITAS,
            'icra_risk_groups' => self::KELOMPOK,
            'icra_precaution_classes' => self::KELAS,
        ] as $tabel => $baris) {
            foreach ($baris as $urut => [$kode, $nama, $uraian]) {
                DB::table(self::S.'.'.$tabel)->insert([
                    'code' => $kode, 'name' => $nama, 'description' => $uraian,
                    'position' => $urut + 1, 'is_active' => true,
                ] + $waktu);
            }
        }

        $aktivitas = DB::table(self::S.'.icra_activity_types')->pluck('id', 'code');
        $kelompok = DB::table(self::S.'.icra_risk_groups')->pluck('id', 'code');
        $kelas = DB::table(self::S.'.icra_precaution_classes')->pluck('id', 'code');

        foreach (self::MATRIKS as [$tipe, $grup, $min, $max]) {
            DB::table(self::S.'.icra_matrix')->insert([
                'activity_type_id' => $aktivitas[$tipe],
                'risk_group_id' => $kelompok[$grup],
                'min_class_id' => $kelas[$min],
                'max_class_id' => $max !== null ? $kelas[$max] : null,
            ] + $waktu);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::S.'.icra_assessments DROP CONSTRAINT icra_assessments_class_decision_check');

        foreach (['activity_type_id', 'risk_group_id', 'area_id', 'precaution_class_id'] as $kolom) {
            DB::statement('ALTER TABLE '.self::S.'.icra_assessments DROP CONSTRAINT icra_assessments_'.$kolom.'_fk');
        }

        Schema::table(self::S.'.icra_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'activity_type_id', 'risk_group_id', 'area_id', 'precaution_class_id',
                'class_decided_by', 'class_decision_reason',
            ]);
        });

        Schema::dropIfExists(self::S.'.icra_class_requirements');
        Schema::dropIfExists(self::S.'.icra_control_measures');
        Schema::dropIfExists(self::S.'.icra_matrix');
        Schema::dropIfExists(self::S.'.icra_areas');
        Schema::dropIfExists(self::S.'.icra_precaution_classes');
        Schema::dropIfExists(self::S.'.icra_risk_groups');
        Schema::dropIfExists(self::S.'.icra_activity_types');
    }
};
