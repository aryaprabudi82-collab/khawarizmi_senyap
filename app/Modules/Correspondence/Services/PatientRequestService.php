<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\PatientRequest;
use Illuminate\Support\Collection;

/**
 * Permintaan pasien atas haknya (domain P item B).
 *
 * Kelima jenisnya — bimbingan rohani, perlindungan dari kekerasan,
 * privasi, second opinion, cuti perawatan — dilayani satu mekanisme
 * karena bentuknya memang satu: seseorang meminta sesuatu atas nama
 * pasien, lalu rumah sakit menjawab.
 */
class PatientRequestService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function request(array $data, ?int $recordedBy = null): PatientRequest
    {
        $this->assertPeminta($data);
        $this->assertTanggalCuti($data);

        return PatientRequest::query()->create($data + [
            'request_number' => $this->numbers->allocate('PRM'),
            'requested_at' => $data['requested_at'] ?? now(),
            'status' => PatientRequest::STATUS_DIMINTA,
            'recorded_by' => $recordedBy,
        ]);
    }

    /**
     * Permintaan dipenuhi.
     *
     * Keterangan boleh kosong: pemenuhan meninggalkan bukti pada
     * perbuatannya sendiri — rohaniwan yang datang, tirai yang dipasang,
     * dokter kedua yang memeriksa.
     */
    public function fulfil(PatientRequest $request, string $olehNama, ?int $olehId = null, ?string $catatan = null): PatientRequest
    {
        $this->assertBelumDijawab($request);

        $request->update([
            'status' => PatientRequest::STATUS_DIPENUHI,
            'response_note' => $catatan,
            'responded_by' => $olehId,
            'responded_by_name' => $olehNama,
            'responded_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Permintaan ditolak — alasannya WAJIB.
     *
     * Ketaksimetrisan dengan fulfil() disengaja. Permintaan yang ditolak
     * tidak meninggalkan apa pun selain catatan ini; kalau catatannya
     * boleh kosong, penolakan hak pasien menjadi peristiwa tanpa jejak —
     * dan justru itulah peristiwa yang paling perlu berjejak.
     */
    public function decline(PatientRequest $request, string $alasan, string $olehNama, ?int $olehId = null): PatientRequest
    {
        $this->assertBelumDijawab($request);

        if (blank($alasan)) {
            throw new CorrespondenceException('Penolakan permintaan pasien harus menyebutkan alasannya.');
        }

        $request->update([
            'status' => PatientRequest::STATUS_DITOLAK,
            'response_note' => $alasan,
            'responded_by' => $olehId,
            'responded_by_name' => $olehNama,
            'responded_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Permintaan yang belum dijawab, yang paling lama menunggu di atas.
     *
     * Ada karena permintaan yang tidak dijawab siapa pun adalah cara
     * kegagalan yang sebenarnya — dan ia tidak terlihat kalau tidak ada
     * yang mendaftarnya. Layar riwayat biasa menampilkan yang terbaru,
     * yang justru menyembunyikan permintaan lama yang terlantar.
     *
     * @return Collection<int, PatientRequest>
     */
    public function outstanding(?string $jenis = null): Collection
    {
        return PatientRequest::query()
            ->where('status', PatientRequest::STATUS_DIMINTA)
            ->when($jenis !== null, fn ($q) => $q->where('request_type', $jenis))
            ->orderBy('requested_at')
            ->get();
    }

    private function assertBelumDijawab(PatientRequest $request): void
    {
        if ($request->status !== PatientRequest::STATUS_DIMINTA) {
            throw new CorrespondenceException(
                'Permintaan ini sudah dijawab ('.$request->status.'); catat permintaan baru bila keadaannya berubah.'
            );
        }
    }

    private function assertPeminta(array $data): void
    {
        $hubungan = $data['requester_relationship'] ?? null;

        if (! in_array($hubungan, PatientRequest::HUBUNGAN, true)) {
            throw new CorrespondenceException(
                'Hubungan peminta dengan pasien harus disebutkan dan dikenal — permintaan atas nama '.
                'pasien yang diajukan orang tanpa hubungan apa pun bukan permintaan pasien.'
            );
        }
    }

    private function assertTanggalCuti(array $data): void
    {
        $jenis = $data['request_type'] ?? null;
        $mulai = $data['leave_starts_at'] ?? null;
        $selesai = $data['leave_ends_at'] ?? null;

        if ($jenis === PatientRequest::JENIS_CUTI) {
            if (blank($mulai) || blank($selesai)) {
                throw new CorrespondenceException('Cuti perawatan harus menyebutkan tanggal mulai dan selesai.');
            }

            if (strtotime((string) $selesai) < strtotime((string) $mulai)) {
                throw new CorrespondenceException('Tanggal selesai cuti tidak boleh mendahului tanggal mulai.');
            }

            return;
        }

        if (filled($mulai) || filled($selesai)) {
            throw new CorrespondenceException(
                'Tanggal cuti hanya berlaku untuk pengajuan cuti perawatan.'
            );
        }
    }
}
