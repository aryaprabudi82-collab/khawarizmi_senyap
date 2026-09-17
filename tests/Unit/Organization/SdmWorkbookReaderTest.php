<?php

namespace Tests\Unit\Organization;

use App\Modules\Organization\Services\SdmWorkbookReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Pembaca berkas kepegawaian .xlsx.
 *
 * Berkas ujinya DIBUAT DI SINI, bukan memakai ekspor SDM yang asli — berkas
 * itu memuat nama, NIP, dan jabatan pegawai RSUI yang sungguhan, dan
 * pengujian tidak boleh bergantung pada data pribadi siapa pun.
 */
class SdmWorkbookReaderTest extends TestCase
{
    private string $berkas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkas = tempnam(sys_get_temp_dir(), 'sdm').'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->berkas)) {
            unlink($this->berkas);
        }

        parent::tearDown();
    }

    /**
     * Menyusun .xlsx minimal: dua lembar, satu tabel string bersama.
     *
     * Urutan r:id sengaja DIBALIK terhadap penomoran berkas, meniru berkas
     * nyata — di sana lembar "MED AGS26" adalah sheet4.xml sementara lembar
     * sebelumnya sheet3.xml berisi pivot.
     */
    private function buatBerkas(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->berkas, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('xl/workbook.xml', <<<'XML'
            <?xml version="1.0"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                      xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
              <sheets>
                <sheet name="PEGAWAI" sheetId="1" r:id="rA"/>
                <sheet name="DOKTER" sheetId="2" r:id="rB"/>
              </sheets>
            </workbook>
            XML);

        // rA menunjuk sheet9, rB menunjuk sheet2 — sengaja tidak berurutan.
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rA" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet9.xml"/>
              <Relationship Id="rB" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
            <?xml version="1.0"?>
            <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <si><t>NIP</t></si>
              <si><t>Nama</t></si>
              <si><t>Budi Santoso</t></si>
              <si><r><t>Siti </t></r><r><t>Aminah</t></r></si>
            </sst>
            XML);

        $zip->addFromString('xl/worksheets/sheet9.xml', <<<'XML'
            <?xml version="1.0"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
                <row r="2"><c r="A2"><v>1800001</v></c><c r="B2" t="s"><v>2</v></c><c r="D2"><v>43224</v></c></row>
                <row r="3"><c r="A3"><v>1800002</v></c><c r="B3" t="s"><v>3</v></c></row>
              </sheetData>
            </worksheet>
            XML);

        $zip->addFromString('xl/worksheets/sheet2.xml', <<<'XML'
            <?xml version="1.0"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1"><c r="A1" t="s"><v>0</v></c></row>
                <row r="2"><c r="A2" t="inlineStr"><is><t>Sebaris</t></is></c></row>
              </sheetData>
            </worksheet>
            XML);

        $zip->close();
    }

    #[Test]
    public function lembar_dipetakan_lewat_rid_bukan_nomor_berkas(): void
    {
        $this->buatBerkas();

        $r = new SdmWorkbookReader($this->berkas);

        $this->assertSame(['PEGAWAI', 'DOKTER'], $r->daftarLembar());

        // PEGAWAI menunjuk sheet9.xml; menebak dari nomor urut akan salah lembar.
        $baris = iterator_to_array($r->baris('PEGAWAI'), false);

        $this->assertCount(2, $baris);
        $this->assertSame('1800001', $baris[0]['A']);
    }

    #[Test]
    public function baris_header_tidak_ikut(): void
    {
        $this->buatBerkas();

        $baris = iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('PEGAWAI'), false);

        foreach ($baris as $b) {
            $this->assertNotSame('NIP', $b['A'] ?? null);
        }
    }

    #[Test]
    public function string_bersama_diterjemahkan(): void
    {
        $this->buatBerkas();

        $baris = iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('PEGAWAI'), false);

        $this->assertSame('Budi Santoso', $baris[0]['B']);
    }

    /**
     * Teks berformat campuran terpecah jadi beberapa <r><t> di dalam satu <si>.
     *
     * Nama yang sebagian dicetak tebal tersimpan begini. Membaca hanya potongan
     * pertama akan memotong nama orang jadi separuh.
     */
    #[Test]
    public function teks_berformat_campuran_digabung_utuh(): void
    {
        $this->buatBerkas();

        $baris = iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('PEGAWAI'), false);

        $this->assertSame('Siti Aminah', $baris[1]['B']);
    }

    #[Test]
    public function sel_string_sebaris_terbaca(): void
    {
        $this->buatBerkas();

        $baris = iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('DOKTER'), false);

        $this->assertSame('Sebaris', $baris[0]['A']);
    }

    #[Test]
    public function sel_kosong_tidak_menggeser_kolom(): void
    {
        $this->buatBerkas();

        $baris = iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('PEGAWAI'), false);

        // Baris 2 melompati kolom C; nilai di D harus tetap terbaca sebagai D.
        $this->assertArrayNotHasKey('C', $baris[0]);
        $this->assertSame('43224', $baris[0]['D']);
    }

    #[Test]
    public function lembar_tak_dikenal_melempar_galat_yang_menyebutkan_pilihannya(): void
    {
        $this->buatBerkas();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PEGAWAI');

        iterator_to_array((new SdmWorkbookReader($this->berkas))->baris('TIDAK ADA'), false);
    }

    // ------------------------------------------------------------- tanggal

    /**
     * Serial Excel diubah jadi tanggal.
     *
     * Kolom TMT berisi angka seperti 43224. Disimpan apa adanya, tabel
     * praktisi memuat "43224" sebagai tanggal mulai bertugas.
     *
     * Titik acuannya 1899-12-30, bukan 1900-01-01 — Excel mewarisi anggapan
     * keliru bahwa 1900 tahun kabisat.
     */
    #[Test]
    #[DataProvider('serialTanggal')]
    public function serial_excel_jadi_tanggal(string $masukan, ?string $diharapkan): void
    {
        $this->buatBerkas();

        $this->assertSame($diharapkan, (new SdmWorkbookReader($this->berkas))->tanggal($masukan));
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function serialTanggal(): array
    {
        return [
            'serial 2018' => ['43224', '2018-05-04'],
            'serial 2019' => ['43656', '2019-07-10'],
            'sudah ISO' => ['2020-03-15', '2020-03-15'],
            'ISO berstempel waktu' => ['2020-03-15 08:00:00', '2020-03-15'],
            'tanda hubung' => ['-', null],
            'kosong' => ['', null],
            'bukan angka' => ['TMT', null],
            'nol' => ['0', null],
            'di luar rentang' => ['999999', null],
        ];
    }
}
