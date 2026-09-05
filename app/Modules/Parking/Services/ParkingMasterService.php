<?php

namespace App\Modules\Parking\Services;

use App\Modules\Parking\Models\BarcodeCard;
use App\Modules\Parking\Models\Rate;
use App\Modules\Parking\Models\Session;

/**
 * Data master parkir: jenis/tarif dan stok kartu barcode. Keduanya
 * dikelola admin yang sama di layar yang sama — di Khanza tabel
 * parkir_barcode cuma pemetaan kode_barcode->nomer_kartu tanpa timestamp,
 * jadi itu pendaftaran stok kartu fisik, bukan transaksi gerbang.
 */
class ParkingMasterService
{
    public function createRate(array $data): Rate
    {
        return Rate::query()->create($data + ['is_active' => true]);
    }

    public function updateRate(Rate $rate, array $data): Rate
    {
        $rate->update($data);

        return $rate->refresh();
    }

    public function registerCard(string $barcode, string $cardNumber): BarcodeCard
    {
        return BarcodeCard::query()->create([
            'barcode' => strtoupper(trim($barcode)),
            'card_number' => strtoupper(trim($cardNumber)),
            'is_active' => true,
        ]);
    }

    /** Kartu hilang/rusak dinonaktifkan, tidak dihapus — sesi lama masih menunjuk ke sini. */
    public function deactivateCard(BarcodeCard $card): BarcodeCard
    {
        if (Session::query()->terbuka()->where('barcode_card_id', $card->id)->exists()) {
            throw new ParkingException("Kartu {$card->card_number} masih menempel pada kendaraan yang belum keluar, selesaikan sesinya dulu.");
        }

        $card->update(['is_active' => false]);

        return $card->refresh();
    }
}
