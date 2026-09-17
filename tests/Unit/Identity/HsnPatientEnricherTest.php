<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Services\HsnPatientEnricher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pemetaan demografi dari `pasien.csv` ke identity.patients.
 *
 * MENGAPA DIUJI KETAT. Aturan di sini menentukan NIK, tanggal lahir, alamat,
 * dan nomor telepon 371.719 orang sungguhan. Setiap kesalahan di sini tidak
 * memunculkan galat apa pun — ia hanya menghasilkan nilai yang terlihat wajar:
 * nomor SIM yang tersimpan sebagai NIK, nomor telepon tiga digit yang dicoba
 * dihubungi saat hasil kritis keluar, atau jenis kelamin yang ditebak untuk
 * orang yang datanya justru ada.
 *
 * Berkas ditulis ke direktori sementara supaya pengujian tidak bergantung
 * pada ekspor 78 MB yang berisi data pasien nyata.
 */
class HsnPatientEnricherTest extends TestCase
{
    private string $berkas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkas = tempnam(sys_get_temp_dir(), 'pasien').'.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->berkas)) {
            unlink($this->berkas);
        }

        parent::tearDown();
    }

    /** @param list<string> $baris */
    private function tulis(array $baris): HsnPatientEnricher
    {
        file_put_contents($this->berkas, implode("\n", $baris)."\n");

        return new HsnPatientEnricher($this->berkas);
    }

    /** @return list<array<string, mixed>> */
    private function baca(HsnPatientEnricher $e): array
    {
        return iterator_to_array($e->pasien(), false);
    }

    private function baris(string $mrn, array $ganti = []): string
    {
        $k = array_replace([
            0 => $mrn, 1 => 'BUDI SANTOSO', 2 => '3201026012920004', 3 => 'L',
            4 => 'JAKARTA', 5 => '1990-05-17', 6 => '-',
            7 => 'JL. MERDEKA NO. 1', 8 => 'O', 9 => 'Wiraswasta',
            10 => 'Maried', 11 => 'Islam', 12 => '2020-01-15 08:00:00.000',
            13 => '081234567890', 14 => '35 Th', 15 => 'S1',
        ], $ganti);

        ksort($k);

        return implode(';', $k);
    }

    // ------------------------------------------------------------------ dasar

    #[Test]
    public function kolom_dipetakan_ke_nama_yang_benar(): void
    {
        $hasil = $this->baca($this->tulis([$this->baris('00000123')]));

        $this->assertCount(1, $hasil);
        $p = $hasil[0];

        $this->assertSame('00000123', $p['medical_record_number']);
        $this->assertSame('BUDI SANTOSO', $p['name']);
        $this->assertSame('3201026012920004', $p['nik']);
        $this->assertSame('L', $p['sex']);
        $this->assertSame('JAKARTA', $p['birth_place']);
        $this->assertSame('1990-05-17', $p['birth_date']);
        $this->assertSame('JL. MERDEKA NO. 1', $p['address']);
        $this->assertSame('O', $p['blood_type']);
        $this->assertSame('Wiraswasta', $p['occupation']);
        $this->assertSame('Kawin', $p['marital_status']);
        $this->assertSame('Islam', $p['religion']);
        $this->assertSame('081234567890', $p['phone']);
        $this->assertSame('S1', $p['education']);
    }

    /**
     * BOM UTF-8 menempel pada baris PERTAMA.
     *
     * Berkas ini tidak berheader, jadi BOM-nya menempel langsung pada nomor
     * rekam medis pasien pertama. Tanpa dibuang, pasien itu hilang — dan
     * hilangnya satu baris dari 371.719 tidak akan terlihat di ringkasan
     * mana pun.
     */
    #[Test]
    public function bom_utf8_di_baris_pertama_dibuang(): void
    {
        file_put_contents($this->berkas, "\xEF\xBB\xBF".$this->baris('00000001')."\n");

        $hasil = $this->baca(new HsnPatientEnricher($this->berkas));

        $this->assertCount(1, $hasil);
        $this->assertSame('00000001', $hasil[0]['medical_record_number']);
    }

    /**
     * "-" berarti KOSONG, bukan isi.
     *
     * Sumber memakai tanda hubung sebagai penanda kosong. Disimpan apa adanya,
     * layar pendaftaran akan menampilkan alamat pasien sebagai "-".
     */
    #[Test]
    public function tanda_hubung_dibaca_sebagai_kosong(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000002', [4 => '-', 7 => '-', 9 => '-', 11 => '-', 13 => '-']),
        ]));

        $p = $hasil[0];

        $this->assertNull($p['birth_place']);
        $this->assertNull($p['address']);
        $this->assertNull($p['occupation']);
        $this->assertNull($p['religion']);
        $this->assertNull($p['phone']);
    }

    // -------------------------------------------------------------------- NIK

    /**
     * Kolom NIK menampung nomor identitas APA SAJA.
     *
     * 710 nilai di data nyata berupa nomor SIM, NPM mahasiswa, atau kartu
     * mahasiswa. Disimpan sebagai NIK, nomor SIM seseorang akan cocok saat
     * petugas mencari NIK pasien lain.
     */
    #[Test]
    public function nomor_identitas_selain_nik_ditolak(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000010', [2 => 'SIM-7890123456']),
            $this->baris('00000011', [2 => 'NPM__12345678']),
            $this->baris('00000012', [2 => '320102601292000']),   // 15 digit
            $this->baris('00000013', [2 => '32010260129200041']), // 17 digit
        ]));

        foreach ($hasil as $p) {
            $this->assertNull($p['nik'], "NIK '{$p['medical_record_number']}' harusnya ditolak");
            $this->assertSame('bukan-16-digit', $p['nik_ditolak']);
        }
    }

    /**
     * NIK yang sama untuk dua pasien: yang PERTAMA memegangnya.
     *
     * `patients_nik_unique` menolak yang kedua, dan penolakan di tengah
     * migrasi meninggalkan sebagian data masuk sebagian tidak. Yang kedua
     * masuk tanpa NIK, berikut catatan siapa pemegangnya — supaya petugas
     * rekam medis bisa menelusuri apakah itu memang satu orang yang
     * terdaftar dua kali.
     */
    #[Test]
    public function nik_ganda_hanya_diberikan_ke_pasien_pertama(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000020', [2 => '3201026012920004']),
            $this->baris('00000021', [2 => '3201026012920004']),
            $this->baris('00000022', [2 => '3201026012920004']),
        ]));

        $this->assertSame('3201026012920004', $hasil[0]['nik']);
        $this->assertNull($hasil[0]['nik_ditolak']);

        $this->assertNull($hasil[1]['nik']);
        $this->assertSame('sudah-dipakai-mrn-00000020', $hasil[1]['nik_ditolak']);

        $this->assertNull($hasil[2]['nik']);
        $this->assertSame('sudah-dipakai-mrn-00000020', $hasil[2]['nik_ditolak']);
    }

    // --------------------------------------------------------- jenis kelamin

    #[Test]
    public function ejaan_inggris_gender_dipetakan(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000030', [3 => 'M']),
            $this->baris('00000031', [3 => 'F']),
            $this->baris('00000032', [3 => 'L']),
            $this->baris('00000033', [3 => 'P']),
        ]));

        $this->assertSame('L', $hasil[0]['sex']);
        $this->assertSame('P', $hasil[1]['sex']);
        $this->assertSame('L', $hasil[2]['sex']);
        $this->assertSame('P', $hasil[3]['sex']);
    }

    /**
     * "T" TIDAK dipetakan jadi jenis kelamin apa pun.
     *
     * 30 baris memakai nilai ini dan artinya tidak diketahui. Contohnya
     * saling bertentangan — ada nama bersufiks ". NN" (Nona, perempuan) dan
     * ". AN" (Anak) di dalamnya. Menebaknya berarti menetapkan jenis kelamin
     * 30 orang secara acak, dan jenis kelamin yang salah menggeser rentang
     * rujukan laboratorium tanpa memunculkan galat.
     */
    #[Test]
    public function nilai_gender_yang_tidak_dikenal_jadi_null(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000034', [3 => 'T']),
            $this->baris('00000035', [3 => 'X']),
            $this->baris('00000036', [3 => '']),
        ]));

        foreach ($hasil as $p) {
            $this->assertNull($p['sex']);
        }
    }

    // -------------------------------------------------------------- telepon

    /**
     * Nomor telepon pecahan lebih buruk daripada kolom kosong.
     *
     * 2,6% data nyata berisi 2-4 digit. Petugas yang menghubunginya saat
     * hasil kritis keluar akan menyangka pasien tidak bisa dijangkau,
     * padahal nomornya memang tidak pernah ada.
     */
    #[Test]
    public function nomor_telepon_pecahan_ditolak(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000040', [13 => '081']),
            $this->baris('00000041', [13 => '0812']),
            $this->baris('00000042', [13 => '0']),
            $this->baris('00000043', [13 => '12']),
        ]));

        foreach ($hasil as $p) {
            $this->assertNull($p['phone'], "Nomor '{$p['medical_record_number']}' harusnya ditolak");
        }
    }

    #[Test]
    public function nomor_telepon_yang_sah_diterima(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000044', [13 => '081381154295']),
            $this->baris('00000045', [13 => '+6281381154295']),
            $this->baris('00000046', [13 => '0217654321']),
        ]));

        $this->assertSame('081381154295', $hasil[0]['phone']);
        $this->assertSame('+6281381154295', $hasil[1]['phone']);
        $this->assertSame('0217654321', $hasil[2]['phone']);
    }

    // -------------------------------------------------------------- tanggal

    #[Test]
    public function tanggal_di_luar_rentang_masuk_akal_ditolak(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000050', [5 => '1899-12-31']),
            $this->baris('00000051', [5 => '2099-01-01']),
            $this->baris('00000052', [5 => 'bukan tanggal']),
        ]));

        foreach ($hasil as $p) {
            $this->assertNull($p['birth_date']);
        }
    }

    #[Test]
    public function stempel_waktu_dipotong_jadi_tanggal(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000053', [12 => '2018-11-22 09:36:54.493']),
        ]));

        $this->assertSame('2018-11-22', $hasil[0]['registered_on']);
    }

    // ---------------------------------------------------- status & gol darah

    #[Test]
    public function status_kawin_diterjemahkan(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000060', [10 => 'Maried']),
            $this->baris('00000061', [10 => 'Single']),
            $this->baris('00000062', [10 => 'Widow']),
            $this->baris('00000063', [10 => 'Widower']),
            $this->baris('00000064', [10 => 'Not Known']),
        ]));

        $this->assertSame('Kawin', $hasil[0]['marital_status']);
        $this->assertSame('Belum kawin', $hasil[1]['marital_status']);
        $this->assertSame('Janda', $hasil[2]['marital_status']);
        $this->assertSame('Duda', $hasil[3]['marital_status']);
        $this->assertNull($hasil[4]['marital_status']);
    }

    #[Test]
    public function golongan_darah_di_luar_daftar_ditolak(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000070', [8 => 'O']),
            $this->baris('00000071', [8 => 'AB']),
            $this->baris('00000072', [8 => 'Z']),
            $this->baris('00000073', [8 => '-']),
        ]));

        $this->assertSame('O', $hasil[0]['blood_type']);
        $this->assertSame('AB', $hasil[1]['blood_type']);
        $this->assertNull($hasil[2]['blood_type']);
        $this->assertNull($hasil[3]['blood_type']);
    }

    /**
     * Baris tanpa nomor rekam medis yang sah dilewati, bukan dimuat kosong.
     */
    #[Test]
    public function baris_tanpa_mrn_sah_dilewati(): void
    {
        $hasil = $this->baca($this->tulis([
            $this->baris('00000080'),
            $this->baris('BUKAN-ANGKA'),
            $this->baris(''),
            $this->baris('00000081'),
        ]));

        $this->assertCount(2, $hasil);
        $this->assertSame('00000080', $hasil[0]['medical_record_number']);
        $this->assertSame('00000081', $hasil[1]['medical_record_number']);
    }
}
