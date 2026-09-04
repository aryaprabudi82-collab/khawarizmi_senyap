<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\K3Incident;

/**
 * Alur insiden K3 sama seperti IKP: dilaporkan -> ditinjau -> ditutup,
 * berurutan ketat. Insiden K3 menyangkut pegawai/tenaga kerja sebagai
 * korban (kecelakaan kerja), beda subjek dari IKP yang menyangkut pasien
 * — makanya tabel dan service-nya terpisah meski alurnya identik.
 */
class K3IncidentService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function report(array $data, int $reportedBy): K3Incident
    {
        return K3Incident::query()->create($data + [
            'incident_number' => $this->numbers->allocate('K3'),
            'status' => K3Incident::STATUS_DILAPORKAN,
            'reported_by' => $reportedBy,
        ]);
    }

    public function review(K3Incident $incident, int $reviewedBy): K3Incident
    {
        if ($incident->status !== K3Incident::STATUS_DILAPORKAN) {
            throw new QualityException('Insiden ini sudah ditinjau atau ditutup.');
        }

        $incident->update([
            'status' => K3Incident::STATUS_DITINJAU,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);

        return $incident->refresh();
    }

    public function close(K3Incident $incident, string $correctiveAction): K3Incident
    {
        if ($incident->status !== K3Incident::STATUS_DITINJAU) {
            throw new QualityException('Insiden harus ditinjau lebih dulu sebelum bisa ditutup.');
        }

        $incident->update([
            'status' => K3Incident::STATUS_DITUTUP,
            'corrective_action' => $correctiveAction,
            'closed_at' => now(),
        ]);

        return $incident->refresh();
    }

    /**
     * jenis_cidera_k3rstahun (dan 6 kode "X Per Tahun" saudaranya —
     * dampak_cidera/jenis_luka/jenis_pekerjaan/lokasi_kejadian/penyebab/
     * bagian_tubuh_k3rstahun) di Khanza masing-masing menu rekap tahunan
     * terpisah per dimensi. Di sini digabung satu layar, dikelompokkan
     * langsung dari kolom teks bebas yang sudah ada di k3_incidents
     * (lihat catatan migrasi quality.k3_incidents) — bukan dari tabel
     * referensi terkendali, jadi kategori yang tampil persis apa yang
     * ditulis petugas saat lapor, bukan daftar baku.
     */
    public function yearlyRecap(int $year): array
    {
        $dasar = K3Incident::query()->whereYear('occurred_at', $year);

        $kelompokkan = fn (string $kolom) => (clone $dasar)
            ->selectRaw("{$kolom} as label, count(*) as jumlah")
            ->groupBy('label')
            ->orderByDesc('jumlah')
            ->pluck('jumlah', 'label');

        return [
            'total' => (clone $dasar)->count(),
            'per_status' => (clone $dasar)->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status'),
            'jenis_cidera' => $kelompokkan('injury_type'),
            'dampak_cidera' => $kelompokkan('injury_impact'),
            'bagian_tubuh' => $kelompokkan('body_part'),
            'jenis_pekerjaan' => $kelompokkan('job_type'),
            'lokasi_kejadian' => $kelompokkan('location'),
            'penyebab' => $kelompokkan('cause'),
        ];
    }
}
