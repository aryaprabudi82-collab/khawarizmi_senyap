<?php

namespace App\Modules\Integration\Services\Inhealth;

use App\Modules\Integration\Models\InhealthEligibilityCheck;
use App\Modules\Integration\Models\InhealthGuarantee;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\PayerReferenceService;
use App\Modules\Integration\Services\RegistrationContext;
use Illuminate\Support\Collection;

/**
 * Mandiri Inhealth (domain L item Q) — 3 kode transaksional:
 *
 *   checkEligibility -> inhealth_cek_eligibilitas
 *   issueGuarantee   -> inhealth_sjp
 *   submitBilling    -> inhealth_kirim_tagihan
 *
 * Tujuh kode pemetaan dan tiga kode referensi memakai mekanisme pemetaan
 * dan referensi kode penjamin dari item E.
 *
 * SJP ADALAH PADANAN SEP. Aturannya karena itu dipinjam utuh dari sisi
 * BPJS, bukan dikarang ulang:
 *
 * 1. SATU KUNJUNGAN, SATU SJP BERLAKU. Dua surat jaminan atas satu
 *    kunjungan ditagihkan dua kali, dan salah satunya pasti ditolak.
 * 2. NOMOR SJP DARI INHEALTH, bukan dinomori sendiri.
 * 3. KEGAGALAN IKUT DICATAT — "kenapa SJP tidak terbit" hanya bisa
 *    dijawab kalau percobaan yang gagal meninggalkan jejak.
 * 4. PEMBATALAN YANG DITOLAK TIDAK MENGUBAH STATUS LOKAL.
 *
 * ELIGIBILITAS GAGAL BUKAN "TIDAK ELIGIBLE". Kalau panggilannya gagal,
 * jawabannya null — dan null itu tidak boleh diperlakukan sebagai
 * penolakan, karena pasien yang sebenarnya berhak akan diminta membayar
 * sendiri.
 *
 * TAGIHAN HANYA ATAS SJP YANG TERBIT, dan rinciannya DIBEKUKAN saat
 * diajukan: menagih atas surat jaminan yang tidak pernah terbit adalah
 * menagih tanpa dasar, dan rincian yang dibaca ulang membuat tagihan yang
 * sudah diverifikasi berubah tanpa ada yang menyentuhnya.
 */
class InhealthService
{
    public const SISTEM = 'inhealth';

    /** Jenis referensi Inhealth yang bisa disegarkan. */
    public const REFERENSI = ['poli', 'faskes', 'ruang-rawat'];

    public function __construct(
        private readonly InhealthClient $client,
        private readonly RegistrationContext $registrations,
        private readonly PayerReferenceService $referensi,
    ) {}

    // ----------------------------------------------------------- eligibilitas

    /**
     * @throws IntegrationException
     */
    public function checkEligibility(string $memberNumber, ?string $serviceDate = null, ?int $actorId = null): InhealthEligibilityCheck
    {
        $memberNumber = trim($memberNumber);

        if ($memberNumber === '') {
            throw new IntegrationException('Nomor peserta Inhealth wajib diisi.');
        }

        $tanggal = $serviceDate ?? now()->toDateString();
        $hasil = $this->client->checkEligibility($memberNumber, $tanggal);
        $berhasil = (bool) ($hasil['success'] ?? false);
        $data = $hasil['data'] ?? [];

        return InhealthEligibilityCheck::query()->create([
            'member_number' => $memberNumber,
            'service_date' => $tanggal,
            // GAGAL berarti null, bukan false — lihat catatan kelas.
            'is_eligible' => $berhasil ? (bool) ($data['eligible'] ?? false) : null,
            'member_name' => $data['nama'] ?? null,
            'plan_name' => $data['plan'] ?? null,
            'response_payload' => $hasil,
            'error_message' => $berhasil ? null : ($hasil['message'] ?? 'Panggilan Inhealth gagal.'),
            'checked_by' => $actorId,
            'checked_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------- SJP

    /**
     * @throws IntegrationException
     */
    public function issueGuarantee(int $registrationId, string $memberNumber, array $data = [], ?int $actorId = null): InhealthGuarantee
    {
        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new IntegrationException("Kunjungan #{$registrationId} tidak ditemukan atau sudah dibatalkan.");

        $aktif = InhealthGuarantee::query()
            ->where('registration_id', $registrationId)
            ->whereIn('status', InhealthGuarantee::AKTIF)
            ->exists();

        if ($aktif) {
            throw new IntegrationException(
                "Kunjungan {$kunjungan->registration_number} sudah punya SJP yang berlaku. "
                . 'Dua surat jaminan atas satu kunjungan akan ditagihkan dua kali.'
            );
        }

        $poli = $data['poli_code'] ?? null;

        if (blank($poli)) {
            throw new IntegrationException(
                'Kode poli tujuan versi Inhealth wajib ditentukan. Petakan dulu poli ini lewat pemetaan kode penjamin.'
            );
        }

        $muatan = [
            'noPeserta' => $memberNumber,
            'tglPelayanan' => $kunjungan->service_date,
            'kodePoli' => $poli,
            'jenisPelayanan' => $data['service_type'] ?? 'ralan',
            'diagnosaAwal' => $data['diagnosis_code'] ?? null,
        ];

        $hasil = $this->client->createGuarantee($muatan);
        $berhasil = (bool) ($hasil['success'] ?? false);

        return InhealthGuarantee::query()->create([
            'registration_id' => $registrationId,
            'registration_number' => $kunjungan->registration_number,
            'member_number' => $memberNumber,
            'patient_name' => $kunjungan->patient_name,
            'sjp_number' => $hasil['data']['noSJP'] ?? null,
            'service_type' => $data['service_type'] ?? 'ralan',
            'poli_code' => $poli,
            'diagnosis_code' => $data['diagnosis_code'] ?? null,
            'status' => $berhasil ? InhealthGuarantee::TERBIT : InhealthGuarantee::GAGAL,
            'request_payload' => $muatan,
            'response_payload' => $hasil,
            'error_message' => $berhasil ? null : ($hasil['message'] ?? null),
            'issued_at' => $berhasil ? now() : null,
            'requested_by' => $actorId,
            'requested_at' => now(),
        ]);
    }

    /**
     * @throws IntegrationException
     */
    public function cancelGuarantee(InhealthGuarantee $sjp, string $reason): InhealthGuarantee
    {
        if ($sjp->status !== InhealthGuarantee::TERBIT) {
            throw new IntegrationException("Hanya SJP yang terbit yang bisa dibatalkan; status sekarang '{$sjp->status}'.");
        }

        if ($sjp->isBilled()) {
            throw new IntegrationException(
                'SJP yang tagihannya sudah diajukan tidak bisa dibatalkan di sini; tarik dulu tagihannya.'
            );
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new IntegrationException('Alasan pembatalan wajib diisi.');
        }

        $hasil = $this->client->cancelGuarantee((string) $sjp->sjp_number, $alasan);

        // Pembatalan yang DITOLAK tidak mengubah status lokal — kalau
        // diubah sepihak, kita mengira jaminan batal sementara Inhealth
        // masih menganggapnya berlaku.
        if (! ($hasil['success'] ?? false)) {
            $sjp->update([
                'response_payload' => $hasil,
                'error_message' => $hasil['message'] ?? null,
            ]);

            return $sjp->refresh();
        }

        $sjp->update([
            'status' => InhealthGuarantee::BATAL,
            'cancelled_at' => now(),
            'cancel_reason' => $alasan,
            'response_payload' => $hasil,
        ]);

        return $sjp->refresh();
    }

    // ---------------------------------------------------------------- tagihan

    /**
     * Mengajukan tagihan atas satu SJP.
     *
     * @throws IntegrationException
     */
    public function submitBilling(InhealthGuarantee $sjp, array $items, ?float $amount = null): InhealthGuarantee
    {
        if ($sjp->status !== InhealthGuarantee::TERBIT) {
            throw new IntegrationException(
                'Tagihan hanya bisa diajukan atas SJP yang terbit: menagih atas surat jaminan yang tidak pernah terbit adalah menagih tanpa dasar.'
            );
        }

        if ($sjp->billing_status === InhealthGuarantee::TAGIHAN_DITERIMA) {
            throw new IntegrationException('Tagihan atas SJP ini sudah diterima Inhealth.');
        }

        if ($items === []) {
            throw new IntegrationException('Rincian tagihan wajib diisi.');
        }

        $nilai = $amount ?? $this->jumlahkan($items);

        $hasil = $this->client->submitBilling([
            'noSJP' => $sjp->sjp_number,
            'noPeserta' => $sjp->member_number,
            'total' => $nilai,
            'rincian' => $items,
        ]);

        $berhasil = (bool) ($hasil['success'] ?? false);

        $sjp->update([
            'billing_status' => $berhasil
                ? InhealthGuarantee::TAGIHAN_DIAJUKAN
                : InhealthGuarantee::TAGIHAN_DITOLAK,
            'billed_amount' => $nilai,
            'billed_at' => now(),
            // Dibekukan saat diajukan — bukan dibaca ulang belakangan.
            'billing_items' => $items,
            'billing_response' => $hasil,
            'billing_message' => $hasil['message'] ?? null,
        ]);

        return $sjp->refresh();
    }

    // ---------------------------------------------------------------- laporan

    public function guarantees(?string $status = null, int $limit = 200): Collection
    {
        return InhealthGuarantee::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * SJP terbit yang tagihannya belum pernah diajukan.
     *
     * Inilah daftar pendapatan yang sudah dilayani tapi belum ditagihkan —
     * yang paling mudah terlupakan dan paling langsung mengurangi kas.
     */
    public function unbilled(): Collection
    {
        return InhealthGuarantee::query()
            ->where('status', InhealthGuarantee::TERBIT)
            ->whereNull('billing_status')
            ->orderBy('issued_at')
            ->get();
    }

    /**
     * @throws IntegrationException
     */
    public function refreshReferences(string $type): int
    {
        if (! in_array($type, self::REFERENSI, true)) {
            throw new IntegrationException("Jenis referensi Inhealth '{$type}' tidak dikenal.");
        }

        $hasil = $this->client->references($type);

        if (! ($hasil['success'] ?? false)) {
            throw new IntegrationException(
                "Gagal mengambil referensi {$type} dari Inhealth: " . ($hasil['message'] ?? 'tidak ada pesan.')
            );
        }

        $baris = array_map(
            fn (array $r) => ['code' => $r['kode'] ?? '', 'name' => $r['nama'] ?? '', 'raw' => $r],
            $hasil['data']['daftar'] ?? []
        );

        return $this->referensi->refresh(self::SISTEM, $type, $baris);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function jumlahkan(array $items): float
    {
        return round(array_sum(array_map(
            fn ($item) => (float) ($item['subtotal'] ?? 0),
            $items
        )), 2);
    }
}
