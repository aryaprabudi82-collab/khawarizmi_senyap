<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\Billing\Models\CashierShiftClosing;
use App\Modules\Billing\Services\CashierClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Layar penutupan shift kasir.
 *
 * DITEMUKAN SAAT VERIFIKASI DOMAIN I: mesin penutupannya sudah dibangun
 * lengkap — CashierClosingService, tabel, CHECK constraint, sepuluh uji —
 * tapi TIDAK ADA LAYARNYA. Kasir tidak bisa menutup shift lewat aplikasi;
 * satu-satunya jalan adalah memanggil service dari kode.
 *
 * Itu bentuk kelalaian yang khas dan berbahaya: seluruh ujinya hijau, karena
 * uji memanggil service langsung. Yang tidak diuji siapa pun adalah apakah
 * ada jalan bagi manusia untuk menjalankannya.
 */
class CashierClosingController
{
    public function __construct(private readonly CashierClosingService $penutupan) {}

    public function index(Request $request): View
    {
        $tanggal = $request->query('tanggal', now()->toDateString());

        $shift = CashierShift::query()->where('is_active', true)->orderBy('start_time')->get();

        /*
         * Untuk tiap shift ditampilkan jumlah yang TERCATAT sistem pada
         * rentangnya — supaya petugas tahu angka pembandingnya sebelum
         * menghitung laci. Ini bukan mengisi jawabannya: yang diketik tetap
         * hasil hitungan fisik, dan selisihnya justru yang dicari.
         */
        $tercatat = [];

        foreach ($shift as $s) {
            [$dari, $sampai] = $this->penutupan->rentang($s, $tanggal);
            $tercatat[$s->id] = $this->penutupan->recordedInWindow($dari, $sampai);
        }

        return view('billing::penutupan.index', [
            'shift' => $shift,
            'tercatat' => $tercatat,
            'tanggal' => $tanggal,
            'riwayat' => CashierShiftClosing::query()->with('shift')
                ->orderByDesc('business_date')->orderByDesc('id')->limit(30)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer', Rule::exists(CashierShift::class, 'id')],
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'variance_reason' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'shift_id' => 'shift', 'business_date' => 'tanggal',
            'counted_cash' => 'uang yang dihitung', 'variance_reason' => 'penjelasan selisih',
        ]);

        $shift = CashierShift::query()->findOrFail($data['shift_id']);

        try {
            $tutup = $this->penutupan->close(
                $shift,
                $data['business_date'],
                $request->user()?->name ?? 'Tanpa nama',
                (float) $data['counted_cash'],
                $request->user()?->getKey(),
                $data['variance_reason'] ?? null,
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses',
            'Shift '.$shift->name.' ditutup ('.$tutup->closing_number.'). Selisih: '
            .number_format((float) $tutup->selisih(), 2, ',', '.').'.');
    }
}
