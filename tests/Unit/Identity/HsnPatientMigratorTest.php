<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Services\HsnPatientMigrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Penyimpulan jenis kelamin dari sufiks gelar — inti kebenaran migrasi HSN.
 *
 * MENGAPA INI DIUJI KETAT. Aturan ini menentukan jenis kelamin 238.143 pasien
 * sungguhan, dan jenis kelamin menggeser rentang rujukan laboratorium serta
 * perhitungan dosis. Salah di sini tidak memunculkan galat apa pun — ia hanya
 * menghasilkan angka yang terlihat wajar.
 *
 * Aturannya sendiri sudah divalidasi terhadap data: 10.076 nama bersufiks yang
 * juga punya jenis kelamin sungguhan di `BillingPenjaminHeader` — 10.073 cocok,
 * 3 meleset (99,97%). Yang dikunci di sini adalah agar implementasinya tidak
 * bergeser dari aturan yang sudah terbukti itu.
 */
class HsnPatientMigratorTest extends TestCase
{
    private HsnPatientMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        // Berkas tidak dibuka untuk pengujian murni-logika ini.
        $this->migrator = new HsnPatientMigrator('tidak-dipakai.csv');
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    public static function namaBersufiks(): array
    {
        return [
            // [nama asli, nama bersih yang diharapkan, sufiks yang diharapkan]
            'titik spasi NY' => ['BUDI SANTOSO. NY', 'BUDI SANTOSO', 'NY'],
            'titik tanpa spasi' => ['SITI AMINAH.NY', 'SITI AMINAH', 'NY'],
            'koma spasi TN' => ['AGUS WIJAYA, TN', 'AGUS WIJAYA', 'TN'],
            'sufiks NN' => ['RINA DEWI. NN', 'RINA DEWI', 'NN'],
            'sufiks BY' => ['SARI LESTARI. BY', 'SARI LESTARI', 'BY'],
            'sufiks AN' => ['PUTRA PERTAMA. AN', 'PUTRA PERTAMA', 'AN'],
            'spasi titik ganda' => ['SUPRI . TN.', 'SUPRI', 'TN'],
            'huruf kecil' => ['dewi anggraini. ny', 'dewi anggraini', 'NY'],
            'tanpa sufiks' => ['JOKO WIDODO', 'JOKO WIDODO', null],
        ];
    }

    #[Test]
    #[DataProvider('namaBersufiks')]
    public function sufiks_dipisahkan_dari_nama(string $asli, string $bersihDiharapkan, ?string $sufiksDiharapkan): void
    {
        [$bersih, $sufiks] = $this->migrator->pisahkanSufiks($asli);

        $this->assertSame($bersihDiharapkan, $bersih);
        $this->assertSame($sufiksDiharapkan, $sufiks);
    }

    /**
     * Gelar akademik TIDAK ikut terpotong.
     *
     * Nama nyata di data HSN memuat gelar bertitik-koma seperti
     * "HELMI, SH.,M.H.,CN. NY". Yang boleh hilang hanya penanda gender di
     * ujungnya; gelar adalah bagian dari nama orang.
     */
    #[Test]
    public function gelar_akademik_tidak_ikut_terpotong(): void
    {
        [$bersih, $sufiks] = $this->migrator->pisahkanSufiks('HELMI, SH.,M.H.,CN. NY');

        $this->assertSame('HELMI, SH.,M.H.,CN', $bersih);
        $this->assertSame('NY', $sufiks);
    }

    /**
     * Sufiks GANDA dipotong seluruhnya, bukan satu lapis saja.
     *
     * Bentuk ini nyata di ekspor HSN — ". NY. NY", ". AN. AN", ". TN. TN" —
     * penanda yang tertulis dua kali. Pemotongan satu lapis menyisakan lapis
     * kedua menempel pada nama, dan nama itulah yang tercetak di gelang
     * identitas dan label spesimen.
     *
     * Ditemukan saat memeriksa 6.299 pasien contoh yang benar-benar dimuat:
     * 9 di antaranya masih bersufiks setelah migrasi.
     */
    #[Test]
    public function sufiks_ganda_dipotong_seluruhnya(): void
    {
        $kasus = [
            ['WULIYANTO. NY. NY', 'WULIYANTO', 'NY'],
            ['RAKHMI SUKMADEWANTI. NY. NY', 'RAKHMI SUKMADEWANTI', 'NY'],
            ['BINTANG AVICENNA ALFATIH. AN. AN', 'BINTANG AVICENNA ALFATIH', 'AN'],
            ['FATIH MIRZA RAMADHANI . AN. AN', 'FATIH MIRZA RAMADHANI', 'AN'],
            ['I MADE NGURAH ARIS WINATA . TN. TN', 'I MADE NGURAH ARIS WINATA', 'TN'],
            ['RONNY. TN. TN', 'RONNY', 'TN'],
        ];

        foreach ($kasus as [$asli, $bersihDiharapkan, $sufiksDiharapkan]) {
            [$bersih, $sufiks] = $this->migrator->pisahkanSufiks($asli);

            $this->assertSame($bersihDiharapkan, $bersih, "Gagal memotong sufiks ganda pada '{$asli}'");
            $this->assertSame($sufiksDiharapkan, $sufiks);
        }
    }

    /**
     * Gelar "RR." di AWAL nama tidak ikut terpotong.
     *
     * Raden Roro adalah bagian dari nama, bukan penanda gender di ujung. Yang
     * dipotong hanya sufiks di ujung — dan pemotongan berulang untuk sufiks
     * ganda tidak boleh merambat sampai memakan awalan nama.
     */
    #[Test]
    public function gelar_di_awal_nama_tidak_terpotong(): void
    {
        [$bersih, $sufiks] = $this->migrator->pisahkanSufiks('RR. SEKAR WANGI RAJNI RAYUDINITA. AN. AN');

        $this->assertSame('RR. SEKAR WANGI RAJNI RAYUDINITA', $bersih);
        $this->assertSame('AN', $sufiks);
    }

    /**
     * Nama yang SELURUHNYA berupa sufiks tidak boleh jadi kosong.
     *
     * `name` di identity.patients bersifat NOT NULL. Memotong "TN" jadi string
     * kosong akan menggagalkan seluruh baris — dan lebih buruk, kalau lolos, ia
     * menciptakan pasien tanpa nama.
     */
    #[Test]
    public function nama_yang_seluruhnya_sufiks_dikembalikan_utuh(): void
    {
        foreach (['TN', 'NY', 'NN', 'AN', 'BY'] as $hanyaSufiks) {
            [$bersih, $sufiks] = $this->migrator->pisahkanSufiks($hanyaSufiks);

            $this->assertSame($hanyaSufiks, $bersih, "Nama '{$hanyaSufiks}' tidak boleh jadi kosong");
            $this->assertNull($sufiks);
        }
    }

    // ------------------------------------------------------- penyimpulan gender

    #[Test]
    public function ny_dan_nn_berarti_perempuan(): void
    {
        foreach (['NY', 'NN'] as $sufiks) {
            [$sex, $sumber] = $this->migrator->simpulkanSex($sufiks);

            $this->assertSame('P', $sex);
            $this->assertSame('sufiks-nama:'.$sufiks, $sumber);
        }
    }

    #[Test]
    public function tn_berarti_laki_laki(): void
    {
        [$sex, $sumber] = $this->migrator->simpulkanSex('TN');

        $this->assertSame('L', $sex);
        $this->assertSame('sufiks-nama:TN', $sumber);
    }

    /**
     * AN (Anak) dan BY (Bayi) adalah penanda UMUR, bukan jenis kelamin.
     *
     * Ini pembedaan yang paling mudah terlewat, dan akibatnya paling berat:
     * pada anak, jenis kelamin yang salah menggeser seluruh rentang rujukan
     * pertumbuhan dan dosis per berat badan.
     */
    #[Test]
    public function an_dan_by_tidak_menentukan_jenis_kelamin(): void
    {
        foreach (['AN', 'BY'] as $sufiks) {
            [$sex, $sumber] = $this->migrator->simpulkanSex($sufiks);

            $this->assertNull($sex, "Sufiks '{$sufiks}' menandai umur, bukan gender");
            $this->assertStringContainsString('sufiks-umur', $sumber);
        }
    }

    #[Test]
    public function tanpa_sufiks_berarti_belum_diketahui(): void
    {
        [$sex, $sumber] = $this->migrator->simpulkanSex(null);

        $this->assertNull($sex);
        $this->assertSame('tidak-diketahui:tanpa-sufiks', $sumber);
    }

    /**
     * Asal-usul nilai SELALU tercatat.
     *
     * Tanpa ini, tidak ada cara membedakan jenis kelamin yang disimpulkan mesin
     * dari yang diisi petugas atau datang dari ekspor ulang AFYA — dan begitu
     * data otoritatif tiba, tidak ada yang tahu mana yang boleh ditimpa.
     */
    #[Test]
    public function asal_usul_nilai_selalu_tercatat(): void
    {
        foreach ([null, 'NY', 'NN', 'TN', 'AN', 'BY'] as $sufiks) {
            [, $sumber] = $this->migrator->simpulkanSex($sufiks);

            $this->assertNotSame('', $sumber);
            $this->assertMatchesRegularExpression('/^(sufiks-nama|tidak-diketahui):/', $sumber);
        }
    }
}
