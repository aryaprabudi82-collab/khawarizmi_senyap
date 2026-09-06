<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PeriodClosing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bagan akun, jurnal manual & buku besar (domain K item E) — ~10 kode.
 *
 *   createAccount / updateAccount -> akun_rekening, pengaturan_rekening,
 *                                    akun_aset_inventaris
 *   setOpeningBalance             -> rekening_tahun
 *   postManual                    -> posting_jurnal
 *   dailyJournal                  -> jurnal_harian
 *   ledger                        -> buku_besar
 *   monthlyBalances               -> saldo_akun_perbulan
 *
 * TIGA ATURAN YANG DITEGAKKAN, dan alasannya:
 *
 * 1. Jurnal manual WAJIB SEIMBANG. Jurnal yang debit dan kreditnya tidak
 *    sama bukan jurnal yang kurang rapi — ia merusak seluruh neraca yang
 *    dibangun di atasnya, dan selisihnya baru ketahuan berbulan-bulan
 *    kemudian saat ada yang mencoba menutup buku.
 *
 * 2. Jurnal TIDAK BOLEH masuk periode yang sudah ditutup. Penutupan
 *    periode membekukan angka yang sudah dilaporkan; menambah jurnal ke
 *    dalamnya membuat laporan yang sudah dikirim tidak lagi cocok dengan
 *    sistemnya sendiri.
 *
 * 3. Saldo buku besar = saldo awal + mutasi. Buku besar tanpa saldo awal
 *    hanya menampilkan mutasi, bukan posisi — dan posisi itulah yang
 *    dibaca orang saat menyusun neraca.
 */
class LedgerService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    // ----------------------------------------------------------- bagan akun

    /**
     * @throws FinanceException
     */
    public function createAccount(array $data): Account
    {
        $kode = trim($data['code'] ?? '');

        if ($kode === '') {
            throw new FinanceException('Kode akun wajib diisi.');
        }

        if (! in_array($data['type'] ?? '', Account::JENIS, true)) {
            throw new FinanceException('Jenis akun tidak dikenal.');
        }

        if (Account::query()->where('code', $kode)->exists()) {
            throw new FinanceException("Akun dengan kode {$kode} sudah ada.");
        }

        return Account::query()->create([
            'code' => $kode,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Menonaktifkan akun — TIDAK menghapusnya.
     *
     * Akun yang pernah dipakai jurnal tidak boleh hilang: buku besar tahun
     * lalu akan kehilangan nama akunnya dan jadi mustahil dibaca. Yang
     * dinonaktifkan cuma tidak bisa dipilih untuk jurnal baru.
     *
     * @throws FinanceException
     */
    public function deactivateAccount(Account $akun): Account
    {
        if (! $akun->is_active) {
            throw new FinanceException('Akun ini sudah nonaktif.');
        }

        $akun->update(['is_active' => false]);

        return $akun->refresh();
    }

    /**
     * Saldo awal tahun buku (rekening_tahun).
     *
     * Idempoten per akun per tahun: menghitung ulang MENGGANTI, bukan
     * menambah — saldo awal ganda menggelembungkan posisi tanpa ada yang
     * terlihat salah.
     *
     * @throws FinanceException
     */
    public function setOpeningBalance(Account $akun, int $tahun, float $debit, float $kredit, ?int $actorId = null): void
    {
        if ($debit < 0 || $kredit < 0) {
            throw new FinanceException('Saldo awal tidak boleh negatif.');
        }

        if ($debit > 0 && $kredit > 0) {
            throw new FinanceException('Saldo awal diisi pada satu sisi saja — debit atau kredit, bukan keduanya.');
        }

        DB::table('finance.account_opening_balances')->updateOrInsert(
            ['account_id' => $akun->id, 'fiscal_year' => $tahun],
            [
                'opening_debit' => $debit,
                'opening_credit' => $kredit,
                'recorded_by' => $actorId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    // -------------------------------------------------------- jurnal manual

    /**
     * Memposting jurnal manual.
     *
     * @param  array<int,array{account_id:int,debit?:float,credit?:float,description?:string}>  $lines
     *
     * @throws FinanceException
     */
    public function postManual(string $date, string $description, array $lines, ?int $actorId = null): JournalEntry
    {
        if (count($lines) < 2) {
            throw new FinanceException('Jurnal harus punya sekurang-kurangnya dua baris.');
        }

        $this->assertPeriodOpen($date);

        $totalDebit = 0.0;
        $totalKredit = 0.0;

        foreach ($lines as $i => $baris) {
            $debit = round((float) ($baris['debit'] ?? 0), 2);
            $kredit = round((float) ($baris['credit'] ?? 0), 2);

            if ($debit < 0 || $kredit < 0) {
                throw new FinanceException('Nilai jurnal tidak boleh negatif.');
            }

            if ($debit > 0 && $kredit > 0) {
                throw new FinanceException('Satu baris jurnal hanya boleh berisi debit atau kredit, tidak keduanya.');
            }

            if ($debit === 0.0 && $kredit === 0.0) {
                throw new FinanceException('Baris jurnal bernilai nol tidak ada gunanya.');
            }

            $akun = Account::query()->find($baris['account_id'] ?? null)
                ?? throw new FinanceException('Akun pada salah satu baris jurnal tidak ditemukan.');

            if (! $akun->is_active) {
                throw new FinanceException("Akun {$akun->code} sudah nonaktif dan tidak bisa dijurnal.");
            }

            $totalDebit += $debit;
            $totalKredit += $kredit;

            $lines[$i]['debit'] = $debit;
            $lines[$i]['credit'] = $kredit;
        }

        if (round($totalDebit, 2) !== round($totalKredit, 2)) {
            throw new FinanceException(
                'Jurnal tidak seimbang: debit ' . number_format($totalDebit, 2, ',', '.')
                . ' berbanding kredit ' . number_format($totalKredit, 2, ',', '.') . '.'
            );
        }

        return DB::transaction(function () use ($date, $description, $lines, $actorId) {
            $jurnal = JournalEntry::query()->create([
                'entry_number' => $this->numbers->allocate('JU'),
                'entry_date' => $date,
                'description' => $description,
                // Jurnal manual tidak punya rujukan; dibiarkan kosong
                // alih-alih diisi rujukan palsu yang menyesatkan penelusuran.
                'reference_type' => null,
                'reference_id' => null,
                'posted_by' => $actorId,
            ]);

            foreach ($lines as $baris) {
                DB::table('finance.journal_lines')->insert([
                    'journal_entry_id' => $jurnal->id,
                    'account_id' => $baris['account_id'],
                    'debit' => $baris['debit'],
                    'credit' => $baris['credit'],
                    'description' => $baris['description'] ?? null,
                ]);
            }

            return $jurnal;
        });
    }

    // ---------------------------------------------------------------- laporan

    /** Jurnal harian — jurnal_harian. */
    public function dailyJournal(string $from, string $until, ?string $referenceType = null): Collection
    {
        $q = DB::table('finance.journal_entries as j')
            ->whereBetween('j.entry_date', [$from, $until])
            ->selectRaw("j.id, j.entry_number, j.entry_date, j.description,
                         coalesce(j.reference_type, 'manual') AS reference_type,
                         (SELECT sum(l.debit) FROM finance.journal_lines l WHERE l.journal_entry_id = j.id) AS debit,
                         (SELECT sum(l.credit) FROM finance.journal_lines l WHERE l.journal_entry_id = j.id) AS credit")
            ->orderBy('j.entry_date')
            ->orderBy('j.id');

        if ($referenceType === 'manual') {
            $q->whereNull('j.reference_type');
        } elseif ($referenceType !== null && $referenceType !== '') {
            $q->where('j.reference_type', $referenceType);
        }

        return $q->get();
    }

    /** Baris-baris satu jurnal. */
    public function journalLines(JournalEntry $jurnal): Collection
    {
        return DB::table('finance.journal_lines as l')
            ->join('finance.chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_entry_id', $jurnal->id)
            ->selectRaw('l.debit, l.credit, l.description, a.code, a.name, a.type')
            ->orderBy('l.id')
            ->get();
    }

    /**
     * Buku besar satu akun: saldo awal, mutasi, dan saldo berjalan.
     *
     * Saldo berjalan dihitung di PHP, bukan lewat window function, supaya
     * arah normal akunnya bisa diperlakukan eksplisit: akun debit-normal
     * (kas, piutang, beban, aset) bertambah oleh debit, akun kredit-normal
     * (utang, pendapatan, modal) bertambah oleh kredit. Menampilkan saldo
     * bertanda tanpa memperhatikan arah normal membuat setiap akun
     * kredit-normal terlihat negatif dan pembacanya menyimpulkan ada yang
     * rusak.
     */
    public function ledger(Account $akun, string $from, string $until): array
    {
        $tahun = (int) substr($from, 0, 4);

        $awal = DB::table('finance.account_opening_balances')
            ->where('account_id', $akun->id)
            ->where('fiscal_year', $tahun)
            ->first();

        $saldoAwal = $akun->isDebitNormal()
            ? (float) ($awal->opening_debit ?? 0) - (float) ($awal->opening_credit ?? 0)
            : (float) ($awal->opening_credit ?? 0) - (float) ($awal->opening_debit ?? 0);

        // Mutasi sebelum rentang, supaya saldo pembuka rentangnya benar.
        $sebelum = DB::table('finance.journal_lines as l')
            ->join('finance.journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $akun->id)
            ->whereRaw('j.entry_date >= ?::date', [$tahun . '-01-01'])
            ->whereRaw('j.entry_date < ?::date', [$from])
            ->selectRaw('coalesce(sum(l.debit),0) AS d, coalesce(sum(l.credit),0) AS k')
            ->first();

        $saldoPembuka = $saldoAwal + ($akun->isDebitNormal()
            ? (float) $sebelum->d - (float) $sebelum->k
            : (float) $sebelum->k - (float) $sebelum->d);

        $baris = DB::table('finance.journal_lines as l')
            ->join('finance.journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $akun->id)
            ->whereBetween('j.entry_date', [$from, $until])
            ->selectRaw('j.entry_number, j.entry_date, j.description, l.debit, l.credit, l.description AS baris_uraian')
            ->orderBy('j.entry_date')
            ->orderBy('j.id')
            ->get();

        $saldo = $saldoPembuka;

        $mutasi = $baris->map(function ($b) use (&$saldo, $akun) {
            $saldo += $akun->isDebitNormal()
                ? (float) $b->debit - (float) $b->credit
                : (float) $b->credit - (float) $b->debit;

            $b->saldo = round($saldo, 2);

            return $b;
        });

        return [
            'saldo_awal_tahun' => round($saldoAwal, 2),
            'saldo_pembuka' => round($saldoPembuka, 2),
            'mutasi' => $mutasi,
            'total_debit' => (float) $baris->sum('debit'),
            'total_kredit' => (float) $baris->sum('credit'),
            'saldo_akhir' => round($saldo, 2),
            'arah_normal' => $akun->isDebitNormal() ? 'debit' : 'kredit',
        ];
    }

    /** Saldo tiap akun per bulan — saldo_akun_perbulan. */
    public function monthlyBalances(int $year, ?string $type = null): Collection
    {
        $q = DB::table('finance.journal_lines as l')
            ->join('finance.journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('finance.chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereRaw('extract(year from j.entry_date) = ?', [$year])
            ->groupBy(DB::raw("to_char(j.entry_date, 'YYYY-MM')"), 'a.code', 'a.name', 'a.type')
            ->selectRaw("to_char(j.entry_date, 'YYYY-MM') AS bulan, a.code, a.name, a.type,
                         sum(l.debit) AS debit, sum(l.credit) AS credit")
            ->orderBy('bulan')
            ->orderBy('a.code');

        if ($type !== null && $type !== '') {
            $q->where('a.type', $type);
        }

        return $q->get();
    }

    /**
     * Neraca saldo: total debit dan kredit seluruh akun pada satu rentang.
     *
     * Selisihnya WAJIB nol. Kalau tidak, ada jurnal yang tidak seimbang —
     * angkanya ditampilkan apa adanya supaya ketahuan, bukan disembunyikan
     * di balik pembulatan.
     */
    public function trialBalance(string $from, string $until): object
    {
        $baris = DB::table('finance.journal_lines as l')
            ->join('finance.journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('finance.chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('j.entry_date', [$from, $until])
            ->groupBy('a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type, sum(l.debit) AS debit, sum(l.credit) AS credit')
            ->orderBy('a.code')
            ->get();

        $debit = (float) $baris->sum('debit');
        $kredit = (float) $baris->sum('credit');

        return (object) [
            'baris' => $baris,
            'total_debit' => $debit,
            'total_kredit' => $kredit,
            'selisih' => round($debit - $kredit, 2),
            'seimbang' => round($debit - $kredit, 2) === 0.0,
        ];
    }

    public function accounts(?string $type = null, bool $activeOnly = false): Collection
    {
        $q = Account::query()->orderBy('code');

        if ($type !== null && $type !== '') {
            $q->where('type', $type);
        }

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    public function openingBalances(int $year): Collection
    {
        return DB::table('finance.account_opening_balances as s')
            ->join('finance.chart_of_accounts as a', 'a.id', '=', 's.account_id')
            ->where('s.fiscal_year', $year)
            ->selectRaw('a.code, a.name, a.type, s.opening_debit, s.opening_credit')
            ->orderBy('a.code')
            ->get();
    }

    /**
     * @throws FinanceException
     */
    private function assertPeriodOpen(string $date): void
    {
        $tahun = (int) substr($date, 0, 4);
        $bulan = (int) substr($date, 5, 2);

        $tertutup = PeriodClosing::query()
            ->where('period_year', $tahun)
            ->where('period_month', $bulan)
            ->whereNull('reopened_at')
            ->exists();

        if ($tertutup) {
            throw new FinanceException(
                sprintf('Periode %04d-%02d sudah ditutup; jurnal baru tidak bisa masuk ke dalamnya.', $tahun, $bulan)
            );
        }
    }
}
