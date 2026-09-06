<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\ExpenseRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan & persetujuan biaya (domain K item F) — 4 kode.
 *
 *   submit    -> pengajuan_biaya
 *   approve   -> persetujuan_pengajuan_biaya
 *   validate  -> validasi_persetujuan_pengajuan_biaya
 *   disburse  -> pencairan lewat kas item A
 *   recap     -> rekap_pengajuan_biaya
 *
 * TIGA ORANG BERBEDA, DITEGAKKAN DI SERVICE. Pengaju tidak boleh
 * menyetujui pengajuannya sendiri, dan penyetuju tidak boleh memvalidasi
 * persetujuannya sendiri. Tanpa itu, tiga tahap yang terlihat rapi di
 * layar bisa dijalankan satu orang dalam tiga klik — dan pemisahan tugas
 * yang cuma ada di gerbang peran akan runtuh begitu ada orang yang
 * memegang dua peran sekaligus, yang di rumah sakit kecil justru lazim.
 *
 * PENCAIRAN MENULIS KE KAS, bukan menyimpan nilainya sendiri. Uang yang
 * keluar hanya boleh punya satu pencatatan; pengajuan cuma menunjuk
 * transaksi kasnya. Pelajaran yang sama seperti piutang pasien di item D.
 */
class ExpenseRequestService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly CashService $kas,
    ) {}

    // ------------------------------------------------------------------ alur

    /**
     * @throws FinanceException
     */
    public function submit(array $data, ?int $actorId = null, ?string $actorName = null): ExpenseRequest
    {
        $nilai = round((float) ($data['requested_amount'] ?? 0), 2);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai pengajuan harus lebih dari nol.');
        }

        if (! empty($data['category_id'])) {
            $kategori = CashCategory::query()->find($data['category_id'])
                ?? throw new FinanceException('Pos pengeluaran tidak ditemukan.');

            if ($kategori->direction !== CashCategory::KELUAR) {
                throw new FinanceException('Pengajuan biaya harus memakai pos pengeluaran, bukan pos pemasukan.');
            }
        }

        return ExpenseRequest::query()->create([
            'request_number' => $this->numbers->allocate('PB'),
            'unit_id' => $data['unit_id'] ?? null,
            'unit_name' => $data['unit_name'],
            'requested_on' => $data['requested_on'],
            'purpose' => $data['purpose'],
            'justification' => $data['justification'] ?? null,
            'requested_amount' => $nilai,
            'category_id' => $data['category_id'] ?? null,
            'status' => ExpenseRequest::DIAJUKAN,
            'requested_by' => $actorId,
            'requested_by_name' => $actorName,
        ]);
    }

    /**
     * Menyetujui, boleh dengan nilai yang dipotong.
     *
     * @throws FinanceException
     */
    public function approve(ExpenseRequest $pengajuan, ?float $approvedAmount, ?string $note, ?int $actorId = null): ExpenseRequest
    {
        $this->assertCanAdvance($pengajuan, ExpenseRequest::DISETUJUI);

        if ($actorId !== null && $pengajuan->requested_by === $actorId) {
            throw new FinanceException('Pengajuan tidak bisa disetujui oleh pengajunya sendiri.');
        }

        $nilai = $approvedAmount !== null ? round($approvedAmount, 2) : (float) $pengajuan->requested_amount;

        if ($nilai <= 0) {
            throw new FinanceException('Nilai yang disetujui harus lebih dari nol.');
        }

        if ($nilai > (float) $pengajuan->requested_amount) {
            throw new FinanceException(
                'Nilai yang disetujui tidak boleh melebihi yang diajukan. Kalau memang butuh lebih, ajukan yang baru.'
            );
        }

        $pengajuan->update([
            'approved_amount' => $nilai,
            'approval_note' => $note,
            'status' => ExpenseRequest::DISETUJUI,
            'approved_at' => now(),
            'approved_by' => $actorId,
        ]);

        return $pengajuan->refresh();
    }

    /**
     * @throws FinanceException
     */
    public function reject(ExpenseRequest $pengajuan, string $reason, ?int $actorId = null): ExpenseRequest
    {
        $this->assertCanAdvance($pengajuan, ExpenseRequest::DITOLAK);

        $pengajuan->update([
            'status' => ExpenseRequest::DITOLAK,
            'rejection_reason' => $reason,
            'approved_by' => $actorId,
            'approved_at' => now(),
        ]);

        return $pengajuan->refresh();
    }

    /**
     * Memvalidasi persetujuan sebelum uang boleh keluar.
     *
     * @throws FinanceException
     */
    public function validateApproval(ExpenseRequest $pengajuan, ?int $actorId = null): ExpenseRequest
    {
        $this->assertCanAdvance($pengajuan, ExpenseRequest::TERVALIDASI);

        if ($actorId !== null && $pengajuan->approved_by === $actorId) {
            throw new FinanceException('Persetujuan tidak bisa divalidasi oleh penyetujunya sendiri.');
        }

        $pengajuan->update([
            'status' => ExpenseRequest::TERVALIDASI,
            'validated_at' => now(),
            'validated_by' => $actorId,
        ]);

        return $pengajuan->refresh();
    }

    /**
     * Mencairkan: menulis transaksi kas keluar, lalu menunjuknya.
     *
     * @throws FinanceException
     */
    public function disburse(ExpenseRequest $pengajuan, array $data, ?int $actorId = null, ?string $actorName = null): ExpenseRequest
    {
        $this->assertCanAdvance($pengajuan, ExpenseRequest::DICAIRKAN);

        if ($pengajuan->category_id === null) {
            throw new FinanceException('Pengajuan harus punya pos pengeluaran sebelum bisa dicairkan.');
        }

        return DB::transaction(function () use ($pengajuan, $data, $actorId, $actorName) {
            $kas = $this->kas->record([
                'category_id' => $pengajuan->category_id,
                'transaction_date' => $data['paid_on'],
                // Nilai yang dicairkan adalah yang DISETUJUI, bukan yang
                // diajukan. Memakai nilai pengajuan berarti mengeluarkan
                // uang yang tidak pernah disetujui siapa pun.
                'amount' => (float) $pengajuan->approved_amount,
                'description' => 'Pencairan ' . $pengajuan->request_number . ' — ' . $pengajuan->purpose,
                'counterparty' => $pengajuan->unit_name,
                'payment_method' => $data['payment_method'] ?? 'tunai',
                'reference_number' => $pengajuan->request_number,
            ], $actorId, $actorName);

            $pengajuan->update([
                'status' => ExpenseRequest::DICAIRKAN,
                'cash_transaction_id' => $kas->id,
                'disbursed_at' => now(),
                'disbursed_by' => $actorId,
            ]);

            return $pengajuan->refresh();
        });
    }

    // ---------------------------------------------------------------- laporan

    public function requests(?string $status = null, ?string $unit = null, ?string $from = null, ?string $until = null): Collection
    {
        $q = ExpenseRequest::query()->orderByDesc('requested_on')->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        if ($unit !== null && $unit !== '') {
            $q->where('unit_name', $unit);
        }

        if ($from !== null && $until !== null) {
            $q->whereBetween('requested_on', [$from, $until]);
        }

        return $q->get();
    }

    /**
     * Rekap per unit: yang diminta, yang disetujui, dan SELISIHNYA.
     *
     * Selisih itulah angka yang dicari saat menyusun anggaran berikutnya —
     * unit yang selalu dipotong setengah mungkin memang mengajukan terlalu
     * besar, atau memang selalu kekurangan. Tanpa menampilkannya, kedua
     * kemungkinan itu tidak terlihat.
     */
    public function recapByUnit(?string $from = null, ?string $until = null): Collection
    {
        $q = DB::table('finance.expense_requests')
            ->whereNotIn('status', [ExpenseRequest::DIBATALKAN])
            ->groupBy('unit_name')
            ->selectRaw("unit_name,
                         count(*) AS pengajuan,
                         sum(requested_amount) AS diminta,
                         coalesce(sum(approved_amount), 0) AS disetujui,
                         sum(requested_amount) - coalesce(sum(approved_amount), 0) AS selisih,
                         count(*) FILTER (WHERE status = 'ditolak') AS ditolak,
                         count(*) FILTER (WHERE status = 'dicairkan') AS dicairkan")
            ->orderByDesc('diminta');

        if ($from !== null && $until !== null) {
            $q->whereBetween('requested_on', [$from, $until]);
        }

        return $q->get();
    }

    /** Rekap per pos pengeluaran — menyambung ke pemetaan akun. */
    public function recapByCategory(?string $from = null, ?string $until = null): Collection
    {
        $q = DB::table('finance.expense_requests as p')
            ->leftJoin('finance.cash_categories as k', 'k.id', '=', 'p.category_id')
            ->whereNotIn('p.status', [ExpenseRequest::DIBATALKAN, ExpenseRequest::DITOLAK])
            ->groupBy('k.name')
            ->selectRaw("coalesce(k.name, '(belum berpos)') AS pos,
                         count(*) AS pengajuan,
                         sum(p.requested_amount) AS diminta,
                         coalesce(sum(p.approved_amount), 0) AS disetujui")
            ->orderByDesc('diminta');

        if ($from !== null && $until !== null) {
            $q->whereBetween('p.requested_on', [$from, $until]);
        }

        return $q->get();
    }

    /** Pengajuan yang menunggu tindakan — pekerjaan yang belum selesai. */
    public function pending(): Collection
    {
        return ExpenseRequest::query()
            ->whereIn('status', [ExpenseRequest::DIAJUKAN, ExpenseRequest::DISETUJUI, ExpenseRequest::TERVALIDASI])
            ->orderBy('requested_on')
            ->get();
    }

    /**
     * @throws FinanceException
     */
    private function assertCanAdvance(ExpenseRequest $pengajuan, string $ke): void
    {
        if (! $pengajuan->canAdvanceTo($ke)) {
            throw new FinanceException(
                "Pengajuan berstatus '{$pengajuan->status}' tidak bisa dilanjutkan ke '{$ke}'."
            );
        }
    }
}
