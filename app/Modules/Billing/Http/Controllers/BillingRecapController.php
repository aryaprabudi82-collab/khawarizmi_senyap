<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Services\BillingRecapService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dua layar rekap billing (domain I item D), dipisah menurut sumber
 * datanya karena pembacanya pun berbeda:
 *
 *  - biaya()      : apa yang ditagihkan (charge_lines), digerbangi
 *    ringkasan_tindakan — dibaca manajemen/keuangan.
 *  - pembayaran() : apa yang dibayar (payments), digerbangi
 *    rekap_pembayaran_ralan — dibaca kasir saat tutup kas.
 */
class BillingRecapController
{
    public function __construct(private readonly BillingRecapService $rekap) {}

    public function biaya(Request $request): View
    {
        [$dari, $sampai, $jenisRawat] = $this->saring($request);

        $sumber = $request->query('sumber') ?: null;

        return view('billing::rekap.biaya', [
            'dari' => $dari,
            'sampai' => $sampai,
            'jenisRawat' => $jenisRawat,
            'sumber' => $sumber,
            'perSumber' => $this->rekap->bySource($dari, $sampai, $jenisRawat),
            'perUnit' => $this->rekap->byUnit($dari, $sampai, $jenisRawat),
            'harian' => $sumber ? $this->rekap->dailyBySource($sumber, $dari, $sampai, $jenisRawat) : collect(),
            'perPasien' => $this->rekap->perPatient($dari, $sampai, $sumber, $jenisRawat),
            'rincian' => $this->rekap->detail($dari, $sampai, $sumber, $jenisRawat),
        ]);
    }

    public function pembayaran(Request $request): View
    {
        [$dari, $sampai, $jenisRawat] = $this->saring($request);

        return view('billing::rekap.pembayaran', [
            'dari' => $dari,
            'sampai' => $sampai,
            'jenisRawat' => $jenisRawat,
            'harian' => $this->rekap->paymentsDaily($dari, $sampai, $jenisRawat),
            'perUnit' => $this->rekap->paymentsByUnit($dari, $sampai, $jenisRawat),
            'perPetugas' => $this->rekap->paymentsByReceiver($dari, $sampai, $jenisRawat),
        ]);
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function saring(Request $request): array
    {
        return [
            Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString(),
            Carbon::parse($request->query('sampai', now()->toDateString()))->toDateString(),
            in_array($request->query('jenis_rawat'), ['ralan', 'ranap'], true) ? $request->query('jenis_rawat') : null,
        ];
    }
}
