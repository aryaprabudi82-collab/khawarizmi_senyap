<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsSep;
use App\Modules\Integration\Services\DiagnosisContext;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\PayerContext;
use App\Modules\Integration\Services\RegistrationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Siklus SEP: satu kunjungan hanya boleh punya satu SEP aktif (dijamin
 * partial unique index bpjs_sep_registration_active_unique di migrasi,
 * bukan cuma pengecekan di sini — supaya aman juga dari dua permintaan
 * bersamaan).
 *
 * PERINGATAN: daftar field payload SEP/2.0/insert di bawah mengikuti subset
 * yang paling umum didokumentasikan VClaim. Field lengkap (cob, katarak,
 * jaminan laka-lantas, skdp, dst.) perlu dicocokkan terhadap dokumentasi
 * versi API yang berlaku begitu kredensial faskes diterbitkan.
 */
class SepService
{
    public function __construct(
        private readonly BpjsClient $client,
        private readonly RegistrationContext $registrations,
        private readonly PayerContext $payers,
        private readonly DiagnosisContext $diagnoses,
        private readonly IdentityMappingService $mappings,
    ) {}

    public function create(int $registrationId, string $noKartu, ?string $noRujukan, int $requestedBy): BpjsSep
    {
        $registration = $this->registrations->find($registrationId);

        if ($registration === null) {
            throw new RuntimeException("Registrasi #{$registrationId} tidak ditemukan.");
        }

        $payer = $this->payers->find((int) $registration->payer_id);

        if ($payer === null || $payer->kind !== 'bpjs') {
            throw new RuntimeException('Kunjungan ini bukan penjamin BPJS, SEP tidak berlaku.');
        }

        if (BpjsSep::query()->where('registration_id', $registrationId)->where(fn ($q) => $q->whereIn('status', [BpjsSep::STATUS_DIAJUKAN, BpjsSep::STATUS_TERBIT]))->exists()) {
            throw new RuntimeException('Kunjungan ini sudah punya SEP yang masih aktif.');
        }

        $poliCode = $this->mappings->externalIdFor('bpjs', 'poli', 'organization', (int) $registration->unit_id);

        if ($poliCode === null) {
            throw new RuntimeException("Unit '{$registration->unit_name}' belum dipetakan ke kode poli BPJS. Petakan lewat layar admin integrasi terlebih dahulu.");
        }

        $jenisPelayanan = $registration->care_type === 'ranap' ? BpjsSep::JENIS_RANAP : BpjsSep::JENIS_RALAN;

        if ($jenisPelayanan === BpjsSep::JENIS_RALAN && blank($noRujukan)) {
            throw new RuntimeException('No. Rujukan wajib diisi untuk pelayanan rawat jalan.');
        }

        $diagnosis = $this->diagnoses->primaryFor($registrationId);

        $payload = [
            'noKartu' => $noKartu,
            'tglSep' => now()->toDateString(),
            'ppkPelayanan' => (string) config('services.bpjs.ppk_code'),
            'jnsPelayanan' => $jenisPelayanan,
            'klsRawat' => '3',
            'noMR' => $registration->patient_mrn,
            'nmPasien' => $registration->patient_name,
            'assesmentPel' => '1',
            'noRujukan' => $noRujukan,
            'poliTujuan' => $poliCode,
            'diagAwal' => $diagnosis->code ?? null,
            'catatan' => null,
            'tujuanKunj' => '0',
            'flagProcedure' => '',
            'kdPenunjang' => '',
            'noHP' => null,
            'user' => 'petugas-' . $requestedBy,
        ];

        return DB::transaction(function () use ($registration, $registrationId, $noKartu, $noRujukan, $poliCode, $jenisPelayanan, $diagnosis, $payload, $requestedBy) {
            $result = $this->client->createSep($payload);

            return BpjsSep::query()->create([
                'registration_id' => $registrationId,
                'registration_number' => $registration->registration_number,
                'no_kartu' => $noKartu,
                'no_rujukan' => $noRujukan,
                'sep_number' => $result['success'] ? ($result['data']['noSep'] ?? null) : null,
                'poli_tujuan' => $poliCode,
                'jenis_pelayanan' => $jenisPelayanan,
                'diagnosa_awal' => $diagnosis->code ?? null,
                'status' => $result['success'] ? BpjsSep::STATUS_TERBIT : BpjsSep::STATUS_GAGAL,
                'request_payload' => $payload,
                'response_payload' => $result,
                'error_message' => $result['success'] ? null : $result['message'],
                'requested_by' => $requestedBy,
                'requested_at' => now(),
                'issued_at' => $result['success'] ? now() : null,
            ]);
        });
    }

    public function cancel(BpjsSep $sep, string $reason): BpjsSep
    {
        if (! $sep->isActive()) {
            throw new RuntimeException('SEP ini sudah tidak aktif.');
        }

        $result = $this->client->cancelSep((string) $sep->sep_number, $reason);

        if (! $result['success']) {
            throw new RuntimeException('Gagal membatalkan SEP: ' . $result['message']);
        }

        $sep->update([
            'status' => BpjsSep::STATUS_BATAL,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $sep->refresh();
    }
}
