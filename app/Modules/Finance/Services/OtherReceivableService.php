<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\OtherReceivable;
use App\Modules\Finance\Models\OtherReceivablePayment;
use App\Modules\Finance\Models\ReceivableCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Piutang non-pasien (domain K item C) — ~15 kode.
 *
 *   record / collect  -> piutang_jasa_perusahaan, peminjam_piutang,
 *                        bayar_piutang_jasa_perusahaan, bayar_piutang_lain
 *   outstanding       -> piutang_jasa_perusahaan_belum_lunas,
 *                        piutang_peminjaman_uang_belum_lunas
 *   categories        -> kategori_piutang_jasa_perusahaan
 *   unmappedTotal     -> akun_piutang, akun_penagihan_piutang
 *
 * Beban hutang lain TIDAK dilayani kelas ini meski Khanza menaruhnya di
 * menu yang sama: arahnya berlawanan, dan sudah punya mekanismenya di
 * PayableService dengan source_context 'lain' sejak migrasi item C.
 *
 * Aturan yang sama seperti hutang vendor: sisa selalu dihitung, tidak
 * pernah disimpan; pembayaran dijumlahkan lewat subquery bukan join
 * supaya piutang yang dicicil tidak tergandakan; dan pembayaran melebihi
 * sisa ditolak karena sisa negatif akan diam-diam mengurangi total
 * piutang saat dijumlahkan.
 */
class OtherReceivableService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    // ------------------------------------------------------------- pencatatan

    /**
     * @throws FinanceException
     */
    public function record(array $data, ?int $actorId = null): OtherReceivable
    {
        $kategori = ReceivableCategory::query()->find($data['category_id'] ?? null)
            ?? throw new FinanceException('Kategori piutang tidak ditemukan.');

        if (! $kategori->is_active) {
            throw new FinanceException("Kategori '{$kategori->name}' sudah tidak aktif.");
        }

        $nilai = (float) ($data['amount'] ?? 0);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai piutang harus lebih dari nol.');
        }

        if (strtotime($data['due_date']) < strtotime($data['issued_on'])) {
            throw new FinanceException('Jatuh tempo tidak boleh mendahului tanggal piutang.');
        }

        return OtherReceivable::query()->create([
            'receivable_number' => $this->numbers->allocate('PTG'),
            'category_id' => $kategori->id,
            // Jenis disalin dari kategorinya, bukan diterima dari pemanggil —
            // alasan yang sama seperti arah pada transaksi kas.
            'kind' => $kategori->kind,
            'debtor_name' => $data['debtor_name'],
            'debtor_contact' => $data['debtor_contact'] ?? null,
            'debtor_ref_id' => $data['debtor_ref_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'issued_on' => $data['issued_on'],
            'due_date' => $data['due_date'],
            'amount' => $nilai,
            'description' => $data['description'],
            'status' => OtherReceivable::BERJALAN,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Menerima pembayaran, boleh dicicil.
     *
     * @throws FinanceException
     */
    public function collect(OtherReceivable $piutang, array $data, ?int $actorId = null, ?string $actorName = null): OtherReceivablePayment
    {
        if ($piutang->status === OtherReceivable::LUNAS) {
            throw new FinanceException('Piutang ini sudah lunas.');
        }

        if ($piutang->status === OtherReceivable::DIHAPUSKAN) {
            throw new FinanceException('Piutang yang sudah dihapuskan tidak bisa menerima pembayaran.');
        }

        $nilai = round((float) ($data['amount'] ?? 0), 2);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai pembayaran harus lebih dari nol.');
        }

        $sisa = $piutang->remaining();

        if ($nilai > $sisa) {
            throw new FinanceException(
                'Pembayaran melebihi sisa piutang (sisa ' . number_format($sisa, 2, ',', '.') . ').'
            );
        }

        return DB::transaction(function () use ($piutang, $data, $nilai, $sisa, $actorId, $actorName) {
            $bayar = OtherReceivablePayment::query()->create([
                'payment_number' => $this->numbers->allocate('TPT'),
                'receivable_id' => $piutang->id,
                'paid_on' => $data['paid_on'],
                'amount' => $nilai,
                'payment_method' => $data['payment_method'] ?? 'transfer',
                'reference_number' => $data['reference_number'] ?? null,
                'note' => $data['note'] ?? null,
                'received_by' => $actorId,
                'received_by_name' => $actorName,
            ]);

            if ($nilai >= $sisa) {
                $piutang->update(['status' => OtherReceivable::LUNAS]);
            }

            return $bayar;
        });
    }

    /**
     * Menghapuskan piutang tak tertagih.
     *
     * Barisnya tidak dihapus dan pembayarannya tetap utuh: penghapusan
     * piutang adalah keputusan yang harus bisa ditelusuri, bukan sekadar
     * membuat angka menjadi rapi.
     *
     * @throws FinanceException
     */
    public function writeOff(OtherReceivable $piutang, string $reason, ?int $actorId = null): OtherReceivable
    {
        if ($piutang->status !== OtherReceivable::BERJALAN) {
            throw new FinanceException('Hanya piutang berjalan yang bisa dihapuskan.');
        }

        $piutang->update([
            'status' => OtherReceivable::DIHAPUSKAN,
            'written_off_at' => now(),
            'write_off_reason' => $reason,
            'written_off_by' => $actorId,
        ]);

        return $piutang->refresh();
    }

    // ---------------------------------------------------------------- laporan

    /** Piutang yang masih berjalan, berikut sisanya. */
    public function outstanding(?string $kind = null, ?int $categoryId = null): Collection
    {
        return $this->query($kind, $categoryId)
            ->select('p.*', DB::raw('coalesce(b.dibayar, 0) AS dibayar'),
                DB::raw('p.amount - coalesce(b.dibayar, 0) AS sisa'))
            ->orderBy('p.due_date')
            ->get();
    }

    /** Ringkasan per jenis piutang. */
    public function byKind(): Collection
    {
        return $this->query(null, null)
            ->groupBy('p.kind')
            ->selectRaw('p.kind,
                         count(*) AS piutang,
                         sum(p.amount) AS nilai,
                         sum(coalesce(b.dibayar, 0)) AS dibayar,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa')
            ->orderByDesc('sisa')
            ->get();
    }

    /** Ringkasan per pihak yang berhutang. */
    public function byDebtor(?string $kind = null): Collection
    {
        return $this->query($kind, null)
            ->groupBy('p.debtor_name')
            ->selectRaw('p.debtor_name,
                         count(*) AS piutang,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa')
            ->orderByDesc('sisa')
            ->get();
    }

    /**
     * Umur piutang. Yang belum jatuh tempo dipisahkan dari tunggakan —
     * alasan yang sama seperti umur hutang vendor.
     */
    public function aging(?string $kind = null): Collection
    {
        $umur = "CASE
            WHEN p.due_date >= current_date THEN 'belum jatuh tempo'
            WHEN current_date - p.due_date <= 30 THEN '1-30 hari'
            WHEN current_date - p.due_date <= 60 THEN '31-60 hari'
            WHEN current_date - p.due_date <= 90 THEN '61-90 hari'
            ELSE 'lebih dari 90 hari'
        END";

        return $this->query($kind, null)
            ->groupBy(DB::raw($umur))
            ->selectRaw("{$umur} AS kelompok,
                         count(*) AS piutang,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa")
            ->get();
    }

    /**
     * Piutang yang dihapuskan — dilaporkan terpisah, tidak dihilangkan.
     *
     * Angka ini justru yang paling perlu terlihat: penghapusan piutang
     * yang menumpuk adalah gejala penagihan yang tidak berjalan.
     */
    public function writtenOff(): Collection
    {
        return DB::table('finance.other_receivables')
            ->where('status', OtherReceivable::DIHAPUSKAN)
            ->orderByDesc('written_off_at')
            ->get();
    }

    public function payments(string $from, string $until): Collection
    {
        return DB::table('finance.other_receivable_payments as b')
            ->join('finance.other_receivables as p', 'p.id', '=', 'b.receivable_id')
            ->whereBetween('b.paid_on', [$from, $until])
            ->selectRaw('b.payment_number, b.paid_on, b.amount, b.payment_method,
                         p.receivable_number, p.debtor_name, p.kind')
            ->orderByDesc('b.paid_on')
            ->get();
    }

    /** Piutang yang kategorinya belum dipetakan ke bagan akun. */
    public function unmappedTotal(): float
    {
        return (float) $this->query(null, null)
            ->join('finance.receivable_categories as k', 'k.id', '=', 'p.category_id')
            ->whereNull('k.account_id')
            ->sum(DB::raw('p.amount - coalesce(b.dibayar, 0)'));
    }

    public function categories(?string $kind = null): Collection
    {
        $q = ReceivableCategory::query()->orderBy('kind')->orderBy('name');

        if ($kind !== null && $kind !== '') {
            $q->where('kind', $kind);
        }

        return $q->get();
    }

    /**
     * Builder bersama seluruh angka piutang.
     *
     * Yang dihapuskan DIKECUALIKAN di satu tempat: piutang yang sudah
     * diputuskan tak tertagih bukan lagi tagihan yang bisa dihitung.
     * Pembayaran dijumlahkan lewat subquery, bukan join, supaya piutang
     * yang dicicil tidak muncul berkali-kali.
     */
    private function query(?string $kind, ?int $categoryId)
    {
        $q = DB::table('finance.other_receivables as p')
            ->leftJoinSub(
                DB::table('finance.other_receivable_payments')
                    ->groupBy('receivable_id')
                    ->selectRaw('receivable_id, sum(amount) AS dibayar'),
                'b',
                'b.receivable_id',
                '=',
                'p.id'
            )
            ->where('p.status', OtherReceivable::BERJALAN)
            ->whereRaw('p.amount - coalesce(b.dibayar, 0) > 0');

        if ($kind !== null && $kind !== '') {
            $q->where('p.kind', $kind);
        }

        if ($categoryId !== null) {
            $q->where('p.category_id', $categoryId);
        }

        return $q;
    }
}
