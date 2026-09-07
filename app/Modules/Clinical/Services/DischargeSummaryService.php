<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\DischargeSummary;
use App\Modules\Clinical\Models\Procedure;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resume medis (domain M item H).
 *
 * EMPAT ATURAN, dan tiga di antaranya pengetatan terhadap Khanza.
 *
 * 1. ISI RESUME DISALIN, TIDAK DIKETIK ULANG. Khanza menyediakan
 *    diagnosa_utama sampai diagnosa_sekunder4 dan prosedur_sekunder3
 *    sebagai kolom teks yang diisi manusia. Di sini seluruh diagnosis dan
 *    prosedur kunjungan itu dibekukan dari clinical.diagnoses dan
 *    clinical.procedures pada saat finalisasi — berapa pun jumlahnya, dan
 *    persis seperti yang tercatat. Resume yang berbeda dari rekam
 *    medisnya adalah surat keterangan yang salah, dan mengetik ulang
 *    adalah cara paling pasti membuat keduanya berbeda.
 *
 * 2. OBAT PULANG DARI RESEP YANG BENAR-BENAR DISERAHKAN. Bukan kolom
 *    teks bebas: yang dibawa pulang pasien adalah yang diserahkan
 *    farmasi, dan itu sudah tercatat.
 *
 * 3. KONDISI PULANG DISALIN DARI ADMISI. Rumah sakit sudah menyatakan
 *    pasiennya pulang hidup atau meninggal saat admisi ditutup;
 *    menanyakannya kedua kali membuka peluang resume menyatakan "hidup"
 *    untuk pasien yang tercatat meninggal.
 *
 * 4. RESUME RANAP TIDAK BISA DIFINALKAN SEBELUM PASIEN PULANG. Resume
 *    medis menurut namanya sendiri adalah ringkasan episode yang sudah
 *    selesai; memfinalkannya di tengah perawatan menghasilkan ringkasan
 *    perawatan yang belum terjadi. Boleh disusun sebagai draf sejak
 *    kapan pun — yang dikunci hanya finalisasinya.
 *
 * SATU EPISODE SATU RESUME. Ralat dilakukan dengan membatalkan lalu
 * membuat baru, bukan menimpa: resume yang sudah dibawa pasien ke
 * fasilitas lain tidak boleh berubah tanpa jejak.
 */
class DischargeSummaryService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    private const ADMISI = 'inpatient.v_admission_summary';

    private const RESEP = 'pharmacy.v_prescription_detail';

    /**
     * Membuka draf resume.
     *
     * Idempoten — berbeda dari hasil penunjang. Kunjungan yang sama tidak
     * punya dua resume, jadi memanggilnya dua kali melanjutkan draf yang
     * sama alih-alih membuat versi kedua yang bersaing.
     *
     * @throws ClinicalException
     */
    public function open(int $registrationId, ?User $actor = null): DischargeSummary
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $adaFinal = DischargeSummary::query()
            ->where('registration_id', $registrationId)
            ->where('status', DischargeSummary::FINAL)
            ->exists();

        if ($adaFinal) {
            throw new ClinicalException(
                'Kunjungan ini sudah punya resume final. Batalkan resume lama bila memang keliru — '
                .'dua resume untuk satu episode berarti dua versi cerita yang sama.'
            );
        }

        $draf = DischargeSummary::query()
            ->where('registration_id', $registrationId)
            ->where('status', DischargeSummary::DRAF)
            ->first();

        if ($draf !== null) {
            return $draf;
        }

        $admisi = $this->admission($registrationId);

        return DischargeSummary::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'admission_id' => $admisi?->admission_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'dpjp_practitioner_id' => $admisi?->dpjp_practitioner_id ?? $kunjungan->practitioner_id,
            'dpjp_name' => $admisi?->dpjp_name ?? $kunjungan->practitioner_name,
            'admitted_at' => $admisi?->admitted_at,
            'discharged_at' => $admisi?->discharged_at,
            'diagnoses' => [],
            'procedures' => [],
            'discharge_medications' => [],
            'status' => DischargeSummary::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Menyimpan bagian naratif.
     *
     * Yang boleh diisi manusia hanya ceritanya — keluhan, perjalanan
     * penyakit, terapi, anjuran. Diagnosis dan obat tidak ada di sini
     * karena keduanya disalin, bukan diketik.
     *
     * @throws ClinicalException
     */
    public function save(DischargeSummary $summary, array $data): DischargeSummary
    {
        if (! $summary->isEditable()) {
            throw new ClinicalException(
                'Resume yang sudah difinalkan tidak bisa diubah. Batalkan lalu buat resume baru bila memang '
                .'keliru — resume yang sudah dibawa pasien ke fasilitas lain tidak boleh berubah tanpa jejak.'
            );
        }

        $naratif = [
            'chief_complaint', 'illness_course', 'physical_findings', 'supporting_exams',
            'lab_results', 'treatment', 'diet', 'follow_up_instruction',
            'control_on', 'control_unit', 'prognosis', 'dpjp_name', 'dpjp_practitioner_id',
        ];

        $summary->update(array_intersect_key($data, array_flip($naratif)));

        return $summary->refresh();
    }

    /**
     * Membekukan resume sebagai bagian rekam medis.
     *
     * @throws ClinicalException
     */
    public function finalize(DischargeSummary $summary, ?User $actor = null): DischargeSummary
    {
        if ($summary->status === DischargeSummary::FINAL) {
            throw new ClinicalException('Resume ini sudah difinalkan.');
        }

        if ($summary->status === DischargeSummary::DIBATALKAN) {
            throw new ClinicalException('Resume yang dibatalkan tidak bisa difinalkan.');
        }

        $admisi = $summary->admission_id !== null
            ? DB::table(self::ADMISI)->where('admission_id', $summary->admission_id)->first()
            : null;

        if ($admisi !== null && $admisi->discharged_at === null) {
            throw new ClinicalException(
                'Pasien belum dinyatakan pulang, jadi resumenya belum bisa difinalkan. Resume medis adalah '
                .'ringkasan episode yang sudah selesai — silakan simpan sebagai draf dulu.'
            );
        }

        $diagnoses = $this->freezeDiagnoses($summary->registration_id);

        if ($diagnoses === []) {
            throw new ClinicalException(
                'Belum ada diagnosis pada kunjungan ini, jadi tidak ada yang bisa diringkas. Resume menyalin '
                .'diagnosis dari rekam medis, bukan mengetiknya ulang — tegakkan diagnosisnya lebih dulu.'
            );
        }

        $adaUtama = collect($diagnoses)->contains(fn ($d) => $d['rank'] === 'utama');

        if (! $adaUtama) {
            throw new ClinicalException(
                'Diagnosis utama belum ditetapkan. Resume tanpa diagnosis utama tidak menjawab pertanyaan '
                .'pokok fasilitas berikutnya: pasien ini dirawat karena apa.'
            );
        }

        if (blank($summary->dpjp_name)) {
            throw new ClinicalException(
                'Nama DPJP wajib disebut: resume medis adalah pernyataan seorang dokter tentang perawatan '
                .'yang ia pimpin, dan yang mengetiknya bisa orang lain.'
            );
        }

        $summary->update([
            'diagnoses' => $diagnoses,
            'procedures' => $this->freezeProcedures($summary->registration_id),
            'discharge_medications' => $this->freezeMedications($summary->registration_id),
            'discharged_at' => $admisi?->discharged_at ?? $summary->discharged_at,
            'condition_at_discharge' => $this->conditionFrom($admisi),
            'discharge_manner' => $admisi?->discharge_status ?? $summary->discharge_manner,
            'status' => DischargeSummary::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $summary->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(DischargeSummary $summary, string $reason): DischargeSummary
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($summary->status === DischargeSummary::DIBATALKAN) {
            throw new ClinicalException('Resume ini sudah dibatalkan.');
        }

        $summary->update([
            'status' => DischargeSummary::DIBATALKAN,
            'follow_up_instruction' => trim(
                ($summary->follow_up_instruction ? $summary->follow_up_instruction.' ' : '')
                ."[Dibatalkan: {$alasan}]"
            ),
        ]);

        return $summary->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): ?DischargeSummary
    {
        return DischargeSummary::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', DischargeSummary::DIBATALKAN)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Admisi yang pasiennya sudah pulang tapi resumenya belum final.
     *
     * Inilah kegunaan operasionalnya: resume yang tidak pernah selesai
     * menahan berkas rekam medis, menahan klaim, dan membuat pasien
     * pulang tanpa membawa ringkasan perawatannya.
     */
    public function outstanding(int $days = 30): Collection
    {
        $final = DischargeSummary::query()
            ->where('status', DischargeSummary::FINAL)
            ->whereNotNull('admission_id')
            ->pluck('admission_id');

        return collect(DB::table(self::ADMISI)
            ->whereNotNull('discharged_at')
            ->where('discharged_at', '>=', now()->subDays($days))
            ->whereNotIn('admission_id', $final->all())
            ->orderBy('discharged_at')
            ->get());
    }

    // ------------------------------------------------------------ salinan

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeDiagnoses(int $registrationId): array
    {
        return Diagnosis::query()
            ->where('registration_id', $registrationId)
            ->orderByRaw('CASE "rank" WHEN \'utama\' THEN 1 WHEN \'komplikasi\' THEN 2 ELSE 3 END')
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'code' => $d->code,
                'display' => $d->display,
                'rank' => $d->rank,
                'certainty' => $d->certainty,
                'practitioner_name' => $d->practitioner_name,
                'diagnosed_at' => $d->diagnosed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeProcedures(int $registrationId): array
    {
        return Procedure::query()
            ->where('registration_id', $registrationId)
            ->orderBy('performed_at')
            ->get()
            ->map(fn ($p) => [
                'code' => $p->service_code,
                'display' => $p->service_name,
                'quantity' => (float) $p->quantity,
                'practitioner_name' => $p->practitioner_name,
                'performed_at' => $p->performed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Obat yang benar-benar diserahkan farmasi.
     *
     * Yang belum diserahkan sengaja tidak ikut: resep yang diketik dokter
     * tapi tidak pernah ditebus bukan obat yang dibawa pulang pasien, dan
     * mencantumkannya membuat fasilitas berikutnya mengira pasien sedang
     * minum obat yang tidak pernah ia terima.
     *
     * @return array<int, array<string, mixed>>
     */
    private function freezeMedications(int $registrationId): array
    {
        return collect(DB::table(self::RESEP)
            ->where('registration_id', $registrationId)
            ->whereNotNull('dispensed_at')
            ->orderBy('prescribed_at')
            ->get())
            ->map(fn ($item) => [
                'drug_name' => $item->drug_name,
                'kfa_code' => $item->kfa_code,
                'quantity' => (float) ($item->dispensed_quantity ?? 0),
                'unit' => $item->drug_unit,
                'dosage_instruction' => $item->dosage_instruction,
                'prescription_number' => $item->prescription_number,
                'kind' => $item->kind,
            ])
            ->all();
    }

    /**
     * Hidup atau meninggal, DISALIN dari status pulang admisi.
     *
     * Tanpa admisi (resume rawat jalan) tidak ada yang bisa disalin, dan
     * menebaknya "hidup" lebih buruk daripada mengosongkannya.
     */
    private function conditionFrom(?object $admission): ?string
    {
        if ($admission === null || $admission->discharge_status === null) {
            return null;
        }

        return $admission->discharge_status === 'meninggal'
            ? DischargeSummary::MENINGGAL
            : DischargeSummary::HIDUP;
    }

    private function admission(int $registrationId): ?object
    {
        return DB::table(self::ADMISI)
            ->where('registration_id', $registrationId)
            ->orderByDesc('admission_id')
            ->first();
    }
}
