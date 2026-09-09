<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Models\IcraMatrixCell;
use App\Modules\Quality\Models\IcraPrecautionClass;

class IcraService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * Membuat pengkajian pra-konstruksi.
     *
     * KELAS PENCEGAHAN DIHITUNG DARI MATRIKS, TIDAK PERNAH DITERIMA DARI
     * PEMANGGIL. Kelas ICRA bukan pendapat: ia hasil persilangan tipe
     * aktivitas proyek dengan kelompok risiko area terdampak, dan seluruh
     * gunanya adalah menutup ruang tawar-menawar. Dengan kelas yang
     * diketik, proyek Tipe D di ruang isolasi bisa tercatat Kelas I dan
     * tidak ada yang menolaknya — lalu dokumen ICRA-nya justru jadi bukti
     * bahwa rumah sakit sudah menilai dan menyimpulkan boleh.
     *
     * $chosenClass hanya dipakai untuk sel matriks yang memang memberi
     * rentang; pada sel bernilai tunggal ia diabaikan, bukan dipatuhi.
     */
    public function assess(
        array $data,
        int $assessedBy,
        IcraActivityType $activityType,
        IcraArea $area,
        ?IcraPrecautionClass $chosenClass = null,
        ?string $decidedBy = null,
        ?string $decisionReason = null
    ): IcraAssessment {
        /*
         * Tipe aktivitas dan area WAJIB, bukan opsional. Tanpa keduanya
         * matriks tidak bisa dijalankan, dan pengkajian yang tidak bisa
         * menentukan kelas pencegahannya bukan pengkajian ICRA — ia catatan
         * bahwa ada proyek. Membuatnya opsional berarti menyediakan jalan
         * memutar yang persis menghapus gunanya aturan ini.
         */
        // Nilai yang menentukan identitas dokumen tidak diterima dari
        // pemanggil — aturan yang sama seperti jenis persetujuan yang
        // diambil dari templatenya.
        unset($data['precaution_class_id'], $data['risk_class'], $data['risk_group_id']);

        $data += $this->tetapkanKelas($activityType, $area, $chosenClass, $decidedBy, $decisionReason);

        return IcraAssessment::query()->create($data + [
            'assessment_number' => $this->numbers->allocate('ICRA'),
            'status' => IcraAssessment::STATUS_AKTIF,
            'assessed_by' => $assessedBy,
            'assessed_at' => now(),
        ]);
    }

    /**
     * Menentukan kelas pencegahan dari matriks.
     *
     * @return array<string, mixed>
     */
    public function tetapkanKelas(
        IcraActivityType $activityType,
        IcraArea $area,
        ?IcraPrecautionClass $chosenClass,
        ?string $decidedBy,
        ?string $decisionReason
    ): array {
        $sel = IcraMatrixCell::query()
            ->where('activity_type_id', $activityType->id)
            ->where('risk_group_id', $area->risk_group_id)
            ->first();

        if ($sel === null) {
            throw new QualityException(
                'Matriks ICRA belum punya sel untuk aktivitas '.$activityType->code.
                ' pada kelompok risiko area ini — kelasnya tidak bisa ditentukan, dan menebaknya '.
                'berarti menetapkan pengendalian konstruksi tanpa dasar.'
            );
        }

        $dasar = [
            'activity_type_id' => $activityType->id,
            'area_id' => $area->id,
            'risk_group_id' => $area->risk_group_id,
        ];

        if (! $sel->butuhKeputusanKomite()) {
            /*
             * Sel bernilai tunggal: kelas yang diusulkan pemanggil diabaikan,
             * bukan ditolak dengan galat. Menolaknya akan membuat layar gagal
             * hanya karena mengirim nilai yang memang tidak dipakai; yang
             * penting adalah nilai itu TIDAK pernah menang atas matriks.
             */
            return $dasar + [
                'precaution_class_id' => $sel->min_class_id,
                'risk_class' => $sel->minClass->code,
                'class_decided_by' => null,
                'class_decision_reason' => null,
            ];
        }

        /*
         * Sel yang memberi rentang: standarnya menyerahkan pilihan kepada
         * komite pengendalian infeksi. Memaksanya jadi satu kelas akan
         * MENYEMBUNYIKAN keputusan yang standarnya justru mensyaratkan ada.
         */
        if ($chosenClass === null) {
            throw new QualityException(
                'Aktivitas '.$activityType->code.' pada area ini berada di sel '.
                $sel->minClass->code.'/'.$sel->maxClass->code.
                ' — pedomannya menyerahkan pilihan kepada komite pengendalian infeksi, '.
                'jadi kelasnya harus dipilih dan disebutkan siapa yang memutuskan.'
            );
        }

        if (! in_array($chosenClass->id, [$sel->min_class_id, $sel->max_class_id], true)) {
            throw new QualityException(
                'Kelas '.$chosenClass->code.' di luar rentang '.$sel->minClass->code.'/'.$sel->maxClass->code.
                ' yang diberikan matriks untuk kombinasi ini.'
            );
        }

        if (blank($decidedBy) || blank($decisionReason)) {
            throw new QualityException(
                'Pemilihan kelas dari rentang harus menyebutkan siapa yang memutuskan dan atas dasar apa — '.
                'keputusan komite yang tidak berpenanggung jawab sama saja dengan tidak ada keputusan.'
            );
        }

        return $dasar + [
            'precaution_class_id' => $chosenClass->id,
            'risk_class' => $chosenClass->code,
            'class_decided_by' => $decidedBy,
            'class_decision_reason' => $decisionReason,
        ];
    }

    /** Sel matriks untuk satu kombinasi, untuk ditampilkan sebelum disimpan. */
    public function cell(IcraActivityType $activityType, IcraArea $area): ?IcraMatrixCell
    {
        return IcraMatrixCell::query()
            ->with(['minClass', 'maxClass'])
            ->where('activity_type_id', $activityType->id)
            ->where('risk_group_id', $area->risk_group_id)
            ->first();
    }

    public function complete(IcraAssessment $assessment): IcraAssessment
    {
        if ($assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        $assessment->update(['status' => IcraAssessment::STATUS_SELESAI]);

        return $assessment->refresh();
    }

    public function cancel(IcraAssessment $assessment): IcraAssessment
    {
        if ($assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        $assessment->update(['status' => IcraAssessment::STATUS_DIBATALKAN]);

        return $assessment->refresh();
    }
}
