<?php

namespace App\Modules\Pharmacy\Database\Seeders;

use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\StockLedger;
use Illuminate\Database\Seeder;

/**
 * Obat contoh dan stok awal untuk pengembangan.
 *
 * Bukan formularium RSP UI. Katalog sebenarnya diimpor terpisah berikut
 * kode KFA Kemenkes yang dibutuhkan saat resep dikirim ke SATUSEHAT.
 */
class PharmacySeeder extends Seeder
{
    public function run(): void
    {
        $depo = StockLocation::query()->updateOrCreate(
            ['code' => 'DEPO-RJ'],
            [
                'name' => 'Depo Farmasi Rawat Jalan',
                'kind' => 'depo',
                'unit_id' => Unit::query()->where('code', 'FARMASI')->value('id'),
                'is_active' => true,
            ]
        );

        StockLocation::query()->updateOrCreate(
            ['code' => 'GUDANG'],
            ['name' => 'Gudang Farmasi Pusat', 'kind' => 'gudang', 'is_active' => true]
        );

        // [kode, nama, generik, bentuk, kekuatan, satuan, harga, narkotika, psikotropika]
        $obat = [
            ['OBT-001', 'Amoksisilin 500 mg', 'Amoksisilin', 'kapsul', '500 mg', 'kapsul', 1500, false, false],
            ['OBT-002', 'Parasetamol 500 mg', 'Parasetamol', 'tablet', '500 mg', 'tablet', 500, false, false],
            ['OBT-003', 'Amlodipin 10 mg', 'Amlodipin', 'tablet', '10 mg', 'tablet', 1200, false, false],
            ['OBT-004', 'Metformin 500 mg', 'Metformin', 'tablet', '500 mg', 'tablet', 900, false, false],
            ['OBT-005', 'Omeprazol 20 mg', 'Omeprazol', 'kapsul', '20 mg', 'kapsul', 2500, false, false],
            ['OBT-006', 'Salbutamol Inhaler', 'Salbutamol', 'inhaler', '100 mcg', 'buah', 85000, false, false],
            ['OBT-007', 'Ibuprofen 400 mg', 'Ibuprofen', 'tablet', '400 mg', 'tablet', 800, false, false],
            ['OBT-008', 'Sefiksim 100 mg', 'Sefiksim', 'kapsul', '100 mg', 'kapsul', 4500, false, false],
            ['OBT-009', 'Kodein 10 mg', 'Kodein', 'tablet', '10 mg', 'tablet', 3500, true, false],
            ['OBT-010', 'Diazepam 2 mg', 'Diazepam', 'tablet', '2 mg', 'tablet', 2800, false, true],
            ['BHP-001', 'Kasa Steril 10x10', null, 'lembar', null, 'lembar', 3000, false, false],
            ['BHP-002', 'Spuit 3 cc', null, 'buah', null, 'buah', 2000, false, false],
        ];

        $ledger = app(StockLedger::class);

        foreach ($obat as [$kode, $nama, $generik, $bentuk, $kekuatan, $satuan, $harga, $narkotika, $psikotropika]) {
            $d = Drug::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama,
                'generic_name' => $generik,
                'category' => str_starts_with($kode, 'BHP') ? 'bhp' : 'obat',
                'form' => $bentuk,
                'strength' => $kekuatan,
                'unit' => $satuan,
                'sell_price' => $harga,
                'minimum_stock' => 50,
                'is_narcotic' => $narkotika,
                'is_psychotropic' => $psikotropika,
                'is_high_alert' => $narkotika || $psikotropika,
                'is_active' => true,
            ]);

            // Dua batch dengan kedaluwarsa berbeda, supaya kaidah FEFO terlihat.
            foreach ([[300, '+8 months', 'B2026A'], [500, '+20 months', 'B2026B']] as [$jumlah, $kadaluarsa, $batch]) {
                $sudahAda = \App\Modules\Pharmacy\Models\StockBatch::query()
                    ->where('drug_id', $d->id)->where('batch_number', $batch)->exists();

                if ($sudahAda) {
                    continue;
                }

                $ledger->receive(
                    drugId: $d->id,
                    locationId: $depo->id,
                    batchNumber: $batch,
                    quantity: $jumlah,
                    expiryDate: now()->modify($kadaluarsa)->toDateString(),
                    costPrice: $harga * 0.7,
                    note: 'Stok awal seeder pengembangan',
                );
            }
        }

        $this->command?->info('Farmasi: ' . count($obat) . ' obat/BHP dan stok awal dua batch per item disiapkan.');
        $this->command?->warn('Formularium dan kode KFA resmi harus diimpor sebelum dipakai melayani pasien.');
    }
}
