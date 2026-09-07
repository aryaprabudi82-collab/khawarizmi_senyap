<?php

namespace App\Modules\Integration\Services\Sisrute;

use App\Modules\Integration\Models\SisruteReferral;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\PayerReferenceService;
use App\Modules\Integration\Services\ReferralContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rujukan Sisrute (domain L item P) — 5 kode:
 *
 *   sendOutgoing / cancelOutgoing -> sisrute_rujukan_keluar
 *   fetchIncoming / respond       -> sisrute_rujukan_masuk
 *   refreshReferences             -> sisrute_referensi_alasanrujuk,
 *                                    sisrute_referensi_diagnosa,
 *                                    sisrute_referensi_faskes
 *
 * RUJUKAN KELUAR TIDAK DISALIN. Isinya sudah tersimpan di konteks
 * encounter; yang dicatat di sini pengirimannya dan jawaban rumah sakit
 * tujuan. Menyalinnya melahirkan dua sumber kebenaran untuk satu rujukan,
 * dan yang dikirim ke Sisrute justru bisa jadi yang salah.
 *
 * RUJUKAN MASUK PUNYA TEMPATNYA SENDIRI karena memang data baru:
 * permintaan dari rumah sakit lain, atas pasien yang belum terdaftar di
 * sini dan belum tentu datang.
 *
 * IDENTITAS PASIEN DARI PERUJUK TIDAK PERNAH MEMBUAT PASIEN DI MASTER
 * KITA. Ia disimpan apa adanya sebagai keterangan; pasien baru dibuat saat
 * ia tiba dan identitasnya diperiksa langsung. Membuat rekam medis dari
 * data yang belum diverifikasi melahirkan pasien ganda — dan pasien ganda
 * berarti riwayat yang terbelah dua.
 *
 * PENOLAKAN WAJIB BERALASAN, dan ini bukan formalitas: rumah sakit
 * perujuk yang ditolak tanpa alasan harus mencari secara buta sementara
 * pasiennya menunggu di IGD atau di dalam ambulans. Ditegakkan service
 * DAN basis data.
 *
 * MENERIMA RUJUKAN BUKAN MENDAFTARKAN PASIEN. Menerima berarti tempat
 * disanggupi; pendaftaran terjadi saat pasiennya tiba. Menyatukannya
 * melahirkan kunjungan atas pasien yang tidak pernah datang, dan kunjungan
 * itu ikut terhitung di sensus harian.
 */
class SisruteReferralService
{
    /** Jenis referensi Sisrute yang bisa disegarkan. */
    public const REFERENSI = ['alasan-rujuk', 'diagnosa', 'faskes'];

    public const SISTEM = 'sisrute';

    public function __construct(
        private readonly SisruteClient $client,
        private readonly ReferralContext $rujukan,
        private readonly PayerReferenceService $referensi,
    ) {}

    // ------------------------------------------------------------ arah keluar

    /**
     * Mengajukan rujukan keluar yang sudah tercatat di konteks encounter.
     *
     * @throws IntegrationException
     */
    public function sendOutgoing(int $outgoingReferralId, array $data = [], ?int $actorId = null): SisruteReferral
    {
        $sumber = $this->rujukan->outgoing($outgoingReferralId)
            ?? throw new IntegrationException("Rujukan keluar #{$outgoingReferralId} tidak ditemukan.");

        $berjalan = SisruteReferral::query()
            ->where('outgoing_referral_id', $outgoingReferralId)
            ->whereIn('status', SisruteReferral::BERJALAN)
            ->exists();

        if ($berjalan) {
            throw new IntegrationException(
                'Rujukan ini sudah punya pengajuan Sisrute yang masih berjalan. '
                . 'Dua pengajuan membuat dua rumah sakit menyiapkan tempat untuk satu pasien.'
            );
        }

        $ringkasan = trim((string) ($data['clinical_summary'] ?? ''));

        if ($ringkasan === '') {
            throw new IntegrationException(
                'Ringkasan kondisi pasien wajib diisi: rumah sakit tujuan menilai kesanggupannya dari situ, bukan dari nama diagnosis saja.'
            );
        }

        $hasil = $this->client->sendReferral([
            'kodeFaskesTujuan' => $sumber->destination_facility_code,
            'namaFaskesTujuan' => $sumber->destination_facility_name,
            'namaPasien' => $sumber->patient_name,
            'kodeAlasan' => $data['reason_code'] ?? null,
            'diagnosa' => $sumber->diagnosis,
            'ringkasanKlinis' => $ringkasan,
            'tglRujukan' => Carbon::parse($sumber->referred_at)->toDateTimeString(),
        ]);

        $berhasil = (bool) ($hasil['success'] ?? false);
        $tujuanPenuh = ($hasil['data']['statusTujuan'] ?? null) === 'penuh';

        return SisruteReferral::query()->create([
            'direction' => SisruteReferral::KELUAR,
            'sisrute_number' => $hasil['data']['noRujukan'] ?? null,
            'outgoing_referral_id' => $outgoingReferralId,
            // Isi rujukannya TIDAK disalin — cuma tujuan dan keterangan yang
            // memang milik pengajuan ini.
            'destination_facility_code' => $sumber->destination_facility_code,
            'destination_facility_name' => $sumber->destination_facility_name,
            'reason_code' => $data['reason_code'] ?? null,
            'reason_note' => $sumber->reason,
            'diagnosis_code' => $data['diagnosis_code'] ?? null,
            'diagnosis_note' => $sumber->diagnosis,
            'clinical_summary' => $ringkasan,
            'status' => match (true) {
                ! $berhasil => SisruteReferral::GAGAL,
                // Jawaban "penuh" sejak awal adalah PENOLAKAN, dan
                // alasannya disebut — bukan dibiarkan menggantung.
                $tujuanPenuh => SisruteReferral::DITOLAK,
                default => SisruteReferral::DIAJUKAN,
            },
            'rejection_reason' => $tujuanPenuh && $berhasil ? 'Kapasitas rumah sakit tujuan penuh.' : null,
            'responded_at' => $tujuanPenuh && $berhasil ? now() : null,
            'requested_at' => now(),
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * @throws IntegrationException
     */
    public function cancelOutgoing(SisruteReferral $rujukan, string $reason): SisruteReferral
    {
        if ($rujukan->direction !== SisruteReferral::KELUAR) {
            throw new IntegrationException('Hanya rujukan keluar yang bisa ditarik dari sini.');
        }

        if (! in_array($rujukan->status, SisruteReferral::BERJALAN, true)) {
            throw new IntegrationException("Rujukan berstatus '{$rujukan->status}' sudah tidak berjalan.");
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new IntegrationException('Alasan penarikan wajib diisi.');
        }

        $hasil = $this->client->cancelReferral((string) $rujukan->sisrute_number, $alasan);

        // Penarikan yang DITOLAK Sisrute tidak mengubah status lokal: kalau
        // diubah sepihak, rumah sakit tujuan masih menyiapkan tempat untuk
        // pasien yang kita anggap sudah batal.
        if (! ($hasil['success'] ?? false)) {
            $rujukan->update([
                'response_code' => $hasil['code'] ?? null,
                'response_message' => $hasil['message'] ?? null,
                'raw_response' => $hasil,
            ]);

            return $rujukan->refresh();
        }

        $rujukan->update([
            'status' => SisruteReferral::DIBATALKAN,
            'rejection_reason' => $alasan,
            'responded_at' => now(),
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
        ]);

        return $rujukan->refresh();
    }

    // ------------------------------------------------------------- arah masuk

    /**
     * Mengambil rujukan masuk dan mencatat yang belum pernah tercatat.
     *
     * IDEMPOTEN atas nomor rujukan Sisrute: mengambil berulang kali tidak
     * menggandakan permintaan, karena permintaan ganda membuat dua petugas
     * menjawab satu rujukan yang sama secara berbeda.
     *
     * @return array{baru: int, sudah_ada: int}
     */
    public function fetchIncoming(string $from, string $until, ?int $actorId = null): array
    {
        $hasil = $this->client->fetchIncoming($from, $until);

        if (! ($hasil['success'] ?? false)) {
            throw new IntegrationException(
                'Gagal mengambil rujukan masuk dari Sisrute: ' . ($hasil['message'] ?? 'tidak ada pesan.')
            );
        }

        $baru = 0;
        $sudahAda = 0;

        foreach ($hasil['data']['rujukan'] ?? [] as $baris) {
            $nomor = (string) ($baris['noRujukan'] ?? '');

            if ($nomor === '') {
                continue;
            }

            $ada = SisruteReferral::query()
                ->where('direction', SisruteReferral::MASUK)
                ->where('sisrute_number', $nomor)
                ->exists();

            if ($ada) {
                $sudahAda++;

                continue;
            }

            SisruteReferral::query()->create([
                'direction' => SisruteReferral::MASUK,
                'sisrute_number' => $nomor,
                // Identitas menurut PERUJUK — tidak pernah dipakai membuat
                // pasien di master kita. Lihat catatan kelas.
                'patient_name' => $baris['namaPasien'] ?? null,
                'patient_identity_number' => $baris['nik'] ?? null,
                'patient_birth_date' => $baris['tglLahir'] ?? null,
                'patient_sex' => $baris['jenisKelamin'] ?? null,
                'origin_facility_code' => $baris['kodeFaskesAsal'] ?? null,
                'origin_facility_name' => $baris['namaFaskesAsal'] ?? null,
                'reason_code' => $baris['kodeAlasan'] ?? null,
                'diagnosis_code' => $baris['diagnosa'] ?? null,
                'clinical_summary' => $baris['ringkasanKlinis'] ?? null,
                'status' => SisruteReferral::DIAJUKAN,
                'requested_at' => $baris['tglRujukan'] ?? now(),
                'raw_response' => $baris,
                'recorded_by' => $actorId,
            ]);

            $baru++;
        }

        return ['baru' => $baru, 'sudah_ada' => $sudahAda];
    }

    /**
     * Menjawab rujukan masuk: sanggup atau tidak.
     *
     * @throws IntegrationException
     */
    public function respond(SisruteReferral $rujukan, bool $accepted, ?string $reason = null, ?string $responderName = null, ?int $actorId = null): SisruteReferral
    {
        if (! $rujukan->isIncoming()) {
            throw new IntegrationException('Hanya rujukan masuk yang dijawab dari sini.');
        }

        if ($rujukan->status !== SisruteReferral::DIAJUKAN) {
            throw new IntegrationException("Rujukan ini sudah dijawab; status sekarang '{$rujukan->status}'.");
        }

        $alasan = $reason === null ? null : trim($reason);

        if (! $accepted && ($alasan === null || $alasan === '')) {
            throw new IntegrationException(
                'Penolakan rujukan wajib disertai alasan: rumah sakit perujuk yang ditolak tanpa alasan harus mencari secara buta sementara pasiennya menunggu.'
            );
        }

        $hasil = $this->client->respondIncoming([
            'noRujukan' => $rujukan->sisrute_number,
            'sanggup' => $accepted,
            'alasan' => $alasan,
        ]);

        if (! ($hasil['success'] ?? false)) {
            $rujukan->update([
                'response_code' => $hasil['code'] ?? null,
                'response_message' => $hasil['message'] ?? null,
                'raw_response' => $hasil,
            ]);

            return $rujukan->refresh();
        }

        $rujukan->update([
            'status' => $accepted ? SisruteReferral::DITERIMA : SisruteReferral::DITOLAK,
            'rejection_reason' => $accepted ? null : $alasan,
            'responded_at' => now(),
            'responded_by_name' => $responderName,
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId ?? $rujukan->recorded_by,
        ]);

        return $rujukan->refresh();
    }

    /**
     * Menandai pasien rujukan benar-benar tiba dan sudah didaftarkan.
     *
     * @throws IntegrationException
     */
    public function markArrived(SisruteReferral $rujukan, int $registrationId): SisruteReferral
    {
        if ($rujukan->status !== SisruteReferral::DITERIMA) {
            throw new IntegrationException(
                'Hanya rujukan yang sudah disanggupi yang bisa ditandai tiba. '
                . "Status sekarang '{$rujukan->status}'."
            );
        }

        $rujukan->update([
            'status' => SisruteReferral::TIBA,
            'registration_id' => $registrationId,
        ]);

        return $rujukan->refresh();
    }

    /**
     * Rujukan yang sudah disanggupi tapi pasiennya belum tiba.
     *
     * Tempat yang disanggupi menahan kapasitas nyata; yang menggantung
     * terlalu lama perlu ditanyakan, bukan dibiarkan menahan bed kosong.
     */
    public function awaitingArrival(): Collection
    {
        return SisruteReferral::query()
            ->where('direction', SisruteReferral::MASUK)
            ->where('status', SisruteReferral::DITERIMA)
            ->whereNull('registration_id')
            ->orderBy('responded_at')
            ->get();
    }

    /** Rujukan masuk yang belum dijawab sama sekali. */
    public function unanswered(): Collection
    {
        return SisruteReferral::query()
            ->where('direction', SisruteReferral::MASUK)
            ->where('status', SisruteReferral::DIAJUKAN)
            ->orderBy('requested_at')
            ->get();
    }

    public function referrals(string $direction, ?string $status = null, int $limit = 200): Collection
    {
        return SisruteReferral::query()
            ->where('direction', $direction)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // -------------------------------------------------------------- referensi

    /**
     * Menyegarkan satu daftar referensi Sisrute.
     *
     * Memakai mekanisme daftar referensi sistem luar dari item E: aturan
     * penyegarannya sama persis — mengganti seluruh daftar, dan menolak
     * daftar kosong supaya salinan lama tidak hilang saat API bermasalah.
     *
     * @throws IntegrationException
     */
    public function refreshReferences(string $type): int
    {
        if (! in_array($type, self::REFERENSI, true)) {
            throw new IntegrationException("Jenis referensi Sisrute '{$type}' tidak dikenal.");
        }

        $hasil = $this->client->references($type);

        if (! ($hasil['success'] ?? false)) {
            throw new IntegrationException(
                "Gagal mengambil referensi {$type} dari Sisrute: " . ($hasil['message'] ?? 'tidak ada pesan.')
            );
        }

        $baris = array_map(
            fn (array $r) => ['code' => $r['kode'] ?? '', 'name' => $r['nama'] ?? '', 'raw' => $r],
            $hasil['data']['daftar'] ?? []
        );

        return $this->referensi->refresh(self::SISTEM, $type, $baris);
    }
}
