<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Jenis berkas digital rekam medis (domain M item L).
 *
 * BERBEDA DARI TEMPLATE FORMULIR: yang disemai di sini bukan ISI
 * dokumen melainkan DAFTAR JENIS berkas yang lazim dipindai ke rekam
 * medis mana pun — surat rujukan dari faskes lain, hasil penunjang dari
 * luar, kartu identitas, persetujuan bertanda tangan basah. Tidak ada
 * yang dikarang: jenis-jenis ini ditentukan oleh dokumen apa yang
 * benar-benar datang dari luar rumah sakit, bukan oleh kebijakan
 * internal yang harus disusun komite medik.
 *
 * PENANDA TANDA TANGAN BASAH menjelaskan mengapa berkas pindaian tetap
 * ada meski rekam medisnya elektronik: persetujuan tindakan dan
 * penolakan tindakan ditandatangani pasien di atas kertas, dan
 * pindaiannya yang jadi bukti.
 *
 * PENANDA PERMANEN dipasang pada jenis yang tidak ikut dimusnahkan
 * meski masa simpan pasiennya lewat.
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->types() as $jenis) {
            DocumentType::query()->updateOrCreate(['code' => $jenis['code']], $jenis);
        }

        $this->command?->info('Jenis berkas digital: '.DocumentType::query()->count().' jenis.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function types(): array
    {
        return [
            [
                'code' => 'RUJUKAN-MASUK', 'name' => 'Surat rujukan dari fasilitas lain',
                'category' => 'surat', 'needs_wet_signature' => false, 'is_permanent' => false,
                'note' => 'Datang dari luar; asli tidak bisa dibuat ulang bila hilang.',
            ],
            [
                'code' => 'HASIL-LUAR', 'name' => 'Hasil penunjang dari fasilitas lain',
                'category' => 'hasil-penunjang', 'needs_wet_signature' => false, 'is_permanent' => false,
                'note' => 'Laboratorium, radiologi, atau patologi yang dikerjakan di tempat lain.',
            ],
            [
                'code' => 'PERSETUJUAN-TINDAKAN', 'name' => 'Persetujuan tindakan kedokteran',
                'category' => 'persetujuan', 'needs_wet_signature' => true, 'is_permanent' => true,
                'note' => 'Ditandatangani pasien di atas kertas; pindaiannya yang jadi bukti. '
                    .'Permanen: bukti persetujuan tidak ikut musnah.',
            ],
            [
                'code' => 'PENOLAKAN-TINDAKAN', 'name' => 'Penolakan tindakan kedokteran',
                'category' => 'persetujuan', 'needs_wet_signature' => true, 'is_permanent' => true,
                'note' => 'Penolakan sama pentingnya dengan persetujuan, dan sama-sama perlu dibuktikan.',
            ],
            [
                'code' => 'RESUME-LUAR', 'name' => 'Resume medis dari fasilitas lain',
                'category' => 'ringkasan', 'needs_wet_signature' => false, 'is_permanent' => false,
            ],
            [
                'code' => 'IDENTITAS', 'name' => 'Kartu identitas pasien',
                'category' => 'identitas', 'needs_wet_signature' => false, 'is_permanent' => false,
            ],
            [
                'code' => 'KARTU-PENJAMIN', 'name' => 'Kartu penjaminan (BPJS/asuransi)',
                'category' => 'identitas', 'needs_wet_signature' => false, 'is_permanent' => false,
            ],
            [
                'code' => 'SURAT-KUASA', 'name' => 'Surat kuasa atau pernyataan keluarga',
                'category' => 'persetujuan', 'needs_wet_signature' => true, 'is_permanent' => true,
            ],
            [
                'code' => 'FOTO-KLINIS', 'name' => 'Foto klinis',
                'category' => 'hasil-penunjang', 'needs_wet_signature' => false, 'is_permanent' => false,
                'note' => 'Luka, lesi kulit, atau kondisi yang lebih jelas dilihat daripada diuraikan.',
            ],
            [
                'code' => 'LAINNYA', 'name' => 'Berkas lain',
                'category' => 'lainnya', 'needs_wet_signature' => false, 'is_permanent' => false,
                'note' => 'Keranjang terakhir; kalau sering dipakai, jenisnya perlu ditambah.',
            ],
        ];
    }
}
