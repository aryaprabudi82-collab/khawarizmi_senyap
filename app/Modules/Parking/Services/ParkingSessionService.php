<?php

namespace App\Modules\Parking\Services;

use App\Modules\Parking\Models\BarcodeCard;
use App\Modules\Parking\Models\Rate;
use App\Modules\Parking\Models\Session;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Satu sesi parkir: masuk (checkIn) lalu keluar (checkOut). Biayanya
 * dihitung dan dibekukan saat keluar, bukan dihitung ulang setiap kali
 * dibaca — kalau tarifnya berubah bulan depan, sesi lama harus tetap
 * menunjukkan angka yang sungguh ditagihkan waktu itu.
 */
class ParkingSessionService
{
    public function checkIn(Rate $rate, string $vehicleNumber, User $actor, ?BarcodeCard $card = null, ?string $notes = null): Session
    {
        $vehicleNumber = strtoupper(trim($vehicleNumber));

        if (! $rate->is_active) {
            throw new ParkingException("Jenis parkir {$rate->name} sudah nonaktif, tidak bisa dipakai untuk kendaraan masuk.");
        }

        if ($card !== null && ! $card->is_active) {
            throw new ParkingException("Kartu {$card->card_number} nonaktif (hilang/rusak), tidak boleh dipakai.");
        }

        if ($card !== null && Session::query()->terbuka()->where('barcode_card_id', $card->id)->exists()) {
            throw new ParkingException("Kartu {$card->card_number} masih dipakai kendaraan lain yang belum keluar.");
        }

        if (Session::query()->terbuka()->where('vehicle_number', $vehicleNumber)->exists()) {
            throw new ParkingException("Kendaraan {$vehicleNumber} tercatat masih di dalam, belum ada catatan keluarnya.");
        }

        return Session::query()->create([
            'rate_id' => $rate->id,
            'barcode_card_id' => $card?->id,
            'vehicle_number' => $vehicleNumber,
            'entered_at' => now(),
            'entered_by' => $actor->id,
            'notes' => $notes,
        ]);
    }

    /** Menutup sesi: hitung durasi, bekukan biaya sesuai tarif yang berlaku saat itu. */
    public function checkOut(Session $session, User $actor): Session
    {
        if (! $session->isOpen()) {
            throw new ParkingException("Sesi kendaraan {$session->vehicle_number} sudah ditutup pada {$session->exited_at->format('d-m-Y H:i')}.");
        }

        return DB::transaction(function () use ($session, $actor): Session {
            $keluar = now();
            $menit = (int) ceil($session->entered_at->diffInSeconds($keluar) / 60);

            $session->update([
                'exited_at' => $keluar,
                'duration_minutes' => $menit,
                'total_fee' => $session->rate->feeFor($menit),
                'exited_by' => $actor->id,
            ]);

            return $session->refresh();
        });
    }

    /** Pencarian sesi terbuka lewat nomor kartu atau nomor kendaraan — yang dipegang petugas di gerbang keluar. */
    public function cariSesiTerbuka(string $kunci): ?Session
    {
        $kunci = strtoupper(trim($kunci));

        return Session::query()
            ->terbuka()
            ->with('rate', 'barcodeCard')
            ->where(function ($q) use ($kunci) {
                $q->where('vehicle_number', $kunci)
                    ->orWhereIn('barcode_card_id', BarcodeCard::query()
                        ->where('card_number', $kunci)
                        ->orWhere('barcode', $kunci)
                        ->select('id'));
            })
            ->first();
    }
}
