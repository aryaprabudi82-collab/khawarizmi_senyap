<?php

namespace App\Modules\Envlab\Services;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\QualityStandard;
use App\Modules\Envlab\Models\SampleTest;
use App\Modules\Envlab\Models\SampleTestItem;
use App\Modules\Envlab\Models\SampleType;
use App\Modules\Envlab\Models\TestParameter;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk alur transaksi envlab — lihat catatan migrasi
 * envlab.sample_tests untuk pemetaan lengkap access-flag ke peran.
 */
class SampleTestService
{
    /**
     * permintaan_pengujian_sampel_lab_kesehatan_lingkungan — loket.
     *
     * @param  list<int>  $parameterIds
     * @throws EnvlabException
     */
    public function request(
        int $customerId,
        int $sampleTypeId,
        array $parameterIds,
        ?string $sampleDescription,
        ?User $actor,
    ): SampleTest {
        $pelanggan = Customer::query()->find($customerId)
            ?? throw new EnvlabException('Pelanggan tidak ditemukan.');

        $jenisSampel = SampleType::query()->find($sampleTypeId)
            ?? throw new EnvlabException('Jenis sampel tidak ditemukan.');

        if ($parameterIds === []) {
            throw new EnvlabException('Pilih minimal satu parameter pengujian.');
        }

        $parameters = TestParameter::query()->whereIn('id', $parameterIds)->where('is_active', true)->get();

        if ($parameters->count() !== count(array_unique($parameterIds))) {
            throw new EnvlabException('Ada parameter yang tidak ditemukan atau sudah tidak aktif.');
        }

        return DB::transaction(function () use ($pelanggan, $jenisSampel, $parameters, $sampleDescription, $actor): SampleTest {
            $permintaan = SampleTest::query()->create([
                'request_number' => $this->allocateNumber(),
                'customer_id' => $pelanggan->id,
                'customer_name' => $pelanggan->name,
                'sample_type_id' => $jenisSampel->id,
                'sample_type_name' => $jenisSampel->name,
                'sample_description' => $sampleDescription,
                'status' => SampleTest::STATUS_DIMINTA,
                'requested_by' => $actor?->id,
                'requested_by_name' => $actor?->name,
                'requested_at' => now(),
            ]);

            foreach ($parameters as $parameter) {
                $bakuMutu = QualityStandard::query()
                    ->where('sample_type_id', $jenisSampel->id)
                    ->where('parameter_id', $parameter->id)
                    ->where('is_active', true)
                    ->first();

                $permintaan->items()->create([
                    'parameter_id' => $parameter->id,
                    'parameter_name' => $parameter->name,
                    'unit' => $parameter->unit,
                    'standard_min' => $bakuMutu?->min_value,
                    'standard_max' => $bakuMutu?->max_value,
                    'standard_qualitative' => $bakuMutu?->qualitative_standard,
                ]);
            }

            return $permintaan;
        });
    }

    /**
     * permintaan_pengujian_sampel_lab_kesehatan_lingkungan — loket menolak
     * sampel yang tidak layak diuji, satu kode dengan request().
     *
     * @throws EnvlabException
     */
    public function reject(SampleTest $permintaan, string $reason): SampleTest
    {
        $this->assertStatus($permintaan, SampleTest::STATUS_DIMINTA);

        $permintaan->update(['status' => SampleTest::STATUS_DITOLAK, 'rejection_reason' => $reason]);

        return $permintaan->refresh();
    }

    /**
     * penugasan_pengujian_sampel_lab_kesehatan_lingkungan — penyelia
     * menerima sampel sekaligus menugaskan ke analis.
     *
     * @throws EnvlabException
     */
    public function accept(SampleTest $permintaan, string $assignedToName, ?User $actor): SampleTest
    {
        $this->assertStatus($permintaan, SampleTest::STATUS_DIMINTA);

        $permintaan->update([
            'status' => SampleTest::STATUS_DIPROSES,
            'assigned_to' => $actor?->id,
            'assigned_to_name' => $assignedToName,
            'assigned_at' => now(),
        ]);

        return $permintaan->refresh();
    }

    /**
     * hasil_pengujian_sampel_lab_kesehatan_lingkungan — analis entri hasil.
     * Abnormalitas dihitung terhadap baku mutu yang disalin saat permintaan
     * dibuat, pola sama dengan order.OrderService::enterResult().
     *
     * @throws EnvlabException
     */
    public function enterResult(
        SampleTestItem $item,
        ?float $value,
        ?string $text,
        ?User $actor,
    ): SampleTestItem {
        $permintaan = $item->sampleTest;

        if ($permintaan->status !== SampleTest::STATUS_DIPROSES) {
            throw new EnvlabException('Hasil hanya bisa dicatat untuk sampel berstatus diproses.');
        }

        $melebihi = $this->isExceeded($item, $value, $text);

        return DB::transaction(function () use ($item, $value, $text, $melebihi, $actor, $permintaan): SampleTestItem {
            $item->update([
                'result_value' => $value,
                'result_text' => $text,
                'is_exceeded' => $melebihi,
                'entered_by' => $actor?->id,
                'entered_by_name' => $actor?->name,
                'entered_at' => now(),
            ]);

            $semuaTerisi = $permintaan->items()->get()->every(fn (SampleTestItem $i) => $i->fresh()->hasResult());

            if ($semuaTerisi) {
                $permintaan->update(['status' => SampleTest::STATUS_HASIL_TERSEDIA]);
            }

            return $item->refresh();
        });
    }

    /**
     * verifikasi_pengujian_sampel_lab_kesehatan_lingkungan — penyelia.
     *
     * @throws EnvlabException
     */
    public function verify(SampleTest $permintaan, ?User $actor): SampleTest
    {
        $this->assertStatus($permintaan, SampleTest::STATUS_HASIL_TERSEDIA);

        $permintaan->update([
            'status' => SampleTest::STATUS_TERVERIFIKASI,
            'verified_by' => $actor?->id,
            'verified_by_name' => $actor?->name,
            'verified_at' => now(),
        ]);

        return $permintaan->refresh();
    }

    /**
     * validasi_pengujian_sampel_lab_kesehatan_lingkungan — penyelia, sign-off akhir.
     *
     * @throws EnvlabException
     */
    public function validateResult(SampleTest $permintaan, ?User $actor): SampleTest
    {
        $this->assertStatus($permintaan, SampleTest::STATUS_TERVERIFIKASI);

        $permintaan->update([
            'status' => SampleTest::STATUS_SELESAI,
            'validated_by' => $actor?->id,
            'validated_by_name' => $actor?->name,
            'validated_at' => now(),
        ]);

        return $permintaan->refresh();
    }

    /**
     * pembayaran_pengujian_sampel_lab_kesehatan_lingkungan — dicatat sendiri,
     * TIDAK lewat billing/finance (pelanggan lab kesling belum tentu pasien).
     *
     * @throws EnvlabException
     */
    public function markPaid(SampleTest $permintaan, float $amount): SampleTest
    {
        if ($permintaan->payment_status === SampleTest::PAYMENT_LUNAS) {
            throw new EnvlabException('Pengujian ini sudah lunas.');
        }

        $permintaan->update([
            'price' => $amount,
            'payment_status' => SampleTest::PAYMENT_LUNAS,
            'paid_at' => now(),
        ]);

        return $permintaan->refresh();
    }

    private function assertStatus(SampleTest $permintaan, string $expected): void
    {
        if ($permintaan->status !== $expected) {
            throw new EnvlabException(
                'Status pengujian saat ini (' . SampleTest::statusLabel($permintaan->status) . ') tidak mengizinkan aksi ini.'
            );
        }
    }

    private function isExceeded(SampleTestItem $item, ?float $value, ?string $text): bool
    {
        if ($item->standard_qualitative !== null) {
            return $text !== null && mb_strtolower(trim($text)) !== mb_strtolower(trim($item->standard_qualitative));
        }

        if ($value === null) {
            return false;
        }

        if ($item->standard_max !== null && $value > (float) $item->standard_max) {
            return true;
        }

        if ($item->standard_min !== null && $value < (float) $item->standard_min) {
            return true;
        }

        return false;
    }

    private function allocateNumber(): string
    {
        $prefix = 'ENV-' . now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO envlab.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = envlab.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return $prefix . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
