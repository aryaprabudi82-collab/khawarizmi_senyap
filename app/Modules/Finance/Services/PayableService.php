<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\PayablePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hutang vendor (domain K item B) — ~20 kode.
 *
 *   submit / validate / reject -> titip_faktur_* dan validasi_tagihan_*
 *   pay                        -> bayar_pemesanan_* dan bayar_pesan_*
 *   outstanding / aging        -> hutang_*, tagihan_hutang_obat
 *   bySupplier                 -> ringkasan_hutang_vendor_*
 *
 * SATU BUKU untuk empat rantai pengadaan, dibedakan kolom source_context.
 *
 * Tiga aturan yang dipegang seluruh kelas ini:
 *
 * 1. Nilai hutang DIBEKUKAN saat validasi. Tidak pernah dihitung ulang
 *    dari penerimaan barang, supaya koreksi harga di rantai pengadaan
 *    tidak diam-diam mengubah hutang yang sudah disepakati.
 *
 * 2. Sisa hutang SELALU DIHITUNG, tidak pernah disimpan — pola yang sama
 *    seperti piutang pasien di domain I item B.
 *
 * 3. Hanya faktur TERVALIDASI yang boleh dibayar dan yang masuk hitungan
 *    hutang. Faktur yang baru dititipkan belum tentu benar; memasukkannya
 *    ke neraca berarti mengakui hutang yang belum diperiksa.
 */
class PayableService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    // ------------------------------------------------------------- pencatatan

    /**
     * Vendor menitipkan faktur (titip_faktur_*).
     *
     * @throws FinanceException
     */
    public function submit(array $data, ?int $actorId = null): Payable
    {
        if (! isset(Payable::SUMBER[$data['source_context'] ?? ''])) {
            throw new FinanceException('Rantai pengadaan asal faktur tidak dikenal.');
        }

        $nilai = (float) ($data['amount'] ?? 0);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai faktur harus lebih dari nol.');
        }

        if (strtotime($data['due_date']) < strtotime($data['invoice_date'])) {
            throw new FinanceException('Jatuh tempo tidak boleh mendahului tanggal faktur.');
        }

        $ganda = Payable::query()
            ->where('supplier_name', $data['supplier_name'])
            ->where('invoice_number', $data['invoice_number'])
            ->where('status', '<>', Payable::DITOLAK)
            ->exists();

        if ($ganda) {
            throw new FinanceException(
                "Faktur {$data['invoice_number']} dari {$data['supplier_name']} sudah pernah dititipkan."
            );
        }

        // Beban hutang lain tidak melewati alur titip-lalu-validasi:
        // tidak ada vendor yang menitipkan apa pun, yang mencatat sudah
        // keuangan sendiri. Memaksanya menunggu validasi berarti meminta
        // orang memvalidasi catatannya sendiri — ritual yang tidak
        // memeriksa apa pun tapi membuat hutang tidak terhitung sampai
        // seseorang menekan tombol.
        $langsungSah = $data['source_context'] === 'lain';

        return Payable::query()->create([
            'payable_number' => $this->numbers->allocate($langsungSah ? 'HTL' : 'HTG'),
            'source_context' => $data['source_context'],
            'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
            'receipt_number' => $data['receipt_number'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'],
            'invoice_number' => $data['invoice_number'],
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'amount' => $nilai,
            'status' => $langsungSah ? Payable::TERVALIDASI : Payable::DITITIPKAN,
            'validated_at' => $langsungSah ? now() : null,
            'validated_by' => $langsungSah ? $actorId : null,
            'account_id' => $data['account_id'] ?? null,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Keuangan memvalidasi faktur (validasi_tagihan_*).
     *
     * Nilai boleh dikoreksi di sini — inilah saatnya angka dibekukan.
     * Setelah tervalidasi, nilai tidak boleh berubah lagi.
     *
     * @throws FinanceException
     */
    public function validate(Payable $payable, ?float $correctedAmount = null, ?int $actorId = null): Payable
    {
        if ($payable->status !== Payable::DITITIPKAN) {
            throw new FinanceException('Hanya faktur yang masih dititipkan yang bisa divalidasi.');
        }

        if ($correctedAmount !== null && $correctedAmount <= 0) {
            throw new FinanceException('Nilai koreksi harus lebih dari nol.');
        }

        $payable->update([
            'amount' => $correctedAmount ?? $payable->amount,
            'status' => Payable::TERVALIDASI,
            'validated_at' => now(),
            'validated_by' => $actorId,
        ]);

        return $payable->refresh();
    }

    /**
     * @throws FinanceException
     */
    public function reject(Payable $payable, string $reason, ?int $actorId = null): Payable
    {
        if ($payable->status !== Payable::DITITIPKAN) {
            throw new FinanceException('Hanya faktur yang masih dititipkan yang bisa ditolak.');
        }

        $payable->update([
            'status' => Payable::DITOLAK,
            'rejection_reason' => $reason,
            'validated_by' => $actorId,
            'validated_at' => now(),
        ]);

        return $payable->refresh();
    }

    /**
     * Membayar hutang, boleh dicicil.
     *
     * Pembayaran melebihi sisa DITOLAK. Membiarkannya lewat akan
     * menghasilkan sisa negatif yang, kalau dijumlahkan bersama hutang
     * lain, diam-diam mengurangi total hutang rumah sakit.
     *
     * @throws FinanceException
     */
    public function pay(Payable $payable, array $data, ?int $actorId = null, ?string $actorName = null): PayablePayment
    {
        if (! $payable->isValidated()) {
            throw new FinanceException('Faktur harus divalidasi lebih dulu sebelum dibayar.');
        }

        if ($payable->status === Payable::LUNAS) {
            throw new FinanceException('Faktur ini sudah lunas.');
        }

        $nilai = round((float) ($data['amount'] ?? 0), 2);

        if ($nilai <= 0) {
            throw new FinanceException('Nilai pembayaran harus lebih dari nol.');
        }

        $sisa = $payable->remaining();

        if ($nilai > $sisa) {
            throw new FinanceException(
                'Pembayaran melebihi sisa hutang (sisa ' . number_format($sisa, 2, ',', '.') . ').'
            );
        }

        return DB::transaction(function () use ($payable, $data, $nilai, $sisa, $actorId, $actorName) {
            $bayar = PayablePayment::query()->create([
                'payment_number' => $this->numbers->allocate('BHT'),
                'payable_id' => $payable->id,
                'paid_on' => $data['paid_on'],
                'amount' => $nilai,
                'payment_method' => $data['payment_method'] ?? 'transfer',
                'reference_number' => $data['reference_number'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_by' => $actorId,
                'paid_by_name' => $actorName,
            ]);

            // Status lunas ditetapkan dari perbandingan, bukan disimpan
            // sebagai sisa. Status boleh diturunkan dari angka; sisa tidak
            // boleh disimpan berdampingan dengan angka yang menurunkannya.
            if ($nilai >= $sisa) {
                $payable->update(['status' => Payable::LUNAS]);
            }

            return $bayar;
        });
    }

    // ---------------------------------------------------------------- laporan

    /**
     * Hutang yang masih terutang, berikut sisanya.
     *
     * Hanya faktur tervalidasi dan lunas-sebagian yang masuk. Yang masih
     * dititipkan sengaja tidak dihitung sebagai hutang.
     */
    public function outstanding(?string $source = null, ?string $supplier = null): Collection
    {
        return $this->outstandingQuery($source, $supplier)
            ->select('p.*', DB::raw('coalesce(b.dibayar, 0) AS dibayar'),
                DB::raw('p.amount - coalesce(b.dibayar, 0) AS sisa'))
            ->orderBy('p.due_date')
            ->get();
    }

    /**
     * Umur hutang — dikelompokkan menurut lama lewat jatuh tempo.
     *
     * Jatuh tempo dibandingkan dengan HARI INI, dan yang belum jatuh tempo
     * dipisahkan sendiri alih-alih dilebur ke kelompok "0-30 hari": hutang
     * yang belum jatuh tempo bukan tunggakan, dan menggabungkannya membuat
     * posisi rumah sakit terlihat lebih buruk daripada kenyataannya.
     */
    public function aging(?string $source = null): Collection
    {
        $umur = "CASE
            WHEN p.due_date >= current_date THEN 'belum jatuh tempo'
            WHEN current_date - p.due_date <= 30 THEN '1-30 hari'
            WHEN current_date - p.due_date <= 60 THEN '31-60 hari'
            WHEN current_date - p.due_date <= 90 THEN '61-90 hari'
            ELSE 'lebih dari 90 hari'
        END";

        return $this->outstandingQuery($source, null)
            ->groupBy(DB::raw($umur))
            ->selectRaw("{$umur} AS kelompok,
                         count(*) AS faktur,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa")
            ->get();
    }

    /** Ringkasan per vendor — ringkasan_hutang_vendor_*. */
    public function bySupplier(?string $source = null): Collection
    {
        return $this->outstandingQuery($source, null)
            ->groupBy('p.supplier_name')
            ->selectRaw('p.supplier_name,
                         count(*) AS faktur,
                         sum(p.amount) AS nilai,
                         sum(coalesce(b.dibayar, 0)) AS dibayar,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa')
            ->orderByDesc('sisa')
            ->get();
    }

    /** Ringkasan per rantai pengadaan — memperlihatkan hutang terbesar ada di mana. */
    public function bySource(): Collection
    {
        return $this->outstandingQuery(null, null)
            ->groupBy('p.source_context')
            ->selectRaw('p.source_context,
                         count(*) AS faktur,
                         sum(p.amount - coalesce(b.dibayar, 0)) AS sisa')
            ->orderByDesc('sisa')
            ->get();
    }

    /** Faktur yang masih menunggu divalidasi — pekerjaan yang belum selesai. */
    public function pending(?string $source = null): Collection
    {
        $q = DB::table('finance.payables')
            ->where('status', Payable::DITITIPKAN)
            ->orderBy('invoice_date');

        if ($source !== null && $source !== '') {
            $q->where('source_context', $source);
        }

        return $q->get();
    }

    /** Pembayaran pada satu rentang. */
    public function payments(string $from, string $until): Collection
    {
        return DB::table('finance.payable_payments as b')
            ->join('finance.payables as p', 'p.id', '=', 'b.payable_id')
            ->whereBetween('b.paid_on', [$from, $until])
            ->selectRaw('b.payment_number, b.paid_on, b.amount, b.payment_method,
                         p.payable_number, p.supplier_name, p.invoice_number, p.source_context')
            ->orderByDesc('b.paid_on')
            ->get();
    }

    /** Nilai hutang yang akunnya belum dipetakan — akun_bayar_hutang. */
    public function unmappedTotal(): float
    {
        return (float) $this->outstandingQuery(null, null)
            ->whereNull('p.account_id')
            ->sum(DB::raw('p.amount - coalesce(b.dibayar, 0)'));
    }

    /**
     * Builder bersama seluruh angka hutang.
     *
     * SENGAJA TIDAK memasang select: yang memanggil menentukan sendiri
     * kolomnya. Builder yang membawa select p.* akan membuat setiap
     * kueri agregat gagal karena kolom itu ikut terbawa ke luar GROUP BY.
     *
     * Pembayaran dijumlahkan lewat subquery, BUKAN join ke tabel
     * pembayaran: faktur yang dicicil tiga kali akan muncul tiga baris
     * kalau di-join, dan nilai fakturnya ikut terhitung tiga kali —
     * pelajaran yang sama seperti keanggotaan surveilans di domain J.
     */
    private function outstandingQuery(?string $source, ?string $supplier)
    {
        $q = DB::table('finance.payables as p')
            ->leftJoinSub(
                DB::table('finance.payable_payments')
                    ->groupBy('payable_id')
                    ->selectRaw('payable_id, sum(amount) AS dibayar'),
                'b',
                'b.payable_id',
                '=',
                'p.id'
            )
            ->whereIn('p.status', [Payable::TERVALIDASI, Payable::LUNAS])
            ->whereRaw('p.amount - coalesce(b.dibayar, 0) > 0');

        if ($source !== null && $source !== '') {
            $q->where('p.source_context', $source);
        }

        if ($supplier !== null && $supplier !== '') {
            $q->where('p.supplier_name', $supplier);
        }

        return $q;
    }
}
