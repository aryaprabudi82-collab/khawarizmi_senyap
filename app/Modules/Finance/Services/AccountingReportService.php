<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\PeriodClosing;
use App\Modules\Finance\Models\PeriodClosingLine;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pemetaan uang ke bagan akun dan penutupan periode (domain I item E).
 *
 * Yang dibangun di sini MEKANISMENYA. Bagan akun yang ada baru empat akun
 * contoh dari seeder, bukan bagan akun RSP UI yang sebenarnya — jadi
 * tidak ada pemetaan bawaan yang diisi diam-diam. Uang yang belum
 * dipetakan muncul apa adanya sebagai "belum dipetakan": disembunyikan
 * atau ditebak jauh lebih berbahaya daripada terlihat kosong, karena
 * laporan keuangan yang tampak rapi padahal ada uang tak terpetakan itu
 * menyesatkan.
 */
class AccountingReportService
{
    public function __construct(private readonly BillingDetailContext $billing) {}

    /**
     * Uang masuk per akun bayar — pembayaran_akun_bayar 1-5.
     *
     * @return Collection<int, array{key: string, total: float, jumlah: int, account_code: ?string, account_name: ?string}>
     */
    public function paymentsByAccount(string $from, string $until): Collection
    {
        return $this->mapToAccounts(
            $this->billing->paymentsByMethod($from, $until),
            AccountMapping::KIND_CARA_BAYAR
        );
    }

    /** Pendapatan per akun — pendapatan_per_akun. */
    public function revenueByAccount(string $from, string $until): Collection
    {
        return $this->mapToAccounts(
            $this->billing->chargesBySource($from, $until),
            AccountMapping::KIND_SUMBER
        );
    }

    /** Berapa banyak uang yang belum punya akun — angka yang harus dilihat sebelum menutup periode. */
    public function unmappedTotal(string $from, string $until): float
    {
        return round(
            $this->paymentsByAccount($from, $until)->whereNull('account_code')->sum('total')
            + $this->revenueByAccount($from, $until)->whereNull('account_code')->sum('total'),
            2
        );
    }

    /**
     * pendapatan_per_akun_closing — menutup satu periode dan MEMBEKUKAN
     * angkanya.
     *
     * Setelah ditutup, laporan periode itu dibaca dari baris yang
     * dibekukan, bukan dihitung ulang. Koreksi tagihan yang masuk
     * belakangan tidak lagi diam-diam mengubah buku yang sudah ditutup;
     * kalau memang perlu, periodenya dibuka kembali secara sadar dan
     * tercatat siapa serta alasannya.
     *
     * @throws FinanceException
     */
    public function closePeriod(int $year, int $month, User $actor, ?string $note = null): PeriodClosing
    {
        if ($month < 1 || $month > 12) {
            throw new FinanceException('Bulan periode harus antara 1 dan 12.');
        }

        $awal = Carbon::create($year, $month, 1)->startOfMonth();

        if ($awal->isFuture()) {
            throw new FinanceException('Periode yang belum berjalan tidak bisa ditutup.');
        }

        if ($this->activeClosing($year, $month) !== null) {
            throw new FinanceException("Periode {$month}-{$year} sudah ditutup. Buka kembali dulu kalau memang perlu diubah.");
        }

        $dari = $awal->toDateString();
        $sampai = $awal->copy()->endOfMonth()->toDateString();

        return DB::transaction(function () use ($year, $month, $actor, $note, $dari, $sampai): PeriodClosing {
            $closing = PeriodClosing::query()->create([
                'period_year' => $year,
                'period_month' => $month,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'note' => $note,
            ]);

            foreach ([
                AccountMapping::KIND_CARA_BAYAR => $this->paymentsByAccount($dari, $sampai),
                AccountMapping::KIND_SUMBER => $this->revenueByAccount($dari, $sampai),
            ] as $kind => $baris) {
                foreach ($baris as $b) {
                    PeriodClosingLine::query()->create([
                        'period_closing_id' => $closing->id,
                        'account_id' => $b['account_id'],
                        'kind' => $kind,
                        'key' => $b['key'],
                        'total' => $b['total'],
                    ]);
                }
            }

            return $closing->refresh();
        });
    }

    /** Membuka kembali periode yang sudah ditutup — selalu dengan alasan, dan tercatat. */
    public function reopenPeriod(PeriodClosing $closing, string $reason, User $actor): PeriodClosing
    {
        if ($closing->isReopened()) {
            throw new FinanceException('Periode ini sudah dibuka kembali sebelumnya.');
        }

        $closing->update([
            'reopened_at' => now(),
            'reopened_by' => $actor->id,
            'reopen_reason' => $reason,
        ]);

        return $closing->refresh();
    }

    public function activeClosing(int $year, int $month): ?PeriodClosing
    {
        return PeriodClosing::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('reopened_at')
            ->first();
    }

    /**
     * Menempelkan akun pada tiap baris. Yang belum dipetakan tetap
     * dikembalikan dengan akun null — bukan dibuang.
     */
    private function mapToAccounts(Collection $baris, string $kind): Collection
    {
        $pemetaan = AccountMapping::query()
            ->with('account')
            ->where('kind', $kind)
            ->get()
            ->keyBy('key');

        return $baris->map(function (object $b) use ($pemetaan): array {
            $akun = $pemetaan->get($b->key)?->account;

            return [
                'key' => $b->key,
                'jumlah' => (int) $b->jumlah,
                'total' => (float) $b->total,
                'account_id' => $akun?->id,
                'account_code' => $akun?->code,
                'account_name' => $akun?->name,
            ];
        })->values();
    }
}
