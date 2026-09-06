<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\CashTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kas harian (domain K item A) — 8 kode.
 *
 *   record / cancel       -> pemasukan_lain, pengeluaran,
 *                            pengeluaran_pengeluaran
 *   categories            -> kategori_pemasukan_lain,
 *                            kategori_pengeluaran_harian
 *   dailySummary / cashflow -> omset_penerimaan, cashflow, keuangan
 *
 * SATU MEKANISME untuk pemasukan dan pengeluaran, dibedakan arah. Nilai
 * selalu disimpan positif; yang menjumlahkan wajib memisahkan arahnya
 * lebih dulu. Seluruh method di kelas ini lewat satu builder bersama
 * supaya penyaring tidak pernah berlaku separuh — pelajaran yang sama
 * yang sudah dibayar mahal di laporan pharmacy dan rekap billing.
 *
 * Transaksi yang DIBATALKAN dikecualikan dari setiap angka, tapi barisnya
 * tidak dihapus: kas yang pernah tercatat harus tetap bisa ditelusuri
 * berikut alasan pembatalannya.
 */
class CashService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    // ---------------------------------------------------------- pencatatan

    /**
     * @throws FinanceException
     */
    public function record(array $data, ?int $actorId = null, ?string $actorName = null): CashTransaction
    {
        $kategori = CashCategory::query()->find($data['category_id'] ?? null)
            ?? throw new FinanceException('Kategori kas tidak ditemukan.');

        if (! $kategori->is_active) {
            throw new FinanceException("Kategori '{$kategori->name}' sudah tidak aktif.");
        }

        $nilai = (float) ($data['amount'] ?? 0);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai transaksi kas harus lebih dari nol.');
        }

        return CashTransaction::query()->create([
            'transaction_number' => $this->numbers->allocate($kategori->direction === CashCategory::MASUK ? 'KM' : 'KK'),
            'category_id' => $kategori->id,
            // Arah disalin dari kategorinya, bukan diterima dari pemanggil:
            // arah yang boleh dikirim terpisah membuka celah pengeluaran
            // tercatat sebagai pemasukan tanpa melanggar satu aturan pun.
            'direction' => $kategori->direction,
            'transaction_date' => $data['transaction_date'],
            'amount' => $nilai,
            'description' => $data['description'],
            'counterparty' => $data['counterparty'] ?? null,
            'payment_method' => $data['payment_method'] ?? 'tunai',
            'reference_number' => $data['reference_number'] ?? null,
            'recorded_by' => $actorId,
            'recorded_by_name' => $actorName,
        ]);
    }

    /**
     * @throws FinanceException
     */
    public function cancel(CashTransaction $trx, string $reason, ?int $actorId = null): CashTransaction
    {
        if ($trx->isCancelled()) {
            throw new FinanceException('Transaksi kas ini sudah dibatalkan.');
        }

        $trx->update([
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'cancelled_by' => $actorId,
        ]);

        return $trx->refresh();
    }

    // -------------------------------------------------------------- laporan

    /** Ringkasan satu rentang: masuk, keluar, dan selisihnya. */
    public function summary(string $from, string $until, ?int $categoryId = null): object
    {
        $row = $this->query($from, $until, $categoryId)
            ->selectRaw("coalesce(sum(amount) FILTER (WHERE direction = 'masuk'), 0) AS masuk,
                         coalesce(sum(amount) FILTER (WHERE direction = 'keluar'), 0) AS keluar,
                         count(*) AS transaksi")
            ->first();

        $masuk = (float) ($row->masuk ?? 0);
        $keluar = (float) ($row->keluar ?? 0);

        return (object) [
            'masuk' => $masuk,
            'keluar' => $keluar,
            'selisih' => $masuk - $keluar,
            'transaksi' => (int) ($row->transaksi ?? 0),
        ];
    }

    /** Arus kas harian — omset_penerimaan & cashflow. */
    public function dailyCashflow(string $from, string $until, ?int $categoryId = null): Collection
    {
        return $this->query($from, $until, $categoryId)
            ->groupBy('transaction_date')
            ->selectRaw("transaction_date,
                         coalesce(sum(amount) FILTER (WHERE direction = 'masuk'), 0) AS masuk,
                         coalesce(sum(amount) FILTER (WHERE direction = 'keluar'), 0) AS keluar,
                         coalesce(sum(amount) FILTER (WHERE direction = 'masuk'), 0)
                         - coalesce(sum(amount) FILTER (WHERE direction = 'keluar'), 0) AS selisih")
            ->orderBy('transaction_date')
            ->get();
    }

    /** Rekap per kategori — memperlihatkan pos mana yang paling besar. */
    public function byCategory(string $from, string $until, ?string $direction = null): Collection
    {
        $q = $this->query($from, $until, null)
            ->join('finance.cash_categories as k', 'k.id', '=', 'finance.cash_transactions.category_id');

        if ($direction !== null && $direction !== '') {
            $q->where('finance.cash_transactions.direction', $direction);
        }

        return $q->groupBy('k.code', 'k.name', 'k.direction')
            ->selectRaw('k.code, k.name, k.direction,
                         count(*) AS transaksi,
                         coalesce(sum(finance.cash_transactions.amount), 0) AS nilai')
            ->orderByDesc('nilai')
            ->get();
    }

    /**
     * Nilai kas yang kategorinya belum dipetakan ke bagan akun.
     *
     * Ditampilkan berdampingan dengan totalnya — sama seperti
     * AccountingReportService::unmappedTotal() sejak domain I item E.
     * Kas yang belum dipetakan tidak akan muncul di laporan akuntansi,
     * dan tanpa angka ini hilangnya tidak akan ketahuan.
     */
    public function unmappedTotal(string $from, string $until): float
    {
        return (float) $this->query($from, $until, null)
            ->join('finance.cash_categories as k', 'k.id', '=', 'finance.cash_transactions.category_id')
            ->whereNull('k.account_id')
            ->sum('finance.cash_transactions.amount');
    }

    /** Daftar transaksi untuk ditelaah. */
    public function transactions(string $from, string $until, ?int $categoryId = null, int $limit = 200): Collection
    {
        return $this->query($from, $until, $categoryId)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Transaksi yang dibatalkan — dilaporkan terpisah, bukan dihilangkan. */
    public function cancelledTransactions(string $from, string $until): Collection
    {
        return DB::table('finance.cash_transactions')
            ->whereBetween('transaction_date', [$from, $until])
            ->whereNotNull('cancelled_at')
            ->orderByDesc('transaction_date')
            ->get();
    }

    public function categories(?string $direction = null): Collection
    {
        $q = CashCategory::query()->orderBy('direction')->orderBy('name');

        if ($direction !== null && $direction !== '') {
            $q->where('direction', $direction);
        }

        return $q->get();
    }

    /**
     * Builder bersama seluruh angka di kelas ini.
     *
     * Yang dibatalkan dikecualikan di SATU tempat, supaya tidak ada
     * laporan yang diam-diam ikut menghitungnya.
     */
    private function query(string $from, string $until, ?int $categoryId)
    {
        $q = DB::table('finance.cash_transactions')
            ->whereBetween('transaction_date', [$from, $until])
            ->whereNull('cancelled_at');

        if ($categoryId !== null) {
            $q->where('category_id', $categoryId);
        }

        return $q;
    }
}
