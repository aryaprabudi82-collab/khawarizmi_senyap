<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\LetterClassification;
use Illuminate\Support\Facades\DB;

/**
 * Nomor surat keluar: berurut PER KLASIFIKASI PER TAHUN.
 *
 * Nomor surat dinas dibaca sebagai alamat arsip — urutan, kode perihal,
 * satuan kerja, bulan, tahun. Satu hitungan tunggal untuk seluruh surat
 * menghasilkan nomor yang tidak memberi tahu apa pun tentang isinya, dan
 * membuat penemuan kembali bergantung pada mesin pencari alih-alih pada
 * nomornya sendiri.
 *
 * NOMOR TIDAK PERNAH DIPAKAI ULANG. Surat yang batal meninggalkan lubang
 * pada urutan, dan lubang itu informasi: sebuah nomor pernah diterbitkan.
 * Memakainya ulang membuat dua dokumen bernomor sama — kerusakan yang
 * tidak bisa diperbaiki belakangan.
 */
class LetterNumberAllocator
{
    /** Bulan romawi, sebagaimana lazim pada nomor surat dinas. */
    private const ROMAWI = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];

    public function __construct(private readonly string $satuanKerja = 'RSPUI') {}

    public function allocate(LetterClassification $classification, ?\DateTimeInterface $tanggal = null): string
    {
        $tanggal ??= now();
        $tahun = (int) $tanggal->format('Y');
        $bulan = (int) $tanggal->format('n');

        // INSERT ... ON CONFLICT, bukan baca-lalu-tulis: dua petugas yang
        // menerbitkan surat pada detik yang sama tidak boleh mendapat nomor
        // yang sama. Pola yang sama dipakai NumberAllocator.
        $baris = DB::selectOne(
            'INSERT INTO correspondence.letter_number_sequences (classification_id, year, last_number, updated_at)
             VALUES (?, ?, 1, now())
             ON CONFLICT (classification_id, year) DO UPDATE
                SET last_number = correspondence.letter_number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$classification->id, $tahun]
        );

        return sprintf(
            '%s/%s/%s/%s/%d',
            str_pad((string) $baris->last_number, 3, '0', STR_PAD_LEFT),
            $classification->code,
            $this->satuanKerja,
            self::ROMAWI[$bulan],
            $tahun
        );
    }
}
