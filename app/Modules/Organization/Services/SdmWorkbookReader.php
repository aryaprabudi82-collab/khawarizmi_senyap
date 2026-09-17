<?php

namespace App\Modules\Organization\Services;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Membaca berkas kepegawaian RSUI (.xlsx) tanpa pustaka pihak ketiga.
 *
 * MENGAPA TIDAK MEMAKAI PHPSPREADSHEET. Menambah dependensi berukuran puluhan
 * megabyte untuk membaca dua lembar sekali seumur migrasi adalah harga yang
 * tidak sepadan — apalagi dependensi itu akan ikut ke setiap `composer
 * install` di server produksi selamanya. Sebuah .xlsx adalah arsip ZIP berisi
 * XML, dan ekstensi `zip` serta `simplexml` sudah ada di PHP ini.
 *
 * YANG DIBACA HANYA APA YANG DIBUTUHKAN: nilai sel dan tabel string bersama.
 * Rumus, gaya, pivot, dan grafik diabaikan.
 *
 * TANGGAL EXCEL BUKAN TANGGAL. Kolom TMT berisi angka seperti 43224 — hitungan
 * hari sejak 1899-12-30 menurut sistem serial Excel. Disimpan apa adanya, tabel
 * praktisi akan memuat "43224" sebagai tanggal mulai bertugas.
 */
class SdmWorkbookReader
{
    /**
     * Awal penanggalan serial Excel.
     *
     * 1899-12-30, bukan 1900-01-01 — Excel mewarisi anggapan keliru bahwa
     * 1900 tahun kabisat, dan pergeseran dua hari itu sudah jadi bagian
     * formatnya selama tiga dekade.
     */
    private const EPOCH_EXCEL = '1899-12-30';

    /** @var array<int, string> */
    private array $stringBersama = [];

    /** @var array<string, string> nama lembar => berkas XML-nya */
    private array $lembar = [];

    public function __construct(private readonly string $berkas)
    {
        $this->muatIndeks();
    }

    /** @return list<string> */
    public function daftarLembar(): array
    {
        return array_keys($this->lembar);
    }

    /**
     * Membaca satu lembar sebagai baris asosiatif berkunci huruf kolom.
     *
     * BARIS PERTAMA DIANGGAP HEADER dan tidak ikut di-yield — kedua lembar
     * yang dipakai memang berheader.
     *
     * @return Generator<int, array<string, string>>
     */
    public function baris(string $namaLembar): Generator
    {
        if (! isset($this->lembar[$namaLembar])) {
            throw new RuntimeException(
                "Lembar '{$namaLembar}' tidak ada. Yang tersedia: ".implode(', ', $this->daftarLembar())
            );
        }

        $xml = $this->ambil($this->lembar[$namaLembar]);
        $sheet = simplexml_load_string($xml);

        if ($sheet === false) {
            throw new RuntimeException("Lembar '{$namaLembar}' tidak bisa diurai.");
        }

        $nomor = 0;

        foreach ($sheet->sheetData->row as $row) {
            $nomor++;

            if ($nomor === 1) {
                continue;
            }

            $sel = [];

            foreach ($row->c as $c) {
                $nilai = $this->nilaiSel($c);

                if ($nilai === '') {
                    continue;
                }

                $kolom = preg_replace('/[0-9]/', '', (string) $c['r']);
                $sel[(string) $kolom] = $nilai;
            }

            if ($sel !== []) {
                yield $sel;
            }
        }
    }

    /**
     * Mengubah serial Excel jadi tanggal ISO.
     *
     * Nilai yang sudah berbentuk tanggal dikembalikan apa adanya; yang bukan
     * angka maupun tanggal mengembalikan null, bukan tanggal karangan.
     */
    public function tanggal(string $nilai): ?string
    {
        $v = trim($nilai);

        if ($v === '' || $v === '-') {
            return null;
        }

        if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $v, $cocok)) {
            return $cocok[1];
        }

        if (! ctype_digit($v)) {
            return null;
        }

        $serial = (int) $v;

        // Di luar rentang ini bukan tanggal: 1 = 1899-12-31, 80000 ≈ tahun 2119.
        if ($serial < 1 || $serial > 80000) {
            return null;
        }

        return date('Y-m-d', strtotime(self::EPOCH_EXCEL.' +'.$serial.' days'));
    }

    private function nilaiSel(SimpleXMLElement $c): string
    {
        $tipe = (string) $c['t'];

        // Sel string sebaris (t="inlineStr") menyimpan teksnya sendiri.
        if ($tipe === 'inlineStr') {
            return trim((string) $c->is->t);
        }

        $v = (string) $c->v;

        if ($v === '') {
            return '';
        }

        if ($tipe === 's') {
            $i = (int) $v;

            return $this->stringBersama[$i] ?? '';
        }

        return trim($v);
    }

    /**
     * Memetakan nama lembar ke berkas XML-nya.
     *
     * TIDAK BISA DITEBAK DARI NOMOR. Urutan lembar di workbook.xml sama sekali
     * tidak sejalan dengan penomoran sheet1.xml, sheet2.xml, dan seterusnya —
     * pada berkas ini lembar kedua justru sheet2.xml sementara lembar keempat
     * adalah sheet4.xml, dan lembar ketiga sheet3.xml memuat pivot. Pemetaan
     * yang benar hanya ada di workbook.xml.rels lewat r:id.
     */
    private function muatIndeks(): void
    {
        $wbXml = $this->ambil('xl/workbook.xml');
        $relXml = $this->ambil('xl/_rels/workbook.xml.rels');

        $wb = simplexml_load_string($wbXml);
        $rel = simplexml_load_string($relXml);

        if ($wb === false || $rel === false) {
            throw new RuntimeException("Struktur workbook tidak terbaca: {$this->berkas}");
        }

        $target = [];

        foreach ($rel->Relationship as $r) {
            $target[(string) $r['Id']] = (string) $r['Target'];
        }

        foreach ($wb->sheets->sheet as $sh) {
            $rid = (string) $sh->attributes('r', true)['id'];

            if (isset($target[$rid])) {
                $this->lembar[(string) $sh['name']] = 'xl/'.ltrim($target[$rid], '/');
            }
        }

        $ssXml = $this->ambil('xl/sharedStrings.xml', wajib: false);

        if ($ssXml === null) {
            return;
        }

        $ss = simplexml_load_string($ssXml);

        if ($ss === false) {
            return;
        }

        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $this->stringBersama[] = trim((string) $si->t);

                continue;
            }

            // Teks berformat campuran terpecah jadi beberapa <r><t>.
            $gabung = '';

            foreach ($si->r as $r) {
                $gabung .= (string) $r->t;
            }

            $this->stringBersama[] = trim($gabung);
        }
    }

    private function ambil(string $jalur, bool $wajib = true): ?string
    {
        if (! is_file($this->berkas)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$this->berkas}");
        }

        $zip = new ZipArchive();

        if ($zip->open($this->berkas) !== true) {
            throw new RuntimeException("Bukan berkas xlsx yang sah: {$this->berkas}");
        }

        $isi = $zip->getFromName($jalur);
        $zip->close();

        if ($isi === false) {
            if ($wajib) {
                throw new RuntimeException("Bagian '{$jalur}' tidak ada di {$this->berkas}");
            }

            return null;
        }

        return $isi;
    }
}
