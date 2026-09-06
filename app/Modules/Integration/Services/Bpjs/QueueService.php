<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsQueueRegistration;
use App\Modules\Integration\Models\BpjsQueueTask;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Antrean Mobile JKN (domain L sisa) — 3 kode.
 *
 *   register / cancel -> bpjs_antrean_pertanggal,
 *                        batal_pendaftaran_mobilejkn_bpjs
 *   sendTask          -> bpjs_task_id
 *
 * KEWAJIBAN, BUKAN FITUR. Sejak 2022 rumah sakit wajib mengirim data
 * antrean supaya peserta bisa mendaftar dan melihat posisinya dari Mobile
 * JKN. Yang dilihat pasien adalah data yang KITA kirim; antrean yang tidak
 * diperbarui membuat pasien datang pada waktu yang salah.
 *
 * TIGA ATURAN:
 *
 * 1. TASK ID MENANDAI TAHAP YANG SUDAH TERCATAT, bukan mencatat waktu
 *    baru. Waktunya ada di encounter.registrations sejak domain J item D;
 *    di sini cuma disimpan KAPAN tahap itu dikirim ke BPJS dan apa
 *    jawabannya. Menyalin waktunya akan melahirkan dua kebenaran tentang
 *    kapan pasien dilayani.
 *
 * 2. TAHAP TIDAK DIKIRIM MUNDUR. BPJS menomori tahap 1 sampai 7 secara
 *    berurutan; mengirim tahap 3 setelah tahap 5 berhasil membuat catatan
 *    waktu pelayanan di sisi BPJS berjalan mundur, dan pasien melihat
 *    antrean yang seolah kembali ke belakang.
 *
 * 3. YANG SUDAH BERHASIL TIDAK DIKIRIM ULANG. Pengiriman ulang tahap yang
 *    sukses membuat BPJS mencatat waktu pelayanan yang berubah-ubah untuk
 *    pasien yang sama. Yang GAGAL justru harus bisa diulang.
 */
class QueueService
{
    /** Penomoran tahap milik BPJS, berikut artinya. */
    public const TAHAP = [
        1 => 'Mulai menunggu admisi',
        2 => 'Selesai admisi',
        3 => 'Mulai menunggu poli',
        4 => 'Mulai dilayani poli',
        5 => 'Selesai dilayani poli',
        6 => 'Mulai menunggu farmasi',
        7 => 'Selesai farmasi',
    ];

    public function __construct(private readonly QueueClient $client) {}

    // ------------------------------------------------------------ pendaftaran

    /**
     * Mencatat pendaftaran antrean.
     *
     * registration_id boleh kosong: pendaftaran dari Mobile JKN datang
     * SEBELUM pasien tiba dan kunjungannya dibuat.
     *
     * @throws IntegrationException
     */
    public function register(array $data, ?int $actorId = null): BpjsQueueRegistration
    {
        foreach (['card_number', 'service_date', 'poly_code'] as $wajib) {
            if (empty($data[$wajib])) {
                throw new IntegrationException("Kolom {$wajib} wajib diisi untuk mendaftarkan antrean.");
            }
        }

        $aktif = BpjsQueueRegistration::query()
            ->where('card_number', $data['card_number'])
            ->where('service_date', $data['service_date'])
            ->where('poly_code', $data['poly_code'])
            ->where('status', '<>', BpjsQueueRegistration::BATAL)
            ->exists();

        if ($aktif) {
            throw new IntegrationException(
                'Peserta ini sudah punya antrean aktif pada poli dan tanggal yang sama.'
            );
        }

        return BpjsQueueRegistration::query()->create([
            'registration_id' => $data['registration_id'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'booking_code' => $data['booking_code'] ?? null,
            'card_number' => $data['card_number'],
            'nik' => $data['nik'] ?? null,
            'patient_name' => $data['patient_name'] ?? null,
            'service_date' => $data['service_date'],
            'poly_code' => $data['poly_code'],
            'practitioner_code' => $data['practitioner_code'] ?? null,
            'queue_number' => $data['queue_number'] ?? null,
            'status' => BpjsQueueRegistration::TERDAFTAR,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Menautkan antrean Mobile JKN ke kunjungan saat pasien datang.
     *
     * @throws IntegrationException
     */
    public function link(BpjsQueueRegistration $antrean, int $registrationId, string $registrationNumber): BpjsQueueRegistration
    {
        if ($antrean->registration_id !== null) {
            throw new IntegrationException('Antrean ini sudah tertaut ke kunjungan lain.');
        }

        if ($antrean->status === BpjsQueueRegistration::BATAL) {
            throw new IntegrationException('Antrean yang dibatalkan tidak bisa ditautkan.');
        }

        $antrean->update([
            'registration_id' => $registrationId,
            'registration_number' => $registrationNumber,
        ]);

        return $antrean->refresh();
    }

    /**
     * @throws IntegrationException
     */
    public function cancel(BpjsQueueRegistration $antrean, string $reason): BpjsQueueRegistration
    {
        if ($antrean->status === BpjsQueueRegistration::SELESAI) {
            throw new IntegrationException('Antrean yang sudah selesai dilayani tidak bisa dibatalkan.');
        }

        if ($antrean->status === BpjsQueueRegistration::BATAL) {
            throw new IntegrationException('Antrean ini sudah dibatalkan.');
        }

        $jawab = $this->client->cancelQueue($antrean->booking_code ?? '', $reason);

        if (! ($jawab['success'] ?? false)) {
            throw new IntegrationException(
                'BPJS menolak pembatalan antrean: ' . ($jawab['message'] ?? 'tanpa keterangan') . '.'
            );
        }

        $antrean->update([
            'status' => BpjsQueueRegistration::BATAL,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $antrean->refresh();
    }

    // ------------------------------------------------------------------ tahap

    /**
     * Mengirim satu tahap antrean ke BPJS.
     *
     * @throws IntegrationException
     */
    public function sendTask(BpjsQueueRegistration $antrean, int $taskId, ?int $actorId = null): BpjsQueueTask
    {
        if (! isset(self::TAHAP[$taskId])) {
            throw new IntegrationException("Tahap antrean {$taskId} tidak dikenal; BPJS mengenal 1 sampai 7.");
        }

        if ($antrean->status === BpjsQueueRegistration::BATAL) {
            throw new IntegrationException('Antrean yang dibatalkan tidak bisa dikirimi tahap.');
        }

        $berhasil = $this->successfulTasks($antrean);

        if (in_array($taskId, $berhasil, true)) {
            throw new IntegrationException(
                "Tahap {$taskId} sudah pernah dikirim dan berhasil; pengiriman ulang membuat waktu pelayanan di sisi BPJS berubah-ubah."
            );
        }

        // Tidak mundur — lihat aturan 2 pada docblock.
        $tertinggi = $berhasil === [] ? 0 : max($berhasil);

        if ($taskId < $tertinggi) {
            throw new IntegrationException(
                "Tahap {$taskId} lebih awal daripada tahap {$tertinggi} yang sudah terkirim; antrean tidak boleh berjalan mundur."
            );
        }

        $jawab = $this->client->sendTask([
            'kodebooking' => $antrean->booking_code,
            'taskid' => $taskId,
            'waktu' => now()->valueOf(),
        ]);

        return BpjsQueueTask::query()->create([
            'queue_registration_id' => $antrean->id,
            'task_id' => $taskId,
            'sent_at' => now(),
            'success' => (bool) ($jawab['success'] ?? false),
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'sent_by' => $actorId,
        ]);
    }

    /** @return array<int,int> */
    public function successfulTasks(BpjsQueueRegistration $antrean): array
    {
        return BpjsQueueTask::query()
            ->where('queue_registration_id', $antrean->id)
            ->where('success', true)
            ->pluck('task_id')
            ->map(fn ($t) => (int) $t)
            ->sort()
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- laporan

    public function queueFor(string $date, ?string $polyCode = null): Collection
    {
        return BpjsQueueRegistration::query()
            ->where('service_date', $date)
            ->when($polyCode, fn ($q) => $q->where('poly_code', $polyCode))
            ->orderBy('poly_code')
            ->orderBy('queue_number')
            ->get();
    }

    /**
     * Antrean Mobile JKN yang belum bertemu kunjungannya.
     *
     * Inilah yang paling perlu terlihat di loket: peserta sudah mendaftar
     * dari rumah, tapi belum ada kunjungan yang dibuat untuknya. Tanpa
     * daftar ini, petugas tidak tahu siapa yang sedang ditunggu.
     */
    public function unlinked(string $date): Collection
    {
        return BpjsQueueRegistration::query()
            ->where('service_date', $date)
            ->whereNull('registration_id')
            ->where('status', '<>', BpjsQueueRegistration::BATAL)
            ->orderBy('queue_number')
            ->get();
    }

    /**
     * Antrean yang tahapnya belum lengkap terkirim.
     *
     * Tahap yang tidak terkirim membuat pasien melihat antrean yang macet
     * di Mobile JKN meski di rumah sakit sudah dilayani.
     */
    public function incompleteTasks(string $date): Collection
    {
        return DB::table('integration.bpjs_queue_registrations as a')
            ->where('a.service_date', $date)
            ->where('a.status', '<>', BpjsQueueRegistration::BATAL)
            ->selectRaw('a.id, a.card_number, a.patient_name, a.poly_code, a.queue_number, a.status,
                         (SELECT count(*) FROM integration.bpjs_queue_tasks t
                           WHERE t.queue_registration_id = a.id AND t.success = true) AS tahap_terkirim,
                         (SELECT count(*) FROM integration.bpjs_queue_tasks t
                           WHERE t.queue_registration_id = a.id AND t.success = false) AS tahap_gagal')
            ->orderBy('a.queue_number')
            ->get()
            ->filter(fn ($a) => $a->tahap_terkirim < 7)
            ->values();
    }

    public function tasks(BpjsQueueRegistration $antrean): Collection
    {
        return BpjsQueueTask::query()
            ->where('queue_registration_id', $antrean->id)
            ->orderBy('task_id')
            ->orderBy('id')
            ->get();
    }
}
