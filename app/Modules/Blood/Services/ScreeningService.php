<?php

namespace App\Modules\Blood\Services;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\Donor;
use App\Modules\Blood\Models\LookbackInvestigation;
use App\Modules\Blood\Models\TransfusionIssue;
use App\Modules\Blood\Models\UnitScreening;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Skrining IMLTD & look-back (domain N item A).
 *
 * LIMA ATURAN.
 *
 * 1. KELIMA PEMERIKSAAN WAJIB. HBsAg, anti-HCV, anti-HIV, sifilis, dan
 *    malaria — bukan sebagian. Sebelum item ini, unit bisa dirilis ke
 *    status "tersedia" tanpa satu pun hasil skrining tercatat, sehingga
 *    statusnya menyatakan sesuatu yang tidak punya dasar.
 *
 * 2. SATU HASIL REAKTIF MENOLAK KANTONGNYA, tanpa jalan memutar.
 *
 * 3. HASIL MERAGUKAN MENAHAN, TIDAK MENOLAK. Pemeriksaan ulang memang
 *    prosedurnya; menolak kantong yang hasilnya cuma meragukan membuang
 *    darah yang mungkin baik, dan merilisnya membahayakan pasien.
 *
 * 4. SKRINING MELEKAT PADA KANTONG, BUKAN PADA DONOR — berbeda dari
 *    serologi pasien dialisis pada domain M item P yang berlaku enam
 *    bulan. Donor yang bersih bulan lalu bisa terinfeksi minggu ini,
 *    dan memakai hasil lama untuk kantong baru persis cara darah
 *    terinfeksi lolos ke pasien.
 *
 * 5. DONOR REAKTIF MEMICU LOOK-BACK DAN PENCEKALAN. Menolak satu
 *    kantong saja membiarkan kantong-kantong sebelumnya dari donor yang
 *    sama tetap beredar — dan itulah kegagalan yang paling mahal
 *    ongkosnya bagi pasien.
 */
class ScreeningService
{
    public function __construct(
        private readonly BloodUnitService $units,
        private readonly DonorService $donors,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * Mencatat hasil skrining satu kantong.
     *
     * @throws BloodException
     */
    public function screen(BloodUnit $unit, array $results, User $actor, array $data = []): UnitScreening
    {
        if (in_array($unit->status, [BloodUnit::STATUS_DIKELUARKAN, BloodUnit::STATUS_DITOLAK], true)) {
            throw new BloodException(
                "Unit {$unit->unit_number} berstatus '{$unit->status}'; skrining hanya dicatat pada unit "
                .'yang masih dalam karantina, ditahan, atau tersedia.'
            );
        }

        $nilai = [];

        foreach (array_keys(UnitScreening::IMLTD) as $pemeriksaan) {
            if (! array_key_exists($pemeriksaan, $results)) {
                throw new BloodException(sprintf(
                    'Hasil %s belum diisi. Kelima pemeriksaan IMLTD wajib pada setiap kantong sebelum '
                    .'boleh dikeluarkan — bukan sebagian.',
                    UnitScreening::IMLTD[$pemeriksaan],
                ));
            }

            $hasil = $results[$pemeriksaan];

            if (! array_key_exists($hasil, UnitScreening::HASIL)) {
                throw new BloodException(
                    "Hasil '{$hasil}' tidak dikenali. Pilihannya: "
                    .implode(', ', array_keys(UnitScreening::HASIL)).'.'
                );
            }

            $nilai[$pemeriksaan] = $hasil;
        }

        $pemeriksa = trim($data['screened_by_name'] ?? $actor->name ?? '');

        if ($pemeriksa === '') {
            throw new BloodException('Nama petugas skrining wajib dicatat.');
        }

        $sudahAda = UnitScreening::query()->where('blood_unit_id', $unit->id)->exists();

        return DB::transaction(function () use ($unit, $nilai, $data, $actor, $pemeriksa, $sudahAda) {
            $skrining = UnitScreening::query()->create($nilai + [
                'blood_unit_id' => $unit->id,
                'method' => $data['method'] ?? null,
                'screened_at' => $data['screened_at'] ?? now(),
                'screened_by' => $actor->id,
                'screened_by_name' => $pemeriksa,
                'is_repeat' => $sudahAda,
                'note' => $data['note'] ?? null,
            ]);

            $this->applyResult($unit, $skrining, $actor);

            return $skrining;
        });
    }

    /**
     * Merilis unit ke status tersedia, HANYA bila skriningnya lengkap
     * dan bersih.
     *
     * Menggantikan pemanggilan langsung BloodUnitService::release() pada
     * jalur normal: unit yang belum diskrining tidak boleh tersedia.
     *
     * @throws BloodException
     */
    public function releaseAfterScreening(BloodUnit $unit, User $actor): BloodUnit
    {
        $skrining = $this->latestScreeningFor($unit);

        if ($skrining === null) {
            throw new BloodException(
                "Unit {$unit->unit_number} belum punya hasil skrining IMLTD. Status \"tersedia\" pada "
                .'unit yang belum diperiksa adalah pernyataan tanpa dasar, dan yang menanggung akibatnya '
                .'adalah pasien yang menerimanya.'
            );
        }

        if ($skrining->hasReactiveResult()) {
            throw new BloodException(sprintf(
                'Unit %s reaktif pada %s dan tidak boleh dirilis.',
                $unit->unit_number,
                implode(', ', $skrining->reactiveTests()),
            ));
        }

        if ($skrining->inconclusiveTests() !== []) {
            throw new BloodException(sprintf(
                'Hasil %s masih meragukan pada unit %s. Periksa ulang lebih dulu — merilisnya '
                .'membahayakan pasien, menolaknya membuang darah yang mungkin baik.',
                implode(', ', $skrining->inconclusiveTests()),
                $unit->unit_number,
            ));
        }

        return $this->units->release($unit, $actor, 'Lolos skrining IMLTD');
    }

    public function latestScreeningFor(BloodUnit $unit): ?UnitScreening
    {
        return UnitScreening::query()
            ->where('blood_unit_id', $unit->id)
            ->orderByDesc('screened_at')
            ->first();
    }

    /** Unit dalam karantina yang belum punya hasil skrining sama sekali. */
    public function awaitingScreening(): Collection
    {
        return BloodUnit::query()
            ->where('status', BloodUnit::STATUS_KARANTINA)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('blood.unit_screenings')
                ->whereColumn('blood.unit_screenings.blood_unit_id', 'blood.blood_units.id'))
            ->orderBy('collected_at')
            ->get();
    }

    // -------------------------------------------------------- look-back

    /**
     * Membuka penelusuran balik atas seorang donor.
     *
     * Menelusuri SELURUH kantong yang pernah berasal darinya, termasuk
     * komponen hasil pemisahan — cucu dari donasi itu — dan menyebutkan
     * pasien yang sudah terlanjur menerimanya.
     *
     * @throws BloodException
     */
    public function openLookback(Donor $donor, string $trigger, string $detail, User $actor): LookbackInvestigation
    {
        if (! array_key_exists($trigger, LookbackInvestigation::PEMICU)) {
            throw new BloodException(
                "Pemicu penelusuran '{$trigger}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(LookbackInvestigation::PEMICU)).'.'
            );
        }

        $uraian = trim($detail);

        if ($uraian === '') {
            throw new BloodException(
                'Uraian pemicu wajib diisi: penelusuran balik adalah tindakan besar, dan alasannya '
                .'harus bisa dibaca kembali bertahun kemudian.'
            );
        }

        $berjalan = LookbackInvestigation::query()
            ->where('donor_id', $donor->id)
            ->where('status', LookbackInvestigation::BERJALAN)
            ->first();

        if ($berjalan !== null) {
            return $berjalan;
        }

        $kantong = $this->traceUnitsFrom($donor);
        $dikeluarkan = $this->issuedAmong($kantong);

        return LookbackInvestigation::query()->create([
            'investigation_number' => $this->numbers->allocate('LB'),
            'donor_id' => $donor->id,
            'triggered_at' => now(),
            'trigger' => $trigger,
            'trigger_detail' => $uraian,
            'units_found' => $kantong->count(),
            'units_already_issued' => $dikeluarkan->count(),
            'unit_numbers' => $kantong->pluck('unit_number')->all(),
            'affected_patients' => $dikeluarkan->map(fn (TransfusionIssue $p) => [
                'patient_id' => $p->patient_id,
                'patient_name' => $p->patient_name,
                'unit_number' => $p->bloodUnit?->unit_number,
                'issued_at' => $p->issued_at?->toIso8601String(),
            ])->values()->all(),
            'status' => LookbackInvestigation::BERJALAN,
            'opened_by' => $actor->id,
            'opened_by_name' => $actor->name,
        ]);
    }

    /**
     * Menarik kantong yang masih ada dari peredaran.
     *
     * @throws BloodException
     */
    public function recallUnits(LookbackInvestigation $investigation, User $actor): LookbackInvestigation
    {
        if (! $investigation->isOpen()) {
            throw new BloodException('Penelusuran ini sudah ditutup.');
        }

        $ditarik = 0;

        foreach ($investigation->unit_numbers ?? [] as $nomor) {
            $unit = BloodUnit::query()->where('unit_number', $nomor)->first();

            if ($unit === null) {
                continue;
            }

            if (! in_array($unit->status, [
                BloodUnit::STATUS_KARANTINA, BloodUnit::STATUS_TERSEDIA, BloodUnit::STATUS_DITAHAN,
            ], true)) {
                continue;
            }

            $alasan = "Ditarik lewat penelusuran balik {$investigation->investigation_number}";

            // Unit yang sudah tersedia ditahan lebih dulu, karena mesin
            // statusnya memang tidak menyediakan jalan langsung dari
            // tersedia ke ditolak — dan itu benar: menarik darah yang
            // sudah dinyatakan siap pakai adalah dua keputusan, bukan
            // satu, dan keduanya perlu jejaknya sendiri.
            if ($unit->status === BloodUnit::STATUS_TERSEDIA) {
                $unit = $this->units->hold($unit, $actor, $alasan);
            }

            $this->units->reject($unit, $actor, $alasan);

            $ditarik++;
        }

        $investigation->update(['units_recalled' => $ditarik]);

        return $investigation->refresh();
    }

    /**
     * @throws BloodException
     */
    public function closeLookback(LookbackInvestigation $investigation, string $conclusion): LookbackInvestigation
    {
        if (! $investigation->isOpen()) {
            throw new BloodException('Penelusuran ini sudah ditutup.');
        }

        $kesimpulan = trim($conclusion);

        if ($kesimpulan === '') {
            throw new BloodException(
                'Kesimpulan wajib diisi. Penarikan darah yang berhenti tanpa kesimpulan tidak bisa '
                .'dibuktikan pernah dituntaskan.'
            );
        }

        $investigation->update([
            'status' => LookbackInvestigation::SELESAI,
            'closed_at' => now(),
            'conclusion' => $kesimpulan,
        ]);

        return $investigation->refresh();
    }

    /**
     * Seluruh kantong yang berasal dari seorang donor, termasuk komponen
     * hasil pemisahan.
     *
     * Penelusurannya berjenjang: unit anak menunjuk induknya lewat
     * parent_unit_id, dan induknya menunjuk donornya.
     */
    public function traceUnitsFrom(Donor $donor): Collection
    {
        $langsung = BloodUnit::query()->where('donor_id', $donor->id)->get();
        $seluruh = $langsung->keyBy('id');
        $induk = $langsung->pluck('id')->all();

        // Berjenjang, bukan satu tingkat: komponen bisa dipisah lagi.
        while ($induk !== []) {
            $anak = BloodUnit::query()->whereIn('parent_unit_id', $induk)->get();
            $induk = [];

            foreach ($anak as $unit) {
                if (! $seluruh->has($unit->id)) {
                    $seluruh->put($unit->id, $unit);
                    $induk[] = $unit->id;
                }
            }
        }

        return $seluruh->values();
    }

    /**
     * @param  Collection<int, BloodUnit>  $units
     * @return Collection<int, TransfusionIssue>
     */
    private function issuedAmong(Collection $units): Collection
    {
        if ($units->isEmpty()) {
            return collect();
        }

        return TransfusionIssue::query()
            ->whereIn('blood_unit_id', $units->pluck('id')->all())
            ->with('bloodUnit')
            ->get();
    }

    /**
     * Hasil skrining menentukan nasib kantongnya — dan bila reaktif,
     * juga nasib donornya.
     *
     * @throws BloodException
     */
    private function applyResult(BloodUnit $unit, UnitScreening $screening, User $actor): void
    {
        if ($screening->hasReactiveResult()) {
            $reaktif = implode(', ', $screening->reactiveTests());

            if ($unit->status !== BloodUnit::STATUS_DITOLAK) {
                $this->units->reject($unit, $actor, "Skrining reaktif: {$reaktif}");
            }

            // Menolak satu kantong saja membiarkan kantong sebelumnya
            // dari donor yang sama tetap beredar.
            if ($unit->donor_id !== null) {
                $donor = Donor::query()->find($unit->donor_id);

                if ($donor !== null) {
                    $this->donors->block($donor, "Skrining IMLTD reaktif: {$reaktif}", null);
                    $this->openLookback(
                        $donor,
                        'skrining-reaktif',
                        "Unit {$unit->unit_number} reaktif pada {$reaktif}.",
                        $actor,
                    );
                }
            }

            return;
        }

        if ($screening->inconclusiveTests() !== [] && $unit->status === BloodUnit::STATUS_TERSEDIA) {
            $this->units->hold(
                $unit,
                $actor,
                'Skrining meragukan: '.implode(', ', $screening->inconclusiveTests())
            );
        }
    }
}
