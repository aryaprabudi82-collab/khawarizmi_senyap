<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Models\IcraAssessmentRequirement;
use App\Modules\Quality\Models\IcraAssessmentRisk;
use App\Modules\Quality\Models\IcraClassRequirement;
use App\Modules\Quality\Models\IcraMatrixCell;
use App\Modules\Quality\Models\IcraPrecautionClass;
use App\Modules\Quality\Models\IcraRiskItem;
use Illuminate\Support\Facades\DB;

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

    /**
     * Menyiapkan daftar periksa risiko dan persyaratan untuk satu kajian.
     *
     * Butir risiko DISALIN dari master, dan persyaratan DISALIN dari kelas
     * yang sudah ditetapkan. Keduanya dibekukan: revisi daftar periksa
     * atau SPO tahun depan tidak boleh mengubah bunyi kajian yang sudah
     * ditandatangani.
     *
     * Seluruh butir lahir dengan `present` KOSONG — belum diperiksa, bukan
     * "tidak ada".
     */
    public function prepareChecklist(IcraAssessment $assessment): IcraAssessment
    {
        return DB::transaction(function () use ($assessment) {
            if ($assessment->risks()->doesntExist()) {
                $butirRisiko = IcraRiskItem::query()
                    ->where('is_active', true)
                    ->orderBy('category')->orderBy('position')
                    ->get();

                foreach ($butirRisiko as $butir) {
                    $assessment->risks()->create([
                        'risk_item_id' => $butir->id,
                        'category' => $butir->category,
                        'label' => $butir->name,
                        'present' => null,
                    ]);
                }
            }

            if ($assessment->precaution_class_id !== null && $assessment->requirements()->doesntExist()) {
                $syarat = IcraClassRequirement::query()
                    ->where('precaution_class_id', $assessment->precaution_class_id)
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->get();

                foreach ($syarat as $urut => $s) {
                    $assessment->requirements()->create([
                        'position' => $urut + 1,
                        'requirement' => $s->requirement,
                        'fulfilled' => null,
                    ]);
                }
            }

            return $assessment->load(['risks', 'requirements']);
        });
    }

    /**
     * Mencatat hasil pemeriksaan satu butir risiko.
     *
     * $present null mengembalikan butir ke keadaan belum diperiksa —
     * dipakai saat penilai salah menandai, bukan sebagai cara menghapus
     * jejak.
     */
    public function markRisk(IcraAssessmentRisk $risk, ?bool $present, ?string $catatan = null): IcraAssessmentRisk
    {
        if ($risk->assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan; daftar risikonya tidak bisa diubah.');
        }

        $risk->update(['present' => $present, 'note' => $catatan]);

        return $risk->refresh();
    }

    /**
     * Mencatat pemenuhan satu persyaratan.
     *
     * Penyimpangan (fulfilled=false) WAJIB berketerangan: persyaratan yang
     * dipenuhi berbukti pada barrier yang terpasang, yang tidak dipenuhi
     * tidak meninggalkan apa pun selain catatan ini.
     */
    public function markRequirement(
        IcraAssessmentRequirement $requirement,
        ?bool $fulfilled,
        ?string $catatan = null,
        ?string $verifiedByName = null
    ): IcraAssessmentRequirement {
        if ($requirement->assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        if ($fulfilled === false && blank($catatan)) {
            throw new QualityException(
                'Persyaratan yang tidak dipenuhi harus disertai keterangan penyimpangannya — tanpa itu, '.
                'catatannya cuma memberi tahu ada yang tidak beres tanpa memberi tahu apanya.'
            );
        }

        $requirement->update([
            'fulfilled' => $fulfilled,
            'note' => $catatan,
            'verified_at' => $fulfilled === null ? null : now(),
            'verified_by_name' => $fulfilled === null ? null : $verifiedByName,
        ]);

        return $requirement->refresh();
    }

    /**
     * Menutup pengkajian.
     *
     * ATURANNYA SENGAJA TIDAK SIMETRIS, bentuk yang sama dengan
     * persetujuan/penolakan pada domain P.
     *
     * Persyaratan yang BELUM DIJAWAB menahan penutupan: menutup pengkajian
     * dengan persyaratan kosong berarti tidak ada yang memeriksa apakah
     * barrier benar-benar terpasang, dan dokumennya akan terbaca seolah
     * seluruhnya beres.
     *
     * Persyaratan yang dijawab TIDAK DIPENUHI tidak menahan apa pun —
     * penyimpangan yang tercatat berikut alasannya adalah catatan jujur
     * yang justru berguna, dan menahannya akan mendorong petugas
     * mengubahnya jadi "dipenuhi" supaya proyeknya bisa ditutup.
     */
    public function complete(IcraAssessment $assessment): IcraAssessment
    {
        if ($assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        $belum = $assessment->load('requirements')->persyaratanBelumDijawab();

        if ($belum !== []) {
            throw new QualityException(
                'Belum bisa ditutup — '.count($belum).' persyaratan belum dijawab: '.
                implode('; ', array_slice($belum, 0, 3)).(count($belum) > 3 ? '; …' : '').'.'
            );
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
