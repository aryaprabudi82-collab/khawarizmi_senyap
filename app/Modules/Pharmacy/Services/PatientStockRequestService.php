<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\PatientStockRequest;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * permintaan_stok_obat_pasien + stok_obat_pasien — sama pola dengan
 * WardStockRequestService, tapi terikat registrasi/pasien tertentu
 * (bukan unit). Dipakai untuk kebutuhan obat/BHP ad-hoc pasien di luar
 * resep formal (mis. consumables tambahan selama rawat inap).
 */
class PatientStockRequestService
{
    private const DEPO_CODE = 'DEPO-RJ';
    private const VIEW_REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, float> $items drugId => quantity_requested */
    public function request(int $registrationId, array $items, ?string $notes, int $requestedBy): PatientStockRequest
    {
        if ($items === []) {
            throw new PharmacyException('Permintaan harus berisi minimal satu obat/BHP.');
        }

        $kunjungan = $this->registration($registrationId)
            ?? throw new PharmacyException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        return DB::transaction(function () use ($kunjungan, $items, $notes, $requestedBy): PatientStockRequest {
            $permintaan = PatientStockRequest::query()->create([
                'request_number' => $this->numbers->allocate('PSP'),
                'registration_id' => $kunjungan->id,
                'registration_number' => $kunjungan->registration_number,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'status' => PatientStockRequest::STATUS_DIAJUKAN,
                'notes' => $notes,
                'requested_by' => $requestedBy,
            ]);

            foreach ($items as $drugId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $permintaan->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity_requested' => $quantity,
                ]);
            }

            return $permintaan->fresh('items');
        });
    }

    public function reject(PatientStockRequest $permintaan, string $reason): PatientStockRequest
    {
        if (! $permintaan->isPending()) {
            throw new PharmacyException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $permintaan->update(['status' => PatientStockRequest::STATUS_DITOLAK, 'rejection_reason' => $reason]);

        return $permintaan->refresh();
    }

    public function issue(PatientStockRequest $permintaan, User $actor): PatientStockRequest
    {
        if (! $permintaan->isPending()) {
            throw new PharmacyException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($permintaan, $actor, $depo): PatientStockRequest {
            $permintaan->load('items');

            foreach ($permintaan->items as $baris) {
                $this->ledger->issue(
                    drugId: $baris->drug_id,
                    locationId: $depo->id,
                    quantity: (float) $baris->quantity_requested,
                    referenceType: 'patient-stock-request',
                    referenceId: $permintaan->id,
                    actor: $actor,
                );

                $baris->update(['quantity_issued' => $baris->quantity_requested]);
            }

            $permintaan->update(['status' => PatientStockRequest::STATUS_DIKELUARKAN, 'issued_by' => $actor->id, 'issued_at' => now()]);

            return $permintaan->refresh();
        });
    }

    /** stok_obat_pasien — seluruh permintaan (dan barisnya) untuk satu registrasi, terbaru dulu. */
    public function forRegistration(int $registrationId): Collection
    {
        return PatientStockRequest::query()
            ->with('items')
            ->where('registration_id', $registrationId)
            ->latest('created_at')
            ->get();
    }

    /** Dipakai layar untuk mencari kunjungan sebelum mengajukan permintaan — staf tidak tahu registration_id mentah. */
    public function findRegistrationByNumber(string $registrationNumber): ?stdClass
    {
        return DB::table(self::VIEW_REGISTRASI)->where('registration_number', $registrationNumber)->first();
    }

    private function registration(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW_REGISTRASI)->where('id', $registrationId)->first();
    }
}
