<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\PatientDrugReturn;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * retur_obat_ranap — obat rawat inap yang tak terpakai dikembalikan ke
 * DEPO-RJ. Kembali lewat StockLedger::receive() ke batch retur
 * tersendiri (kind 'retur'), pola sama dengan RetailSaleService.
 */
class PatientDrugReturnService
{
    private const DEPO_CODE = 'DEPO-RJ';
    private const VIEW_REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, float> $items drugId => quantity */
    public function return(int $registrationId, array $items, ?string $notes, User $actor): PatientDrugReturn
    {
        if ($items === []) {
            throw new PharmacyException('Retur harus berisi minimal satu obat/BHP.');
        }

        $kunjungan = $this->registration($registrationId)
            ?? throw new PharmacyException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($kunjungan, $items, $notes, $actor, $depo): PatientDrugReturn {
            $retur = PatientDrugReturn::query()->create([
                'return_number' => $this->numbers->allocate('ROR'),
                'registration_id' => $kunjungan->id,
                'registration_number' => $kunjungan->registration_number,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'returned_at' => now(),
                'returned_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $drugId => $quantity) {
                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                $retur->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity' => $quantity,
                ]);

                $this->ledger->receive(
                    drugId: $drugId,
                    locationId: $depo->id,
                    batchNumber: 'RETUR-' . $retur->return_number,
                    quantity: $quantity,
                    actor: $actor,
                    note: "Retur obat ranap {$retur->return_number} / {$kunjungan->registration_number}",
                    kind: 'retur',
                    referenceType: 'patient-drug-return',
                    referenceId: $retur->id,
                );
            }

            return $retur->fresh('items');
        });
    }

    /** Dipakai layar untuk mencari kunjungan sebelum mengajukan retur — staf tidak tahu registration_id mentah. */
    public function findRegistrationByNumber(string $registrationNumber): ?stdClass
    {
        return DB::table(self::VIEW_REGISTRASI)->where('registration_number', $registrationNumber)->first();
    }

    private function registration(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW_REGISTRASI)->where('id', $registrationId)->first();
    }
}
