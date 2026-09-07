<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Clinical\Models\NursingCarePlanItem;
use App\Modules\Clinical\Models\NursingDiagnosis;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asuhan keperawatan pasien (domain M item B).
 *
 * Menaungi bagian "masalah" dan "rencana" pada seluruh kode
 * penilaian_awal_keperawatan_* Khanza — sepuluh jenis asesmen keperawatan,
 * masing-masing dengan dua tabel anak di sana.
 *
 * ATURAN YANG DITEGAKKAN, DAN DASARNYA DARI KHANZA SENDIRI:
 *
 * 1. RENCANA HARUS BERADA DI BAWAH MASALAH YANG DITEGAKKAN. Di Khanza,
 *    tabel rencana adalah anak langsung dari lembar asesmen, sehingga
 *    rencana bisa dipilih tanpa masalahnya ikut dipilih — intervensi tanpa
 *    indikasi. Padahal master_rencana_keperawatan di Khanza punya foreign
 *    key ke master_masalah_keperawatan. Jadi yang dikerjakan di sini bukan
 *    menyimpang, melainkan menegakkan aturan yang Khanza sudah tuliskan di
 *    master tapi tidak dijaga di transaksinya.
 *
 * 2. ASUHAN MELEKAT PADA LEMBAR ASESMEN, bukan langsung pada kunjungan.
 *    Satu kunjungan bisa punya asesmen awal DAN asesmen lanjutan, dan
 *    masalah yang ditemukan di masing-masing bukan hal yang sama.
 *
 * 3. NAMA MASALAH DAN BUNYI RENCANA DISALIN saat dipilih. Master
 *    keperawatan direvisi mengikuti SDKI/SIKI, dan asuhan yang sudah
 *    ditulis perawat tidak boleh ikut berubah kalimatnya.
 *
 * 4. ASESMEN YANG SUDAH FINAL TIDAK BISA DITAMBAHI ASUHAN. Lembar yang
 *    sudah dikunci adalah pernyataan yang sudah selesai; menambahkan
 *    masalah sesudahnya membuat isi rekam medis bertambah tanpa jejak
 *    siapa dan kapan.
 */
class NursingCareService
{
    public function __construct(private readonly NursingCareContext $master) {}

    /**
     * Menegakkan satu masalah keperawatan pada lembar asesmen.
     *
     * @throws ClinicalException
     */
    public function addDiagnosis(FormResponse $assessment, string $problemCode, array $data = [], ?User $actor = null): NursingDiagnosis
    {
        $this->assertEditable($assessment);

        $spesialisasi = $data['specialty'] ?? null;

        $masalah = $this->master->problem($problemCode, $spesialisasi)
            ?? throw new ClinicalException(
                "Masalah keperawatan '{$problemCode}' tidak ada di master "
                . ($spesialisasi === null ? 'umum' : "spesialisasi {$spesialisasi}") . '.'
            );

        if (! $masalah->is_active) {
            throw new ClinicalException("Masalah keperawatan '{$masalah->name}' sudah tidak aktif.");
        }

        $sudahAda = NursingDiagnosis::query()
            ->where('form_response_id', $assessment->id)
            ->where('problem_code', $problemCode)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException("Masalah '{$masalah->name}' sudah ditegakkan pada asesmen ini.");
        }

        $urutan = $data['priority'] ?? (
            NursingDiagnosis::query()->where('form_response_id', $assessment->id)->max('priority') + 1
        );

        return NursingDiagnosis::query()->create([
            'form_response_id' => $assessment->id,
            'registration_id' => $assessment->registration_id,
            'patient_id' => $assessment->patient_id,
            'problem_code' => $masalah->code,
            // Disalin, bukan dirujuk — lihat aturan 3.
            'problem_name' => $masalah->name,
            'specialty' => $masalah->specialty,
            'standard_code' => $masalah->standard_code,
            'priority' => $urutan,
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Memilih satu rencana keperawatan di bawah masalah yang sudah
     * ditegakkan.
     *
     * @throws ClinicalException
     */
    public function addCarePlan(NursingDiagnosis $diagnosis, string $planCode, ?string $note = null): NursingCarePlanItem
    {
        $assessment = $diagnosis->formResponse;

        if ($assessment !== null) {
            $this->assertEditable($assessment);
        }

        // Induknya diperiksa di kueri — lihat aturan 1.
        $rencana = $this->master->carePlan($diagnosis->problem_code, $planCode, $diagnosis->specialty)
            ?? throw new ClinicalException(
                "Rencana '{$planCode}' bukan rencana untuk masalah '{$diagnosis->problem_name}'. "
                . 'Rencana keperawatan selalu melekat pada masalah yang mendasarinya.'
            );

        if (! $rencana->is_active) {
            throw new ClinicalException("Rencana keperawatan '{$planCode}' sudah tidak aktif.");
        }

        $sudahAda = NursingCarePlanItem::query()
            ->where('nursing_diagnosis_id', $diagnosis->id)
            ->where('plan_code', $planCode)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException('Rencana ini sudah dipilih untuk masalah tersebut.');
        }

        return NursingCarePlanItem::query()->create([
            'nursing_diagnosis_id' => $diagnosis->id,
            'plan_code' => $rencana->code,
            'plan' => $rencana->plan,
            'standard_code' => $rencana->standard_code,
            'status' => NursingCarePlanItem::DIRENCANAKAN,
            'note' => $note,
        ]);
    }

    /**
     * Mengubah status pelaksanaan satu rencana.
     *
     * @throws ClinicalException
     */
    public function updatePlanStatus(NursingCarePlanItem $item, string $status, ?string $note = null): NursingCarePlanItem
    {
        $sah = [
            NursingCarePlanItem::DIRENCANAKAN,
            NursingCarePlanItem::DIKERJAKAN,
            NursingCarePlanItem::DIHENTIKAN,
        ];

        if (! in_array($status, $sah, true)) {
            throw new ClinicalException("Status rencana '{$status}' tidak dikenal.");
        }

        if ($status === NursingCarePlanItem::DIHENTIKAN && blank($note)) {
            throw new ClinicalException(
                'Rencana yang dihentikan wajib disertai alasan: rencana yang hilang tanpa keterangan '
                . 'tidak bisa dibedakan dari rencana yang terlupakan.'
            );
        }

        $item->update(['status' => $status, 'note' => $note ?? $item->note]);

        return $item->refresh();
    }

    /**
     * Membatalkan satu masalah BERIKUT rencana di bawahnya.
     *
     * @throws ClinicalException
     */
    public function removeDiagnosis(NursingDiagnosis $diagnosis): void
    {
        $assessment = $diagnosis->formResponse;

        if ($assessment !== null) {
            $this->assertEditable($assessment);
        }

        // Rencana ikut terhapus lewat cascade: rencana yang tertinggal di
        // bawah masalah yang sudah dicabut adalah intervensi tanpa indikasi
        // — persis keadaan yang aturan 1 hindari.
        DB::transaction(fn () => $diagnosis->delete());
    }

    // ---------------------------------------------------------------- baca

    /** Asuhan keperawatan satu lembar asesmen, berikut rencananya. */
    public function forAssessment(FormResponse $assessment): Collection
    {
        return NursingDiagnosis::query()
            ->where('form_response_id', $assessment->id)
            ->with('carePlanItems')
            ->orderBy('priority')
            ->get();
    }

    /** Asuhan keperawatan satu kunjungan, lintas lembar asesmen. */
    public function forRegistration(int $registrationId): Collection
    {
        return NursingDiagnosis::query()
            ->where('registration_id', $registrationId)
            ->with('carePlanItems')
            ->orderBy('priority')
            ->get();
    }

    /**
     * Masalah yang rencananya belum satu pun dipilih.
     *
     * Masalah keperawatan yang ditegakkan tanpa rencana adalah diagnosis
     * tanpa tindak lanjut — sah dicatat, tapi harus terlihat supaya tidak
     * lolos begitu saja saat lembar asesmen difinalisasi.
     */
    public function withoutCarePlan(int $registrationId): Collection
    {
        return NursingDiagnosis::query()
            ->where('registration_id', $registrationId)
            ->whereDoesntHave('carePlanItems')
            ->orderBy('priority')
            ->get();
    }

    /**
     * @throws ClinicalException
     */
    private function assertEditable(FormResponse $assessment): void
    {
        if (! $assessment->isEditable()) {
            throw new ClinicalException(
                'Asesmen yang sudah difinalisasi tidak bisa ditambahi asuhan keperawatan. '
                . 'Isi rekam medis yang bertambah tanpa jejak siapa dan kapan tidak bisa dipertanggungjawabkan.'
            );
        }
    }
}
