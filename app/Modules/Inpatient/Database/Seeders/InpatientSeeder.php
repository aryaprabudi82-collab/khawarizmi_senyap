<?php

namespace App\Modules\Inpatient\Database\Seeders;

use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Kamar dan bed minimum supaya alur rawat inap bisa dijalankan di
 * lingkungan pengembangan: admisi, siklus bed, dan sejak domain I item A
 * juga biaya kamar yang masuk ke tagihan. Tanpa ini modul inpatient sama
 * sekali tidak bisa dicoba pada basis data yang baru di-seed — satu-satunya
 * konteks berdata referensi yang belum punya seeder.
 *
 * Nomor kamar dan tarifnya contoh, bukan denah maupun tarif RSP UI yang
 * sebenarnya.
 */
class InpatientSeeder extends Seeder
{
    public function run(): void
    {
        $kamar = [
            ['VIP-01', 'vip', 1_200_000, 1],
            ['K1-01', 'kelas-1', 600_000, 2],
            ['K2-01', 'kelas-2', 400_000, 4],
            ['K3-01', 'kelas-3', 250_000, 6],
            ['ICU-01', 'icu', 1_500_000, 2],
            ['ISO-01', 'isolasi', 800_000, 1],
        ];

        $jumlahBed = 0;

        foreach ($kamar as [$nomor, $kelas, $tarif, $bedPerKamar]) {
            $ruang = Room::query()->updateOrCreate(['room_number' => $nomor], [
                'room_class' => $kelas,
                'daily_rate' => $tarif,
                'is_active' => true,
            ]);

            foreach (range(1, $bedPerKamar) as $urut) {
                Bed::query()->updateOrCreate(
                    ['room_id' => $ruang->id, 'bed_number' => chr(64 + $urut)],
                    ['status' => Bed::STATUS_TERSEDIA]
                );
                $jumlahBed++;
            }
        }

        $this->command?->info('Rawat inap: ' . count($kamar) . ' kamar dan ' . $jumlahBed . ' bed contoh disiapkan.');
        $this->command?->warn('Denah kamar dan tarif sesungguhnya harus dimasukkan sebelum dipakai melayani pasien.');
    }
}
