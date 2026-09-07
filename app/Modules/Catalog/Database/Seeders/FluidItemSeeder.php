<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\FluidItem;
use Illuminate\Database\Seeder;

/**
 * Master jenis cairan masuk & keluar (domain M item E).
 *
 * Isinya mengikuti kolom catatan_keseimbangan_cairan dan
 * catatan_cairan_hemodialisa Khanza — yang di sana jadi dua tabel dengan
 * kolom berbeda, di sini jadi baris pada satu master.
 *
 * IWL ADA SEBAGAI JENIS TERSENDIRI, bukan dihitung diam-diam dari berat
 * badan: rumusnya berbeda antar pedoman dan bertambah saat demam, dan
 * angka yang tidak pernah dinyatakan siapa pun tidak boleh ikut menentukan
 * pemberian cairan.
 */
class FluidItemSeeder extends Seeder
{
    public function run(): void
    {
        $jenis = [
            // kode, nama, arah, konteks, urutan
            ['infus', 'Infus', FluidItem::MASUK, null, 1],
            ['transfusi', 'Transfusi', FluidItem::MASUK, null, 2],
            ['minum', 'Minum', FluidItem::MASUK, null, 3],
            ['ngt-masuk', 'Cairan lewat NGT', FluidItem::MASUK, null, 4],
            ['obat-injeksi', 'Cairan obat injeksi', FluidItem::MASUK, null, 5],

            ['urine', 'Urine', FluidItem::KELUAR, null, 1],
            ['drain', 'Drain', FluidItem::KELUAR, null, 2],
            ['muntah', 'Muntah', FluidItem::KELUAR, null, 3],
            ['ngt-keluar', 'Cairan keluar lewat NGT', FluidItem::KELUAR, null, 4],
            ['bab-cair', 'BAB cair', FluidItem::KELUAR, null, 5],
            ['perdarahan', 'Perdarahan', FluidItem::KELUAR, null, 6],
            ['iwl', 'Insensible water loss (IWL)', FluidItem::KELUAR, null, 7],

            // Khusus hemodialisa — mengikuti catatan_cairan_hemodialisa.
            ['sisa-priming', 'Sisa priming', FluidItem::MASUK, 'hemodialisa', 10],
            ['wash-out', 'Wash out', FluidItem::MASUK, 'hemodialisa', 11],
            ['ultrafiltrasi', 'Ultrafiltrasi', FluidItem::KELUAR, 'hemodialisa', 10],
        ];

        foreach ($jenis as [$kode, $nama, $arah, $konteks, $urut]) {
            FluidItem::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama,
                'direction' => $arah,
                'care_context' => $konteks,
                'sequence' => $urut,
                'is_active' => true,
            ]);
        }

        $this->command?->info('Master cairan: ' . FluidItem::query()->count() . ' jenis.');
    }
}
