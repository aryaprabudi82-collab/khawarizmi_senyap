<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\Billing\Models\CashierShiftClosing;
use App\Modules\Billing\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penutupan shift kasir — mencocokkan uang laci dengan pembayaran tercatat.
 *
 * Inilah yang dijanjikan nama menu "Closing Kasir" Khanza tapi tidak pernah
 * dilakukan tabelnya: `closing_kasir` cuma menyimpan jam shift.
 */
class CashierClosingService
{
    /**
     * Menutup shift.
     *
     * JUMLAH TERCATAT DIHITUNG DI SINI LALU DIBEKUKAN. Kalau ia dibaca
     * ulang saat laporan dibuka, pembayaran yang masuk terlambat atau
     * dibatalkan setelah shift ditutup akan mengubah angka pembanding
     * secara surut — dan petugas yang sudah menandatangani selisih nol
     * tiba-tiba punya selisih yang tidak pernah ia lihat. Yang dibekukan
     * adalah apa yang TERLIHAT saat penutupan, karena itulah yang ia
     * tanda tangani. Sama persis dengan stok sistem pada opname.
     */
    public function close(
        CashierShift $shift,
        string $businessDate,
        string $cashierName,
        float $countedCash,
        ?int $cashierId = null,
        ?string $varianceReason = null,
        ?string $note = null,
    ): CashierShiftClosing {
        if ($countedCash < 0) {
            throw new RuntimeException('Uang yang dihitung tidak bisa negatif.');
        }

        [$mulai, $selesai] = $this->rentang($shift, $businessDate);

        $tercatat = $this->recordedInWindow($mulai, $selesai, $cashierId);

        /*
         * SELISIH TIDAK MEMBLOKIR PENUTUPAN, TAPI WAJIB BERALASAN.
         * Menahan penutupan sampai selisihnya nol akan membuat petugas
         * mengetik angka yang mencocokkan alih-alih angka yang ia hitung —
         * dan sejak itu seluruh catatan kas jadi karangan yang rapi.
         */
        $cocok = bccomp(number_format($countedCash, 2, '.', ''), $tercatat['tunai'], 2) === 0;

        if (! $cocok && trim((string) $varianceReason) === '') {
            throw new RuntimeException(
                'Selisih kas '.$this->selisih($countedCash, $tercatat['tunai'])
                .' wajib disertai penjelasan. Penutupan tidak ditahan — yang perlu adalah '
                .'selisihnya tercatat berikut sebabnya.'
            );
        }

        return CashierShiftClosing::query()->create([
            'closing_number' => $this->allocateNumber(),
            'shift_id' => $shift->getKey(),
            'business_date' => $businessDate,
            'cashier_id' => $cashierId,
            'cashier_name' => $cashierName,
            'window_from' => $mulai,
            'window_until' => $selesai,
            'closed_at' => now(),
            'counted_cash' => $countedCash,
            'recorded_cash' => $tercatat['tunai'],
            'recorded_noncash' => $tercatat['nontunai'],
            'variance_reason' => $cocok ? null : $varianceReason,
            'note' => $note,
        ]);
    }

    /**
     * Pembayaran tercatat pada rentang shift, dipisah tunai dan bukan.
     *
     * Yang bukan tunai TIDAK ikut hitungan selisih laci — kartu dan QRIS
     * tidak pernah masuk laci, dan memasukkannya akan menghasilkan selisih
     * sebesar seluruh transaksi non-tunai setiap hari.
     *
     * @return array{tunai: string, nontunai: string}
     */
    public function recordedInWindow(Carbon $from, Carbon $until, ?int $cashierId = null): array
    {
        /*
         * PEMBAYARAN YANG DIBATALKAN TIDAK IKUT. Uangnya sudah dikembalikan
         * ke pasien, jadi ia tidak ada di laci — memasukkannya berarti
         * menuntut kasir mempertanggungjawabkan uang yang justru sudah
         * benar ia keluarkan, dan setiap pembatalan akan tampak sebagai
         * kekurangan kas sebesar nilainya.
         */
        $kueri = Payment::query()
            ->whereNull('voided_at')
            ->whereBetween('paid_at', [$from, $until]);

        if ($cashierId !== null) {
            $kueri->where('received_by', $cashierId);
        }

        $baris = (clone $kueri)
            ->selectRaw("coalesce(sum(case when method = 'tunai' then amount else 0 end), 0) as tunai,
                         coalesce(sum(case when method <> 'tunai' then amount else 0 end), 0) as nontunai")
            ->first();

        return [
            'tunai' => (string) $baris->tunai,
            'nontunai' => (string) $baris->nontunai,
        ];
    }

    /**
     * Rentang waktu sebuah shift pada satu tanggal.
     *
     * Shift malam menyeberang tengah malam, dan itu bukan kasus langka — ia
     * terjadi setiap hari. Tanpa penanganannya, rentang 22:00–06:00 terbaca
     * sebagai rentang kosong dan tidak satu pun pembayaran masuk hitungan,
     * sehingga kasir malam SELALU tampak kelebihan uang sebesar seluruh
     * penerimaannya.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function rentang(CashierShift $shift, string $businessDate): array
    {
        $mulai = Carbon::parse($businessDate.' '.$shift->start_time);
        $selesai = Carbon::parse($businessDate.' '.$shift->end_time);

        if ($shift->crosses_midnight) {
            $selesai->addDay();
        }

        return [$mulai, $selesai];
    }

    /** Penomoran atomik — pola yang sama dengan InvoiceService. */
    private function allocateNumber(): string
    {
        $prefix = now()->format('Ymd').'-CLS';

        $row = DB::selectOne(
            'INSERT INTO billing.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = billing.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'CLS'.$prefix.'-'.str_pad((string) $row->last_number, 4, '0', STR_PAD_LEFT);
    }

    private function selisih(float $dihitung, string $tercatat): string
    {
        return bcsub(number_format($dihitung, 2, '.', ''), $tercatat, 2);
    }
}
