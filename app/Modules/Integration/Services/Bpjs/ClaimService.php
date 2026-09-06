<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Models\ClaimMonitoring;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Klaim INA-CBG & monitoring klaim (domain L item C) — 10 kode.
 *
 *   assemble / submit -> inacbg_klaim_baru_manual, ..._manual2,
 *                        ..._otomatis, bridging_smart_klaim_bpjs
 *   monitor           -> bpjs_monitoring_klaim, ..._apotek
 *
 * EMPAT ATURAN YANG MENJAGA ANGKANYA TETAP BENAR:
 *
 * 1. KODE CBG DAN TARIFNYA DITERIMA, TIDAK PERNAH DIHITUNG. Grouper
 *    INA-CBG milik Kemenkes; menghitung sendiri berarti mengarang tarif
 *    yang akan dibayarkan negara.
 *
 * 2. ISI KLAIM DIBEKUKAN SAAT DIKIRIM. Diagnosis dan tindakan disalin ke
 *    baris klaimnya, bukan dibaca ulang dari rekam medis — rekam medis
 *    boleh dikoreksi setelah klaim terkirim, dan klaim yang sudah
 *    diverifikasi tidak boleh ikut berubah.
 *
 * 3. SATU KLAIM AKTIF PER KUNJUNGAN. Dua klaim atas kunjungan yang sama
 *    akan dibayar dua kali atau ditolak dua-duanya.
 *
 * 4. MONITORING TIDAK MENGUBAH STATUS KLAIM KITA. Jawaban BPJS disimpan
 *    sebagai salinan; status di sini tetap status PENGIRIMAN kita, supaya
 *    "belum kami kirim" tetap bisa dibedakan dari "sudah dikirim tapi
 *    belum diverifikasi BPJS".
 */
class ClaimService
{
    private const REGISTRASI = 'encounter.v_registration_summary';
    private const DIAGNOSIS = 'clinical.v_encounter_diagnosis';
    private const TINDAKAN = 'clinical.v_procedure_charge';
    private const BIAYA = 'billing.v_charge_detail';

    public function __construct(private readonly ClaimClient $client) {}

    // -------------------------------------------------------------- penyusunan

    /**
     * Menyusun klaim dari data kunjungan yang sudah ada.
     *
     * Belum dikirim — isinya masih boleh berubah selama berstatus draf.
     *
     * @throws IntegrationException
     */
    public function assemble(int $registrationId, string $type = Claim::INACBG, ?int $actorId = null): Claim
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new IntegrationException("Kunjungan #{$registrationId} tidak ditemukan atau sudah dibatalkan.");

        $aktif = Claim::query()
            ->where('registration_id', $registrationId)
            ->where('claim_type', $type)
            ->where('status', '<>', Claim::BATAL)
            ->first();

        if ($aktif) {
            throw new IntegrationException(
                "Kunjungan {$kunjungan->registration_number} sudah punya klaim {$type} aktif ({$aktif->claim_number})."
            );
        }

        $sep = DB::table('integration.bpjs_sep')
            ->where('registration_id', $registrationId)
            ->where('status', 'terbit')
            ->first();

        return Claim::query()->create([
            'claim_number' => $this->allocateNumber(),
            'claim_type' => $type,
            'registration_id' => $registrationId,
            'registration_number' => $kunjungan->registration_number,
            'patient_id' => $kunjungan->patient_id,
            'patient_name' => $kunjungan->patient_name,
            'card_number' => $sep->no_kartu ?? null,
            'sep_number' => $sep->sep_number ?? null,
            'care_type' => $kunjungan->care_type,
            'admitted_on' => $kunjungan->service_date,
            'discharged_on' => null,
            'hospital_charge' => $this->hospitalCharge($registrationId),
            'status' => Claim::DRAF,
        ]);
    }

    /**
     * Mengirim klaim ke grouper, lalu menyimpan kode CBG yang DITERIMA.
     *
     * @throws IntegrationException
     */
    public function submit(Claim $claim, ?int $actorId = null): Claim
    {
        if ($claim->status !== Claim::DRAF && $claim->status !== Claim::DIKEMBALIKAN) {
            throw new IntegrationException(
                "Klaim berstatus '{$claim->status}' tidak bisa dikirim; hanya draf atau yang dikembalikan."
            );
        }

        if ($claim->sep_number === null && $claim->claim_type !== Claim::JASA_RAHARJA) {
            throw new IntegrationException('Klaim BPJS harus punya SEP sebelum dikirim ke grouper.');
        }

        // Isi dibekukan DI SINI, bukan dibaca ulang saat dibutuhkan nanti.
        $diagnosis = $this->diagnoses($claim->registration_id);
        $tindakan = $this->procedures($claim->registration_id);

        if ($diagnosis === []) {
            throw new IntegrationException('Klaim tanpa diagnosis akan ditolak grouper; lengkapi diagnosisnya dulu.');
        }

        $jawab = $this->client->group([
            'nomor_klaim' => $claim->claim_number,
            'no_sep' => $claim->sep_number,
            'jenis_rawat' => $claim->care_type,
            'tanggal_masuk' => $claim->admitted_on?->toDateString(),
            'diagnosa' => array_column($diagnosis, 'code'),
            'prosedur' => array_column($tindakan, 'code'),
            'tarif_rs' => (float) $claim->hospital_charge,
        ]);

        $data = $jawab['data'] ?? [];
        $berhasil = (bool) ($jawab['success'] ?? false);

        $claim->update([
            'diagnoses' => $diagnosis,
            'procedures' => $tindakan,
            // Kode dan tarif SELALU dari jawaban grouper.
            'cbg_code' => $data['kode_cbg'] ?? null,
            'cbg_description' => $data['deskripsi_cbg'] ?? null,
            'cbg_tariff' => $data['tarif_cbg'] ?? null,
            'status' => $berhasil ? Claim::TERKIRIM : Claim::DIKEMBALIKAN,
            'submitted_at' => now(),
            'submitted_by' => $actorId,
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'grouper_response' => $jawab,
        ]);

        return $claim->refresh();
    }

    /**
     * @throws IntegrationException
     */
    public function cancel(Claim $claim, string $reason): Claim
    {
        if ($claim->status === Claim::TERVERIFIKASI) {
            throw new IntegrationException('Klaim yang sudah diverifikasi BPJS tidak bisa dibatalkan dari sini.');
        }

        if ($claim->status === Claim::BATAL) {
            throw new IntegrationException('Klaim ini sudah dibatalkan.');
        }

        $claim->update([
            'status' => Claim::BATAL,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $claim->refresh();
    }

    // -------------------------------------------------------------- monitoring

    /**
     * Menanyakan status verifikasi klaim ke BPJS.
     *
     * TIDAK mengubah status klaim kita — jawabannya disimpan sebagai
     * salinan. Lihat aturan 4 pada docblock kelas.
     */
    public function monitor(string $scope, string $from, string $until, ?int $actorId = null): ClaimMonitoring
    {
        if (! in_array($scope, ['rs', 'apotek'], true)) {
            throw new IntegrationException("Lingkup monitoring '{$scope}' tidak dikenal.");
        }

        $jawab = $this->client->monitorClaims($scope, $from, $until);
        $data = $jawab['data'] ?? [];
        $daftar = $data['klaim'] ?? [];

        return ClaimMonitoring::query()->create([
            'scope' => $scope,
            'period_from' => $from,
            'period_until' => $until,
            'success' => (bool) ($jawab['success'] ?? false),
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'raw_response' => $jawab,
            'claim_count' => count($daftar),
            'total_tariff' => collect($daftar)->sum('tarif'),
            'checked_by' => $actorId,
        ]);
    }

    // ---------------------------------------------------------------- laporan

    public function claims(?string $status = null, ?string $type = null): Collection
    {
        return Claim::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('claim_type', $type))
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * Selisih antara biaya rumah sakit dan tarif CBG.
     *
     * Angka inilah yang menentukan klaim menguntungkan atau merugikan, dan
     * yang paling sering tidak terlihat: tarif CBG dibayar tetap, sedangkan
     * biaya rumah sakit berbeda tiap pasien. Ditampilkan per klaim, bukan
     * cuma totalnya, karena rata-rata yang sehat bisa menyembunyikan
     * beberapa kasus yang rugi besar.
     */
    public function marginSummary(string $from, string $until): object
    {
        $row = DB::table('integration.claims')
            ->whereBetween('admitted_on', [$from, $until])
            ->whereNotNull('cbg_tariff')
            ->where('status', '<>', Claim::BATAL)
            ->selectRaw('count(*) AS klaim,
                         coalesce(sum(hospital_charge), 0) AS biaya_rs,
                         coalesce(sum(cbg_tariff), 0) AS tarif_cbg,
                         count(*) FILTER (WHERE cbg_tariff < hospital_charge) AS merugi')
            ->first();

        $biaya = (float) ($row->biaya_rs ?? 0);
        $tarif = (float) ($row->tarif_cbg ?? 0);

        return (object) [
            'klaim' => (int) ($row->klaim ?? 0),
            'biaya_rs' => $biaya,
            'tarif_cbg' => $tarif,
            'selisih' => round($tarif - $biaya, 2),
            'merugi' => (int) ($row->merugi ?? 0),
        ];
    }

    public function monitorings(int $limit = 50): Collection
    {
        return ClaimMonitoring::query()->orderByDesc('id')->limit($limit)->get();
    }

    // ------------------------------------------------------------------ bantu

    /** @return array<int, array{code: string, display: string|null}> */
    private function diagnoses(int $registrationId): array
    {
        return DB::table(self::DIAGNOSIS)
            ->where('registration_id', $registrationId)
            ->orderByRaw("CASE WHEN rank = 'utama' THEN 0 ELSE 1 END")
            ->get(['code', 'display'])
            ->map(fn ($d) => ['code' => $d->code, 'display' => $d->display])
            ->all();
    }

    /** @return array<int, array{code: string, display: string|null}> */
    private function procedures(int $registrationId): array
    {
        return DB::table(self::TINDAKAN)
            ->where('registration_id', $registrationId)
            ->get()
            ->map(fn ($t) => ['code' => $t->service_code ?? null, 'display' => $t->service_name ?? null])
            ->filter(fn ($t) => $t['code'] !== null)
            ->values()
            ->all();
    }

    private function hospitalCharge(int $registrationId): float
    {
        return (float) DB::table(self::BIAYA)
            ->where('registration_id', $registrationId)
            ->sum('amount');
    }

    private function allocateNumber(): string
    {
        $key = 'KLM' . now()->format('Ym');

        $row = DB::selectOne(
            'INSERT INTO integration.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = integration.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
