<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\PrescriptionItem;
use App\Modules\Pharmacy\Models\PrescriptionReview;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pintu masuk konteks pharmacy.
 *
 * Alur resep sengaja tidak bisa dipotong:
 *
 *   ditulis → menunggu-telaah → disetujui → diserahkan
 *                            ↘ ditolak → (diperbaiki) → menunggu-telaah
 *
 * Telaah apoteker bukan pelengkap. Resep tidak punya jalan pintas dari
 * ditulis langsung ke diserahkan, dan stok baru berkurang pada langkah
 * terakhir — bukan saat resep ditulis.
 */
class PrescriptionService
{
    private const VIEW_REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly AllergyScreening $screening,
    ) {}

    /**
     * @throws PharmacyException
     */
    public function create(int $registrationId, ?User $actor = null): Prescription
    {
        $kunjungan = $this->registration($registrationId)
            ?? throw new PharmacyException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $berjalan = Prescription::query()
            ->where('registration_id', $registrationId)
            ->whereIn('status', [Prescription::STATUS_DITULIS, Prescription::STATUS_MENUNGGU_TELAAH])
            ->first();

        if ($berjalan !== null) {
            return $berjalan;
        }

        return Prescription::query()->create([
            'prescription_number' => $this->allocateNumber(),
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'unit_name' => $kunjungan->unit_name,
            'prescriber_id' => $kunjungan->practitioner_id,
            'prescriber_name' => $kunjungan->practitioner_name,
            'status' => Prescription::STATUS_DITULIS,
            'prescribed_at' => now(),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * @throws PharmacyException
     */
    public function addItem(
        Prescription $prescription,
        int $drugId,
        float $quantity,
        string $dosageInstruction,
        ?string $note = null,
    ): PrescriptionItem {
        if (! $prescription->isEditable()) {
            throw new PharmacyException(
                'Resep ini tidak bisa diubah lagi. Status: ' . Prescription::statusLabel($prescription->status) . '.'
            );
        }

        if ($quantity <= 0) {
            throw new PharmacyException('Jumlah obat harus lebih dari nol.');
        }

        $obat = Drug::query()->find($drugId)
            ?? throw new PharmacyException('Obat tidak ditemukan.');

        if (! $obat->is_active) {
            throw new PharmacyException("{$obat->name} sudah tidak aktif dan tidak bisa diresepkan.");
        }

        $sudahAda = $prescription->items()->where('drug_id', $drugId)->exists();

        if ($sudahAda) {
            throw new PharmacyException("{$obat->name} sudah ada di resep ini.");
        }

        return DB::transaction(function () use ($prescription, $obat, $quantity, $dosageInstruction, $note) {
            $item = $prescription->items()->create([
                'drug_id' => $obat->id,
                // Disalin: harga dan nama boleh berubah, isi resep yang sudah
                // ditulis tidak boleh ikut berubah.
                'drug_name' => $obat->name,
                'drug_unit' => $obat->unit,
                'unit_price' => $obat->sell_price,
                'quantity' => $quantity,
                'dosage_instruction' => $dosageInstruction,
                'note' => $note,
            ]);

            $this->recalculateTotal($prescription);

            return $item;
        });
    }

    /**
     * @throws PharmacyException
     */
    public function removeItem(PrescriptionItem $item): void
    {
        $prescription = $item->prescription;

        if (! $prescription->isEditable()) {
            throw new PharmacyException('Resep ini tidak bisa diubah lagi.');
        }

        DB::transaction(function () use ($item, $prescription): void {
            $item->delete();
            $this->recalculateTotal($prescription);
        });
    }

    /**
     * Mengirim resep ke apoteker untuk ditelaah.
     *
     * @throws PharmacyException
     */
    public function submit(Prescription $prescription): Prescription
    {
        if ($prescription->status === Prescription::STATUS_MENUNGGU_TELAAH) {
            throw new PharmacyException('Resep ini sudah menunggu telaah.');
        }

        if (! $prescription->isEditable()) {
            throw new PharmacyException('Resep ini sudah melewati tahap telaah.');
        }

        if ($prescription->items()->count() === 0) {
            throw new PharmacyException('Resep kosong tidak bisa dikirim ke apoteker.');
        }

        $prescription->update([
            'status' => Prescription::STATUS_MENUNGGU_TELAAH,
            'submitted_at' => now(),
        ]);

        return $prescription->refresh();
    }

    /**
     * Hasil penyaringan otomatis untuk membantu apoteker menelaah.
     */
    public function screen(Prescription $prescription): array
    {
        return $this->screening->screen($prescription);
    }

    /**
     * Telaah apoteker.
     *
     * Temuan penyaringan disimpan sebagaimana adanya saat telaah dilakukan.
     * Data alergi pasien bisa berubah kemudian; dasar keputusan apoteker
     * tidak boleh ikut berubah.
     *
     * @throws PharmacyException
     */
    public function review(
        Prescription $prescription,
        string $outcome,
        ?string $note = null,
        ?User $actor = null,
    ): Prescription {
        if ($prescription->status !== Prescription::STATUS_MENUNGGU_TELAAH) {
            throw new PharmacyException(
                'Hanya resep yang menunggu telaah yang bisa ditelaah. Status sekarang: '
                . Prescription::statusLabel($prescription->status) . '.'
            );
        }

        if (! in_array($outcome, ['disetujui', 'ditolak'], true)) {
            throw new PharmacyException('Hasil telaah harus disetujui atau ditolak.');
        }

        $temuan = $this->screen($prescription);

        if ($outcome === 'disetujui'
            && $this->screening->hasSevereFinding($temuan)
            && blank($note)) {
            throw new PharmacyException(
                'Resep ini memicu peringatan alergi berat. Persetujuan wajib disertai catatan apoteker.'
            );
        }

        return DB::transaction(function () use ($prescription, $outcome, $note, $actor, $temuan): Prescription {
            PrescriptionReview::query()->create([
                'prescription_id' => $prescription->id,
                'outcome' => $outcome,
                'findings' => $temuan,
                'pharmacist_note' => $note,
                'reviewer_id' => $actor?->id,
                'reviewer_name' => $actor?->name,
                'reviewed_at' => now(),
            ]);

            $prescription->update([
                'status' => $outcome === 'disetujui'
                    ? Prescription::STATUS_DISETUJUI
                    : Prescription::STATUS_DITOLAK,
                'reviewed_at' => now(),
                'reviewed_by' => $actor?->id,
                'reviewed_by_name' => $actor?->name,
            ]);

            return $prescription->refresh();
        });
    }

    /**
     * Menyerahkan obat dan mengurangi stok.
     *
     * Baru di sinilah stok berkurang. Seluruh item diserahkan dalam satu
     * transaksi: kalau satu item gagal karena stok kurang, tidak ada item
     * lain yang terlanjur dipotong.
     *
     * @throws PharmacyException
     */
    public function dispense(Prescription $prescription, int $locationId, ?User $actor = null): Prescription
    {
        if (! $prescription->isDispensable()) {
            throw new PharmacyException(
                'Resep hanya bisa diserahkan setelah disetujui apoteker. Status sekarang: '
                . Prescription::statusLabel($prescription->status) . '.'
            );
        }

        $items = $prescription->items()->get();

        if ($items->isEmpty()) {
            throw new PharmacyException('Resep kosong tidak bisa diserahkan.');
        }

        return DB::transaction(function () use ($prescription, $items, $locationId, $actor): Prescription {
            foreach ($items as $item) {
                try {
                    $this->stock->issue(
                        drugId: $item->drug_id,
                        locationId: $locationId,
                        quantity: (float) $item->quantity,
                        referenceType: 'prescription',
                        referenceId: $prescription->id,
                        actor: $actor,
                    );
                } catch (PharmacyException $e) {
                    throw new PharmacyException("{$item->drug_name}: {$e->getMessage()}");
                }

                $item->update(['dispensed_quantity' => $item->quantity]);
            }

            $prescription->update([
                'status' => Prescription::STATUS_DISERAHKAN,
                'dispensed_at' => now(),
                'dispensed_by' => $actor?->id,
                'dispensed_by_name' => $actor?->name,
                'location_id' => $locationId,
            ]);

            return $prescription->refresh();
        });
    }

    /**
     * @throws PharmacyException
     */
    public function cancel(Prescription $prescription, string $reason, ?User $actor = null): Prescription
    {
        if ($prescription->status === Prescription::STATUS_DISERAHKAN) {
            throw new PharmacyException(
                'Resep yang sudah diserahkan tidak bisa dibatalkan. Gunakan retur obat.'
            );
        }

        $prescription->update([
            'status' => Prescription::STATUS_BATAL,
            'cancellation_reason' => $reason,
        ]);

        return $prescription->refresh();
    }

    private function recalculateTotal(Prescription $prescription): void
    {
        $total = $prescription->items()->get()->sum(fn (PrescriptionItem $i) => $i->subtotal());

        $prescription->update(['total_amount' => $total]);
    }

    /** Nomor resep harian, dialokasikan atomik seperti nomor antrean. */
    private function allocateNumber(): string
    {
        $prefix = now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO pharmacy.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = pharmacy.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'R' . $prefix . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }

    /** Konteks kunjungan, lewat view yang diterbitkan konteks encounter. */
    private function registration(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW_REGISTRASI)->where('id', $registrationId)->first();
    }
}
