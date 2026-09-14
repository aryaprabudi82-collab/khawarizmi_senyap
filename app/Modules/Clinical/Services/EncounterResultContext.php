<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hasil penunjang satu kunjungan — lab, radiologi, patologi anatomi.
 *
 * MENGAPA LAYAR RME PERLU INI. Sebelum ini, layar pemeriksaan hanya bisa
 * MEMBUAT order; hasilnya tidak bisa dilihat dari sana sama sekali. Dokter
 * yang hendak menuliskan asesmen harus berpindah ke layar Order, mencari
 * kunjungan yang sama, membaca hasilnya, lalu kembali — dan sepanjang itu
 * catatan yang sedang diketik ditinggalkan. Yang lebih buruk: tidak ada
 * yang mencegah asesmen ditulis tanpa pernah melihat hasilnya.
 *
 * MEMBACA VIEW TERBITAN, BUKAN TABEL. Uji batas konteks melarang Clinical
 * menyentuh `orders.lab_radiology_orders` langsung, dan larangan itu benar:
 * pembacaan langsung berarti tiap perubahan kolom di konteks Order
 * diam-diam merusak layar RME tanpa ada yang memperingatkan.
 */
class EncounterResultContext
{
    public const KATEGORI_LAB = 'lab';

    public const KATEGORI_RADIOLOGI = 'radiologi';

    public const KATEGORI_PA = 'pa';

    /**
     * Order penunjang satu kunjungan, termasuk yang BELUM ada hasilnya.
     *
     * Yang belum berhasil ikut dikembalikan, dan itu disengaja: order yang
     * sudah diminta tapi hasilnya belum keluar adalah informasi klinis
     * tersendiri — dokter perlu tahu ia sedang menunggu sesuatu, bukan
     * menyimpulkan tidak ada pemeriksaan.
     *
     * @return Collection<int, object>
     */
    public function pesanan(int $registrationId, string $kategori): Collection
    {
        return collect(DB::select(
            'SELECT order_id, order_number, category, status,
                    requesting_practitioner_name, requested_at,
                    verified_at, processed_at, resulted_at
               FROM orders.v_order_summary
              WHERE registration_id = ?
                AND category = ?
              ORDER BY requested_at DESC',
            [$registrationId, $kategori]
        ));
    }

    /**
     * Butir hasil satu kunjungan berikut rentang rujukan dan penanda abnormal.
     *
     * Rentang rujukan ikut dibawa karena angka hasil tanpa rentangnya tidak
     * bisa dinilai siapa pun — 13,5 bermakna berbeda untuk hemoglobin dan
     * untuk leukosit, dan pembaca yang harus mengingat rentangnya sendiri
     * cepat atau lambat akan salah.
     *
     * @return Collection<int, object>
     */
    public function hasil(int $registrationId, string $kategori): Collection
    {
        return collect(DB::select(
            'SELECT result_id, order_id, order_number, test_code, test_name,
                    result_type, unit, reference_low, reference_high, reference_text,
                    result_numeric, result_text, result_notes, is_abnormal,
                    specimen_type, modality, clinical_notes,
                    resulted_at, verified_at, verified_by_name
               FROM orders.v_order_result
              WHERE registration_id = ?
                AND category = ?
              ORDER BY resulted_at DESC NULLS LAST, test_name',
            [$registrationId, $kategori]
        ));
    }

    /**
     * Jumlah order per kategori — untuk lencana angka di kepala panel.
     *
     * @return array{lab:int, radiologi:int, pa:int}
     */
    public function jumlahPesanan(int $registrationId): array
    {
        $baris = DB::select(
            'SELECT category, COUNT(*) AS n
               FROM orders.v_order_summary
              WHERE registration_id = ?
              GROUP BY category',
            [$registrationId]
        );

        $hasil = [self::KATEGORI_LAB => 0, self::KATEGORI_RADIOLOGI => 0, self::KATEGORI_PA => 0];

        foreach ($baris as $b) {
            if (array_key_exists($b->category, $hasil)) {
                $hasil[$b->category] = (int) $b->n;
            }
        }

        return $hasil;
    }
}
