<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\Collection as LibraryCollection;
use App\Modules\Library\Models\Fine;
use App\Modules\Library\Models\Item;
use App\Modules\Library\Models\Loan;
use App\Modules\Library\Models\LoanPolicy;
use App\Modules\Library\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sirkulasi perpustakaan: peminjaman, pengembalian, denda (domain Q item B).
 */
class CirculationService
{
    public function __construct(private readonly LibraryNumberAllocator $numbers) {}

    /**
     * Meminjamkan satu eksemplar.
     *
     * Aturan yang berlaku SAAT INI dibekukan ke pinjaman: jatuh tempo
     * dihitung sekali dari lama pinjam yang berlaku hari ini, dan tarif
     * denda hariannya disalin. Ini bukan menyimpan nilai turunan — jatuh
     * tempo adalah janji yang dibuat di meja sirkulasi, dan menghitungnya
     * ulang dari pengaturan yang berlaku nanti akan menggeser jatuh tempo
     * seluruh pinjaman yang sedang berjalan.
     */
    public function borrow(Member $member, Item $item, ?int $issuedBy = null, ?string $issuedByName = null): Loan
    {
        $policy = $this->activePolicy();

        $this->assertAnggotaBolehPinjam($member, $policy);
        $this->assertEksemplarBolehDipinjam($item);

        return DB::transaction(function () use ($member, $item, $policy, $issuedBy, $issuedByName) {
            return Loan::query()->create([
                'loan_number' => $this->numbers->allocate('PJM'),
                'member_id' => $member->id,
                'item_id' => $item->id,
                'borrowed_at' => now(),
                'due_date' => now()->addDays($policy->loan_days)->toDateString(),

                'policy_id' => $policy->id,
                'policy_daily_fine' => $policy->daily_fine,
                'policy_loan_days' => $policy->loan_days,

                'issued_by' => $issuedBy,
                'issued_by_name' => $issuedByName,
            ]);
        });
    }

    /**
     * Mengembalikan eksemplar.
     *
     * Kondisi saat kembali dicatat TERPISAH dari kondisi eksemplar, lalu
     * kondisi eksemplarnya diperbarui. Keduanya perlu: yang pertama fakta
     * tentang transaksi ini, yang kedua keadaan bendanya sekarang.
     *
     * Denda keterlambatan dibuat otomatis kalau memang terlambat —
     * keterlambatannya DIHITUNG dari selisih jatuh tempo dan tanggal
     * kembali, dua kolom yang memang terpisah.
     */
    public function returnItem(
        Loan $loan,
        string $kondisi = Item::KONDISI_BAIK,
        ?int $receivedBy = null,
        ?string $receivedByName = null,
        ?string $catatan = null
    ): Loan {
        if ($loan->returned_at !== null) {
            throw new LibraryException('Pinjaman '.$loan->loan_number.' sudah dikembalikan.');
        }

        if (! in_array($kondisi, Item::KONDISI, true)) {
            throw new LibraryException('Kondisi "'.$kondisi.'" tidak dikenal.');
        }

        return DB::transaction(function () use ($loan, $kondisi, $receivedBy, $receivedByName, $catatan) {
            $loan->update([
                'returned_at' => now(),
                'returned_condition' => $kondisi,
                'received_by' => $receivedBy,
                'received_by_name' => $receivedByName,
                'note' => $catatan,
            ]);

            $loan->item->update(['condition' => $kondisi]);

            $loan->refresh();

            $terlambat = $loan->hariTerlambat();

            if ($terlambat > 0 && (float) $loan->policy_daily_fine > 0) {
                $this->chargeLateFine($loan, $terlambat);
            }

            return $loan;
        });
    }

    /**
     * Denda keterlambatan dari tarif yang DIBEKUKAN pada pinjamannya.
     *
     * Tidak menunggu daftar jenis denda: besarannya keluar dari tarif
     * harian yang sudah melekat pada pinjaman, jadi ia tidak bergantung
     * pada kosakata yang belum ditetapkan RSP UI.
     */
    public function chargeLateFine(Loan $loan, int $hariTerlambat): Fine
    {
        if ($hariTerlambat < 1) {
            throw new LibraryException('Denda keterlambatan hanya untuk pinjaman yang memang terlambat.');
        }

        if ($loan->fines()->where('kind', Fine::JENIS_KETERLAMBATAN)->exists()) {
            throw new LibraryException('Pinjaman ini sudah punya denda keterlambatan.');
        }

        return Fine::query()->create([
            'fine_number' => $this->numbers->allocate('DND'),
            'member_id' => $loan->member_id,
            'loan_id' => $loan->id,
            'kind' => Fine::JENIS_KETERLAMBATAN,
            'days_late' => $hariTerlambat,
            'amount' => round($hariTerlambat * (float) $loan->policy_daily_fine, 2),
            'charged_at' => now(),
        ]);
    }

    public function pay(Fine $fine, float $jumlah, ?int $recordedBy = null): Fine
    {
        $this->assertBelumDiselesaikan($fine);

        if ($jumlah <= 0) {
            throw new LibraryException('Jumlah pembayaran denda harus lebih dari nol.');
        }

        $fine->update([
            'paid_at' => now(),
            'paid_amount' => $jumlah,
            'recorded_by' => $recordedBy,
        ]);

        return $fine->refresh();
    }

    /**
     * Membebaskan denda — alasannya WAJIB.
     *
     * Ketaksimetrisan dengan pay() disengaja: denda yang dibayar
     * meninggalkan bukti pada uangnya sendiri, denda yang dibebaskan tidak
     * meninggalkan apa pun selain catatan ini. Pembebasan tanpa jejak
     * adalah bentuk penyalahgunaan yang paling mudah dilakukan orang
     * dalam.
     */
    public function waive(Fine $fine, string $alasan, ?int $recordedBy = null): Fine
    {
        $this->assertBelumDiselesaikan($fine);

        if (blank($alasan)) {
            throw new LibraryException('Pembebasan denda harus menyebutkan alasannya.');
        }

        $fine->update([
            'waived_at' => now(),
            'waived_reason' => $alasan,
            'recorded_by' => $recordedBy,
        ]);

        return $fine->refresh();
    }

    /**
     * Pinjaman yang lewat jatuh tempo dan belum kembali.
     *
     * DIHITUNG dari tanggal, bukan status yang disimpan: status terlambat
     * yang disimpan menuntut ada yang menjalankannya tiap hari, dan
     * pinjaman yang lewat tempo pada hari sistem itu mati akan selamanya
     * tampak tepat waktu.
     *
     * @return Collection<int, Loan>
     */
    public function overdue(): Collection
    {
        return Loan::query()
            ->with(['member', 'item.collection'])
            ->whereNull('returned_at')
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date')
            ->get();
    }

    public function activePolicy(): LoanPolicy
    {
        $policy = LoanPolicy::query()->where('is_active', true)->first();

        if ($policy === null) {
            throw new LibraryException(
                'Pengaturan peminjaman belum ditetapkan — lama pinjam dan tarif denda harus ada '.
                'sebelum ada yang meminjam, karena keduanya dibekukan ke pinjamannya.'
            );
        }

        return $policy;
    }

    /** Menetapkan pengaturan baru; yang lama dinonaktifkan, tidak dihapus. */
    public function setPolicy(array $data, ?int $createdBy = null): LoanPolicy
    {
        return DB::transaction(function () use ($data, $createdBy) {
            LoanPolicy::query()->where('is_active', true)->update(['is_active' => false]);

            return LoanPolicy::query()->create($data + [
                'effective_from' => now()->toDateString(),
                'is_active' => true,
                'created_by' => $createdBy,
            ]);
        });
    }

    private function assertBelumDiselesaikan(Fine $fine): void
    {
        if ($fine->paid_at !== null) {
            throw new LibraryException('Denda '.$fine->fine_number.' sudah dibayar.');
        }

        if ($fine->waived_at !== null) {
            throw new LibraryException('Denda '.$fine->fine_number.' sudah dibebaskan.');
        }
    }

    private function assertAnggotaBolehPinjam(Member $member, LoanPolicy $policy): void
    {
        if (! $member->is_active) {
            throw new LibraryException('Keanggotaan '.$member->name.' tidak aktif.');
        }

        if ($member->kedaluwarsa()) {
            throw new LibraryException(
                'Masa berlaku keanggotaan '.$member->name.' sudah habis pada '.
                $member->expires_at->format('d-m-Y').'.'
            );
        }

        $dipegang = $member->loans()->whereNull('returned_at')->count();

        if ($dipegang >= $policy->max_items) {
            throw new LibraryException(
                $member->name.' sedang memegang '.$dipegang.' eksemplar, batasnya '.$policy->max_items.'.'
            );
        }
    }

    private function assertEksemplarBolehDipinjam(Item $item): void
    {
        /*
         * "Sedang dipinjam" DIHITUNG, tidak dibaca dari kolom status pada
         * eksemplar. Khanza menyimpannya sebagai nilai enum `status_buku`,
         * yang berarti dua sumber untuk satu fakta — begitu satu transaksi
         * gagal di tengah, keduanya berbeda dan tidak ada cara menentukan
         * mana yang benar.
         */
        if ($item->sedangDipinjam()) {
            throw new LibraryException('Eksemplar '.$item->inventory_number.' sedang dipinjam.');
        }

        if ($item->condition !== Item::KONDISI_BAIK) {
            throw new LibraryException(
                'Eksemplar '.$item->inventory_number.' berkondisi '.$item->condition.
                ', tidak bisa dipinjamkan.'
            );
        }

        if ($item->collection->medium !== LibraryCollection::MEDIUM_CETAK) {
            throw new LibraryException('Ebook tidak dipinjamkan sebagai eksemplar fisik.');
        }
    }
}
