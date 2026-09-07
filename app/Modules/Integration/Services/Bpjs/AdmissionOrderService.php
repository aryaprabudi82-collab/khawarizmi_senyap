<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsAdmissionOrder;
use App\Modules\Integration\Models\BpjsSep;
use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Models\SepReclassification;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surat Perintah Rawat Inap & reklasifikasi SEP (domain L item L) —
 * 3 kode: bpjs_surat_pri, reklasifikasi_ralan, reklasifikasi_ranap.
 *
 * SURAT PRI adalah perintah dokter kita agar peserta dirawat inap tanpa
 * rujukan baru, dan menjadi dasar terbitnya SEP rawat inap. NOMORNYA DARI
 * BPJS — nomor karangan tidak akan dikenali saat SEP-nya diterbitkan, dan
 * pasien yang sudah masuk bangsal terlanjur dirawat tanpa penjaminan.
 *
 * REKLASIFIKASI MENGUBAH APA YANG DITAGIHKAN KE NEGARA, jadi dijaga tiga
 * hal sekaligus:
 *
 * 1. YANG LAMA DISIMPAN, bukan ditimpa. Perubahan tagihan yang tidak
 *    meninggalkan jejak tidak bisa dibedakan dari kecurangan, sekalipun
 *    niatnya benar.
 * 2. ALASAN WAJIB DIISI. Reklasifikasi tanpa alasan adalah baris riwayat
 *    yang tidak menjelaskan apa pun saat ditanya auditor setahun kemudian.
 * 3. HANYA UNTUK SEP YANG BELUM DIKLAIM. Setelah klaim dikirim, isinya
 *    sudah dibekukan (item C); yang berubah harus lewat revisi klaim,
 *    bukan suntingan senyap pada SEP yang sedang diverifikasi BPJS.
 */
class AdmissionOrderService
{
    /** Status klaim yang membuat SEP-nya tidak boleh direklasifikasi lagi. */
    private const KLAIM_MENGUNCI = [
        Claim::TERKIRIM,
        Claim::DIKEMBALIKAN,
        Claim::TERVERIFIKASI,
    ];

    public function __construct(private readonly BpjsAdmissionClient $client) {}

    // ------------------------------------------------------------ Surat PRI

    /**
     * Menerbitkan Surat Perintah Rawat Inap.
     *
     * @throws IntegrationException
     */
    public function issue(array $data, ?int $actorId = null): BpjsAdmissionOrder
    {
        foreach (['patient_id', 'patient_mrn', 'patient_name', 'card_number'] as $wajib) {
            if (empty($data[$wajib])) {
                throw new IntegrationException('Identitas peserta wajib lengkap sebelum surat perintah rawat inap dibuat.');
            }
        }

        if (empty($data['planned_date'])) {
            throw new IntegrationException('Tanggal rencana masuk rawat inap wajib diisi.');
        }

        $rencana = Carbon::parse($data['planned_date'])->startOfDay();

        // Perintah rawat inap untuk tanggal yang sudah lewat tidak bisa
        // dipakai menerbitkan SEP, dan hanya akan menahan slot surat baru.
        if ($rencana->lt(now()->startOfDay())) {
            throw new IntegrationException('Tanggal rencana masuk tidak boleh sebelum hari ini.');
        }

        $registrationId = $data['registration_id'] ?? null;

        if ($registrationId !== null) {
            $adaAktif = BpjsAdmissionOrder::query()
                ->where('registration_id', $registrationId)
                ->whereIn('status', BpjsAdmissionOrder::AKTIF)
                ->exists();

            if ($adaAktif) {
                throw new IntegrationException(
                    'Kunjungan ini sudah punya surat perintah rawat inap yang berlaku. '
                    . 'Dua surat menerbitkan dua SEP rawat inap, dan salah satunya pasti ditolak saat klaim.'
                );
            }
        }

        $hasil = $this->client->createAdmissionOrder([
            'noKartu' => $data['card_number'],
            'tglRencanaMasuk' => $rencana->toDateString(),
            'kodeDokter' => $data['practitioner_code'] ?? null,
            'poliKontrol' => $data['poli_code'] ?? null,
            'user' => $data['practitioner_name'] ?? null,
        ]);

        return BpjsAdmissionOrder::query()->create([
            'patient_id' => $data['patient_id'],
            'patient_mrn' => $data['patient_mrn'],
            'patient_name' => $data['patient_name'],
            'card_number' => $data['card_number'],
            'registration_id' => $registrationId,
            'practitioner_id' => $data['practitioner_id'] ?? null,
            'practitioner_name' => $data['practitioner_name'] ?? null,
            'poli_code' => $data['poli_code'] ?? null,
            'planned_date' => $rencana->toDateString(),
            'diagnosis_code' => $data['diagnosis_code'] ?? null,
            'reason' => $data['reason'] ?? null,
            // Nomor dari BPJS — lihat catatan kelas.
            'order_number' => $hasil['data']['noSuratPRI'] ?? null,
            'status' => $hasil['success'] ? BpjsAdmissionOrder::TERBIT : BpjsAdmissionOrder::GAGAL,
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Menandai surat sudah dipakai menerbitkan SEP rawat inap.
     *
     * @throws IntegrationException
     */
    public function markUsed(BpjsAdmissionOrder $surat, string $sepNumber): BpjsAdmissionOrder
    {
        if ($surat->status !== BpjsAdmissionOrder::TERBIT) {
            throw new IntegrationException(
                "Hanya surat yang terbit yang bisa dipakai; status sekarang '{$surat->status}'."
            );
        }

        $surat->update([
            'status' => BpjsAdmissionOrder::TERPAKAI,
            'response_message' => "Dipakai menerbitkan SEP {$sepNumber}.",
        ]);

        return $surat->refresh();
    }

    /**
     * @throws IntegrationException
     */
    public function cancel(BpjsAdmissionOrder $surat, string $reason): BpjsAdmissionOrder
    {
        if ($surat->status === BpjsAdmissionOrder::TERPAKAI) {
            throw new IntegrationException(
                'Surat yang sudah dipakai menerbitkan SEP tidak bisa dibatalkan di sini; batalkan SEP-nya lebih dulu.'
            );
        }

        if ($surat->status === BpjsAdmissionOrder::BATAL) {
            throw new IntegrationException('Surat ini sudah dibatalkan.');
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new IntegrationException('Alasan pembatalan wajib diisi.');
        }

        $hasil = $this->client->cancelAdmissionOrder((string) $surat->order_number, $alasan);

        // Pembatalan yang DITOLAK BPJS tidak mengubah status lokal: kalau
        // diubah sepihak, sistem kita mengira surat batal sementara BPJS
        // masih menganggapnya berlaku — aturan yang sama seperti pembatalan
        // antrean Mobile JKN.
        if (! $hasil['success']) {
            $surat->update([
                'response_code' => $hasil['code'] ?? null,
                'response_message' => $hasil['message'] ?? null,
                'raw_response' => $hasil,
            ]);

            return $surat->refresh();
        }

        $surat->update([
            'status' => BpjsAdmissionOrder::BATAL,
            'cancelled_at' => now(),
            'cancellation_reason' => $alasan,
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
        ]);

        return $surat->refresh();
    }

    /** Surat yang tanggal rencananya sudah lewat tapi belum pernah dipakai. */
    public function stale(): Collection
    {
        return BpjsAdmissionOrder::query()
            ->where('status', BpjsAdmissionOrder::TERBIT)
            ->whereDate('planned_date', '<', now()->toDateString())
            ->orderBy('planned_date')
            ->get();
    }

    // ------------------------------------------------------- reklasifikasi

    /**
     * Mengubah klasifikasi SEP: jenis pelayanan dan/atau kelas rawat.
     *
     * @throws IntegrationException
     */
    public function reclassify(BpjsSep $sep, array $data, ?int $actorId = null): SepReclassification
    {
        if ($sep->status !== BpjsSep::STATUS_TERBIT) {
            throw new IntegrationException(
                "Hanya SEP yang terbit yang bisa direklasifikasi; status sekarang '{$sep->status}'."
            );
        }

        $alasan = trim((string) ($data['reason'] ?? ''));

        if ($alasan === '') {
            throw new IntegrationException(
                'Alasan reklasifikasi wajib diisi: baris riwayat tanpa alasan tidak menjelaskan apa pun saat ditanya auditor.'
            );
        }

        $jenisBaru = (string) ($data['new_service_type'] ?? '');

        if (! in_array($jenisBaru, [BpjsSep::JENIS_RANAP, BpjsSep::JENIS_RALAN], true)) {
            throw new IntegrationException("Jenis pelayanan '{$jenisBaru}' tidak dikenal.");
        }

        $kelasLama = $data['previous_class'] ?? null;
        $kelasBaru = $data['new_class'] ?? null;

        if ($jenisBaru === $sep->jenis_pelayanan && $kelasBaru === $kelasLama) {
            throw new IntegrationException('Tidak ada yang berubah: jenis pelayanan dan kelas rawatnya sama.');
        }

        $this->assertBelumDiklaim($sep);

        $hasil = $this->client->reclassifySep([
            'noSep' => $sep->sep_number,
            'jnsPelayanan' => $jenisBaru,
            'klsRawat' => $kelasBaru,
            'catatan' => $alasan,
        ]);

        return DB::transaction(function () use ($sep, $jenisBaru, $kelasLama, $kelasBaru, $alasan, $hasil, $actorId) {
            $riwayat = SepReclassification::query()->create([
                'sep_id' => $sep->id,
                'sep_number' => $sep->sep_number,
                'registration_id' => $sep->registration_id,
                // Yang lama disimpan lebih dulu, sebelum apa pun diubah.
                'previous_service_type' => $sep->jenis_pelayanan,
                'new_service_type' => $jenisBaru,
                'previous_class' => $kelasLama,
                'new_class' => $kelasBaru,
                'reason' => $alasan,
                'status' => $hasil['success'] ? SepReclassification::DITERIMA : SepReclassification::GAGAL,
                'response_code' => $hasil['code'] ?? null,
                'response_message' => $hasil['message'] ?? null,
                'raw_response' => $hasil,
                'recorded_by' => $actorId,
            ]);

            // SEP kita baru berubah kalau BPJS menerima perubahannya. Kalau
            // ditolak, yang tercatat di sini dan yang tercatat di BPJS akan
            // berbeda — dan klaimnya ditolak tanpa sebab yang jelas.
            if ($hasil['success']) {
                $sep->update(['jenis_pelayanan' => $jenisBaru]);
            }

            return $riwayat;
        });
    }

    /** Riwayat reklasifikasi satu SEP, terlama lebih dulu. */
    public function reclassificationHistory(BpjsSep $sep): Collection
    {
        return SepReclassification::query()
            ->where('sep_id', $sep->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @throws IntegrationException
     */
    private function assertBelumDiklaim(BpjsSep $sep): void
    {
        $klaim = Claim::query()
            ->where('sep_number', $sep->sep_number)
            ->whereIn('status', self::KLAIM_MENGUNCI)
            ->first();

        if ($klaim !== null) {
            throw new IntegrationException(
                "SEP ini sudah diklaim ({$klaim->claim_number}, status {$klaim->status}). "
                . 'Perubahannya harus lewat revisi klaim, bukan reklasifikasi SEP.'
            );
        }
    }
}
