<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Models\ReceivableCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penagihan & laporan piutang pasien (domain K item D) — ~10 kode.
 *
 *   contact / validate  -> penagihan_piutang_pasien,
 *                          validasi_penagihan_piutang
 *   outstanding / aging -> rincian_piutang_pasien, piutang_pasien2
 *   byPayer / monthly   -> ringkasan piutang per cara bayar
 *
 * CATATAN PENTING TENTANG "PER CARA BAYAR": piutang PASIEN hanya lahir
 * dari tagihan yang tanggung jawabnya ada pada pasien — tagihan yang
 * ditanggung penjamin ditagihkan lewat klaim dan tidak pernah menjadi
 * utang pasien (ditegakkan InvoiceService::createReceivable). Akibatnya
 * pemilahan per cara bayar di sini akan hampir selalu berisi satu baris
 * saja. Tiga kode Khanza detail_piutang_penjab,
 * ringkasan_piutang_jenis_bayar, dan nilai_piutang_perjenis_bayar_per_bulan
 * sesungguhnya menanyakan piutang PENJAMIN — itu tinggal di
 * finance.receivables dan punya layarnya sendiri. Pemilahan di sini tetap
 * disediakan karena tetap benar dan berguna saat ada penjamin lain yang
 * hanya menanggung sebagian, tapi jangan dikira menjawab ketiga kode itu.
 *
 * PEMBAYARANNYA TIDAK DI SINI. Sisa piutang pasien diturunkan dari sisa
 * tagihannya, dan pembayaran atas tagihan sudah punya tempatnya sendiri
 * di billing.payments sejak awal. Kelas ini mencatat KONTAK penagihan —
 * peristiwa yang memang belum pernah tercatat — bukan uangnya.
 *
 * Sisa dihitung lewat SATU ekspresi SQL yang sama dengan yang dipakai
 * model: pokok tagihan dikurangi pembayaran yang tidak di-void. Menyalin
 * rumus ini ke beberapa tempat adalah cara termudah membuat dua laporan
 * piutang yang tidak pernah cocok.
 */
class ReceivableCollectionService
{
    /** Sisa tagihan: total baris biaya dikurangi pembayaran yang sah. */
    private const SISA = '(
        coalesce((SELECT sum(c.amount) FROM billing.charge_lines c WHERE c.invoice_id = i.id), 0)
        - coalesce((SELECT sum(y.amount) FROM billing.payments y WHERE y.invoice_id = i.id AND y.voided_at IS NULL), 0)
    )';

    // ------------------------------------------------------------- pencatatan

    /**
     * Mencatat satu upaya penagihan.
     *
     * @throws BillingException
     */
    public function contact(PatientReceivable $piutang, array $data, ?int $actorId = null, ?string $actorName = null): ReceivableCollection
    {
        if ($piutang->isCancelled()) {
            throw new BillingException('Piutang yang dibatalkan tidak bisa ditagih.');
        }

        if (! isset(ReceivableCollection::HASIL[$data['outcome'] ?? ''])) {
            throw new BillingException('Hasil penagihan tidak dikenal.');
        }

        if (! in_array($data['channel'] ?? '', ReceivableCollection::KANAL, true)) {
            throw new BillingException('Kanal penagihan tidak dikenal.');
        }

        return ReceivableCollection::query()->create([
            'receivable_id' => $piutang->id,
            'contacted_on' => $data['contacted_on'],
            'channel' => $data['channel'],
            'outcome' => $data['outcome'],
            'promised_on' => $data['promised_on'] ?? null,
            'note' => $data['note'] ?? null,
            'contacted_by' => $actorId,
            'contacted_by_name' => $actorName,
        ]);
    }

    /**
     * Penyelia memverifikasi catatan penagihan.
     *
     * Tanpa langkah ini, catatan "sudah ditagih, pasien menolak" bisa
     * ditulis siapa saja dan langsung jadi alasan menghapus piutang.
     *
     * @throws BillingException
     */
    public function validateContact(ReceivableCollection $penagihan, ?int $actorId = null): ReceivableCollection
    {
        if ($penagihan->isValidated()) {
            throw new BillingException('Catatan penagihan ini sudah diverifikasi.');
        }

        if ($actorId !== null && $penagihan->contacted_by === $actorId) {
            throw new BillingException('Catatan penagihan tidak bisa diverifikasi oleh penagihnya sendiri.');
        }

        $penagihan->update(['validated_at' => now(), 'validated_by' => $actorId]);

        return $penagihan->refresh();
    }

    // ---------------------------------------------------------------- laporan

    /** Piutang pasien yang masih bersisa, berikut riwayat penagihannya. */
    public function outstanding(?string $careType = null, ?int $payerId = null): Collection
    {
        return $this->query($careType, $payerId)
            ->selectRaw('p.*, i.invoice_number, i.payer_name, i.payer_id, '
                . self::SISA . ' AS sisa,
                (SELECT count(*) FROM billing.patient_receivable_collections k WHERE k.receivable_id = p.id) AS upaya_tagih,
                (SELECT max(k.contacted_on) FROM billing.patient_receivable_collections k WHERE k.receivable_id = p.id) AS tagih_terakhir')
            ->orderBy('p.due_date')
            ->get();
    }

    /** Ringkasan per cara bayar — ringkasan_piutang_jenis_bayar. */
    public function byPayer(?string $careType = null): Collection
    {
        return $this->query($careType, null)
            ->groupBy('i.payer_name')
            ->selectRaw('i.payer_name, count(*) AS piutang, sum(' . self::SISA . ') AS sisa')
            ->orderByDesc('sisa')
            ->get();
    }

    /** Nilai piutang per cara bayar per bulan. */
    public function monthlyByPayer(?string $careType = null): Collection
    {
        return $this->query($careType, null)
            ->groupBy(DB::raw("to_char(p.due_date, 'YYYY-MM')"), 'i.payer_name')
            ->selectRaw("to_char(p.due_date, 'YYYY-MM') AS bulan, i.payer_name,
                         count(*) AS piutang, sum(" . self::SISA . ') AS sisa')
            ->orderBy('bulan')
            ->orderBy('i.payer_name')
            ->get();
    }

    /** Umur piutang pasien — yang belum jatuh tempo dipisahkan. */
    public function aging(?string $careType = null): Collection
    {
        $umur = "CASE
            WHEN p.due_date >= current_date THEN 'belum jatuh tempo'
            WHEN current_date - p.due_date <= 30 THEN '1-30 hari'
            WHEN current_date - p.due_date <= 60 THEN '31-60 hari'
            WHEN current_date - p.due_date <= 90 THEN '61-90 hari'
            ELSE 'lebih dari 90 hari'
        END";

        return $this->query($careType, null)
            ->groupBy(DB::raw($umur))
            ->selectRaw("{$umur} AS kelompok, count(*) AS piutang, sum(" . self::SISA . ') AS sisa')
            ->get();
    }

    /**
     * Piutang yang belum pernah ditagih sama sekali.
     *
     * Angka ini yang paling berguna: piutang menumpuk paling sering bukan
     * karena pasien menolak, melainkan karena tidak pernah ada yang
     * menagih. Tanpa memisahkannya, kedua sebab itu terlihat sama.
     */
    public function neverContacted(?string $careType = null): Collection
    {
        return $this->query($careType, null)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('billing.patient_receivable_collections as k')
                ->whereColumn('k.receivable_id', 'p.id'))
            ->selectRaw('p.*, i.invoice_number, i.payer_name, ' . self::SISA . ' AS sisa')
            ->orderBy('p.due_date')
            ->get();
    }

    /** Riwayat penagihan satu piutang. */
    public function contacts(PatientReceivable $piutang): Collection
    {
        return ReceivableCollection::query()
            ->where('receivable_id', $piutang->id)
            ->orderByDesc('contacted_on')
            ->get();
    }

    /** Catatan penagihan yang masih menunggu verifikasi penyelia. */
    public function pendingValidation(): Collection
    {
        return DB::table('billing.patient_receivable_collections as k')
            ->join('billing.patient_receivables as p', 'p.id', '=', 'k.receivable_id')
            ->whereNull('k.validated_at')
            ->selectRaw('k.*, p.patient_name, p.invoice_id')
            ->orderBy('k.contacted_on')
            ->get();
    }

    /**
     * Builder bersama seluruh angka piutang pasien.
     *
     * Piutang yang dibatalkan dan yang sudah lunas dikecualikan di SATU
     * tempat. Lunas ditentukan dari sisa tagihannya, bukan dari kolom
     * status — karena tidak ada kolom status, dan memang tidak boleh ada.
     */
    private function query(?string $careType, ?int $payerId)
    {
        $q = DB::table('billing.patient_receivables as p')
            ->join('billing.invoices as i', 'i.id', '=', 'p.invoice_id')
            ->whereNull('p.cancelled_at')
            ->where('i.status', '<>', 'void')
            ->whereRaw(self::SISA . ' > 0');

        if ($careType !== null && $careType !== '') {
            $q->where('p.care_type', $careType);
        }

        if ($payerId !== null) {
            $q->where('i.payer_id', $payerId);
        }

        return $q;
    }
}
