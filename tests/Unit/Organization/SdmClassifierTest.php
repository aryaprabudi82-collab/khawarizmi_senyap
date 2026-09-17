<?php

namespace Tests\Unit\Organization;

use App\Modules\Organization\Services\SdmClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Penggolongan pegawai dari berkas kepegawaian resmi RSUI.
 *
 * Aturan di sini menentukan isi laporan ketenagaan dan daftar pilihan dokter
 * penanggung jawab. Yang paling mudah salah — dan diuji paling ketat — adalah
 * kategori 2: RSUI menyatukan 456 Ners dengan 21 Pranata Laboratorium dan 20
 * Radiografer dalam satu golongan "Farmasi, Perawat, dan Tenaga Kesehatan
 * Lainnya". Memperlakukan seluruhnya sebagai perawat membuat jumlah tenaga
 * keperawatan terlalu besar pada laporan yang dikirim ke Kemenkes.
 */
class SdmClassifierTest extends TestCase
{
    private SdmClassifier $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c = new SdmClassifier();
    }

    // ------------------------------------------------- kategori staf RSUI

    #[Test]
    public function kategori_1_adalah_dokter(): void
    {
        $h = $this->c->golongkanPegawai('1', 'Dokter Spesialis Anak');

        $this->assertSame('dokter', $h['category']);
    }

    #[Test]
    public function kategori_3_adalah_penunjang(): void
    {
        $h = $this->c->golongkanPegawai('3', 'Health Care Assistant');

        $this->assertSame('penunjang', $h['category']);
    }

    #[Test]
    public function kategori_4_adalah_non_medis(): void
    {
        $h = $this->c->golongkanPegawai('4', 'Teknisi ME');

        $this->assertSame('non-medis', $h['category']);
    }

    /**
     * Kode di luar 1-4 tidak ditebak dari jabatannya.
     *
     * Kategori staf ditetapkan RSUI. Menebaknya berarti menerbitkan
     * penggolongan yang tidak pernah mereka putuskan.
     */
    #[Test]
    public function kode_kategori_tak_dikenal_jadi_non_medis(): void
    {
        foreach (['', '9', 'X'] as $kode) {
            $h = $this->c->golongkanPegawai($kode, 'Ners');

            $this->assertSame('non-medis', $h['category'], "kode '{$kode}'");
        }
    }

    // ----------------------------------- kategori 2 dipecah menurut jabatan

    /**
     * Ners, perawat, dan bidan dalam kategori 2 adalah PERAWAT.
     */
    #[Test]
    #[DataProvider('jabatanPerawat')]
    public function kategori_2_berjabatan_keperawatan_jadi_perawat(string $jabatan): void
    {
        $h = $this->c->golongkanPegawai('2', $jabatan);

        $this->assertSame('perawat', $h['category'], $jabatan);
        $this->assertNull($h['support_type']);
    }

    /** @return list<array{0: string}> */
    public static function jabatanPerawat(): array
    {
        return [
            ['Ners'],
            ['Perawat Terampil'],
            ['Perawat Ahli Pertama'],
            ['Bidan Ahli Pertama'],
            ['Bidan Terampil'],
            ['Koordinator Asuhan Keperawatan'],
        ];
    }

    /**
     * Sisanya dalam kategori 2 adalah PENUNJANG, berikut jenisnya.
     *
     * Inilah yang menjawab pemilahan lab/radiologi/farmasi/gizi — dan yang
     * tidak akan muncul kalau kategori 2 diperlakukan seluruhnya sebagai
     * perawat.
     */
    #[Test]
    #[DataProvider('jabatanPenunjangKategori2')]
    public function kategori_2_selain_perawat_jadi_penunjang(string $jabatan, string $jenis): void
    {
        $h = $this->c->golongkanPegawai('2', $jabatan);

        $this->assertSame('penunjang', $h['category'], $jabatan);
        $this->assertSame($jenis, $h['support_type'], $jabatan);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function jabatanPenunjangKategori2(): array
    {
        return [
            ['Apoteker', 'farmasi'],
            ['Asisten Apoteker', 'farmasi'],
            ['Pranata Laboratorium Terampil', 'laboratorium'],
            ['Pranata Laboratorium Ahli Pertama', 'laboratorium'],
            ['Radiografer', 'radiologi'],
            ['Fisioterapis', 'rehab-medik'],
            ['Nutrisionis', 'gizi'],
            ['Teknisi Pelayanan Bank Darah', 'bank-darah'],
            ['Terapis Gigi dan Mulut', 'gigi'],
        ];
    }

    /**
     * Jenis penunjang yang Anda tanyakan sejak awal, dari jabatan nyata.
     */
    #[Test]
    #[DataProvider('jenisPenunjangNyata')]
    public function jenis_penunjang_dikenali_dari_jabatan(string $jabatan, ?string $jenis): void
    {
        $this->assertSame($jenis, $this->c->jenisPenunjang($jabatan), $jabatan);
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function jenisPenunjangNyata(): array
    {
        return [
            ['Staf Sterilisasi (CSSD)', 'cssd'],
            ['Cook', 'gizi'],
            ['Pramusaji', 'gizi'],
            ['Operator Binatu', 'binatu'],
            ['Staf Casemix', 'rekam-medis'],
            ['Staf Rekam Medis', 'rekam-medis'],
            ['Sanitarian', 'sanitasi'],
            ['Petugas Pemulasaraan Jenazah', 'forensik'],
            ['Health Care Assistant', null],
            ['Staf Admisi', null],
        ];
    }

    /**
     * Jabatan yang lebih khusus menang atas yang umum.
     *
     * "Staf Administrasi Farmasi" mengandung kata "farmasi", dan kalau urutan
     * pemeriksaannya salah, petugas administrasi tercatat sebagai tenaga
     * kefarmasian pada laporan ketenagaan.
     */
    #[Test]
    public function pranata_laboratorium_bukan_sekadar_mengandung_kata_lab(): void
    {
        $this->assertSame('laboratorium', $this->c->jenisPenunjang('Pranata Laboratorium Terampil'));
        $this->assertSame('radiologi', $this->c->jenisPenunjang('Radiografer'));
    }

    // -------------------------------------------------------------- dokter

    /**
     * Dokter mitra TETAP boleh jadi DPJP.
     *
     * Mereka bukan pegawai tetap RSUI, tapi memegang pasien dan
     * menandatangani rekam medis. Status kemitraan urusan kepegawaian, bukan
     * kewenangan klinis — 148 dari 337 dokter berstatus mitra, dan
     * mengeluarkan mereka akan mengosongkan separuh daftar DPJP.
     */
    #[Test]
    public function dokter_mitra_boleh_jadi_dpjp_selama_praktiknya_aktif(): void
    {
        $this->assertTrue($this->c->bolehJadiDpjp('AKTIF'));
        $this->assertTrue($this->c->bolehJadiDpjp('aktif'));
    }

    /**
     * Status praktik selain AKTIF berarti tidak melayani.
     *
     * Dokter yang sudah berhenti tapi terlanjur tercatat aktif akan muncul
     * sebagai pilihan DPJP dan menerima pasien.
     */
    #[Test]
    public function status_praktik_selain_aktif_tidak_boleh_jadi_dpjp(): void
    {
        foreach (['TIDAK AKTIF', 'CUTI', 'KELUAR', '', '-'] as $status) {
            $this->assertFalse($this->c->bolehJadiDpjp($status), "status '{$status}'");
        }
    }

    #[Test]
    #[DataProvider('penyebutanDokter')]
    public function spesialisasi_diambil_dari_penyebutan(string $penyebutan, ?string $diharapkan): void
    {
        $this->assertSame($diharapkan, $this->c->spesialisasiDokter($penyebutan));
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function penyebutanDokter(): array
    {
        return [
            ['Dokter Spesialis Anak', 'Spesialis Anak'],
            ['Dokter Spesialis Gizi Klinik', 'Spesialis Gizi Klinik'],
            ['Dokter Gigi Spesialis Radiologi Kedokteran Gigi', 'Gigi Spesialis Radiologi Kedokteran Gigi'],
            ['Dokter Umum', null],
            ['Dokter Gigi Umum', null],
            ['-', null],
            ['', null],
        ];
    }

    #[Test]
    public function gelar_depan_dikenali(): void
    {
        $this->assertSame('Dr.', $this->c->gelarDepan('dr. Anna Maria'));
        $this->assertSame('Drg.', $this->c->gelarDepan('drg. I Nyoman'));
        $this->assertSame('Ns.', $this->c->gelarDepan('Ns. Abdul Gofur, S.Kep.'));
        $this->assertNull($this->c->gelarDepan('Ajeng Setiarini, A.Md.Farm.'));
    }

    // -------------------------------------------------------- kunci nama

    /**
     * Gelar dan tanda baca tidak memengaruhi kunci pencocokan.
     */
    #[Test]
    public function kunci_nama_mengabaikan_gelar_dan_tanda_baca(): void
    {
        $a = $this->c->kunciNama('Ns. Budi Santoso, S.Kep');
        $b = $this->c->kunciNama('BUDI SANTOSO');

        $this->assertSame($a, $b);
        $this->assertSame('budi santoso', $a);
    }

    /**
     * Dua orang berbeda bernama sama menghasilkan kunci yang sama — dan itu
     * memang tidak bisa dihindari.
     *
     * Uji ini mengunci pengakuan itu: kunci nama dipakai untuk MENCARI
     * kandidat, tidak pernah untuk memutuskan bahwa dua baris adalah orang
     * yang sama. Pada data nyata, satu nama cocok ke dua perawat pegawai yang
     * benar-benar berbeda.
     */
    #[Test]
    public function nama_yang_sama_menghasilkan_kunci_yang_sama_walau_orangnya_beda(): void
    {
        $this->assertSame(
            $this->c->kunciNama('Nilawati'),
            $this->c->kunciNama('Nilawati, A.Md.Kep')
        );
    }
}
