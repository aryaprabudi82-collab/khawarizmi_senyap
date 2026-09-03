<?php

namespace App\Modules\Envlab\Http\Controllers;

use App\Modules\Envlab\Models\SampleTest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * rekap_pelayanan_lab_kesehatan_lingkungan — satu-satunya kode rekap yang
 * benar-benar punya access flag di Khanza (baris 48 sheet2). "Rekap
 * Pembayaran Lab Kesling" (baris 50) TIDAK punya access flag sendiri di
 * sumber Khanza-nya (kolom itu kosong) — makanya rekap pembayaran
 * digabung satu layar dengan rekap pelayanan di sini, bukan kode/gerbang
 * terpisah.
 */
class RecapController
{
    public function index(Request $request): View
    {
        $dari = CarbonImmutable::parse($request->query('dari', now()->startOfMonth()->toDateString()))->startOfDay();
        $sampai = CarbonImmutable::parse($request->query('sampai', now()->toDateString()))->endOfDay();

        $dasar = SampleTest::query()->whereBetween('requested_at', [$dari, $sampai]);

        $perStatus = (clone $dasar)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $pembayaran = (clone $dasar)
            ->selectRaw("payment_status, count(*) as jumlah, coalesce(sum(price), 0) as total")
            ->groupBy('payment_status')
            ->get()
            ->keyBy('payment_status');

        $perPelanggan = (clone $dasar)
            ->selectRaw('customer_name, count(*) as jumlah')
            ->groupBy('customer_name')
            ->orderByDesc('jumlah')
            ->get();

        return view('envlab::recap.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'perStatus' => $perStatus,
            'pembayaran' => $pembayaran,
            'perPelanggan' => $perPelanggan,
            'total' => (clone $dasar)->count(),
        ]);
    }
}
