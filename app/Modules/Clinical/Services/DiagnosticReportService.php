<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\DiagnosticReport;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Hasil pemeriksaan penunjang khusus (domain M item G) — 18 kode.
 *
 * BUTIRNYA DARI TEMPLATE, KERANGKANYA DARI TABEL INI. Kedelapan belas
 * tabel Khanza berbagi kerangka yang sama lalu berbeda pada butir khas
 * modalitasnya; butir itu persis bentuk yang sudah ditangani template
 * sejak item A, sedangkan kerangkanya — kesimpulan, pemeriksa, tautan ke
 * permintaan — tidak bisa dititipkan ke form_responses tanpa membebani
 * seluruh formulir lain dengan kolom yang tidak mereka pakai.
 *
 * EMPAT ATURAN:
 *
 * 1. KESIMPULAN WAJIB SAAT DIFINALKAN. Ini pengetatan terhadap Khanza
 *    yang membiarkannya kosong. Hasil tanpa kesimpulan adalah kumpulan
 *    angka yang tidak ditafsirkan siapa pun, dan dokter yang membacanya
 *    kemudian akan menganggap tidak adanya kesimpulan berarti tidak ada
 *    temuan. Ditegakkan service DAN basis data.
 *
 * 2. PEMERIKSA WAJIB DISEBUT. Tafsiran klinis adalah pernyataan
 *    seseorang; hasil yang tidak menyebut siapa yang membacanya tidak bisa
 *    dipertanggungjawabkan, dan tidak bisa ditanyakan kembali saat
 *    tafsirannya diragukan.
 *
 * 3. VERSI TEMPLATE DIBEKUKAN, sama seperti form_responses: butir yang
 *    dijawab tidak boleh berubah saat templatenya direvisi.
 *
 * 4. YANG SUDAH FINAL TIDAK BISA DIUBAH. Ralat dilakukan dengan
 *    membatalkan lalu membuat hasil baru yang menyebut alasannya —
 *    bukan dengan menimpa, karena hasil yang sudah dibaca dokter lain
 *    tidak boleh berubah tanpa jejak.
 */
class DiagnosticReportService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(private readonly FormTemplateContext $templates) {}

    /**
     * Membuka hasil pemeriksaan baru.
     *
     * TIDAK IDEMPOTEN, berbeda dari form_responses. Satu kunjungan bisa
     * punya beberapa EKG — pada pasien nyeri dada, EKG diulang justru
     * untuk melihat perubahannya, dan melanjutkan draf lama akan menimpa
     * rekaman yang menangkap perubahan itu.
     *
     * @throws ClinicalException
     */
    public function open(int $registrationId, string $templateCode, array $data = [], ?User $actor = null): DiagnosticReport
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $template = $this->templates->active($templateCode)
            ?? throw new ClinicalException("Template hasil pemeriksaan '{$templateCode}' tidak ada atau sudah tidak aktif.");

        if ($template->category !== 'hasil-pemeriksaan') {
            throw new ClinicalException(
                "Template '{$templateCode}' bukan template hasil pemeriksaan; kategorinya '{$template->category}'."
            );
        }

        return DiagnosticReport::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'order_id' => $data['order_id'] ?? null,
            'template_code' => $template->code,
            'template_version' => $template->version,
            'template_name' => $template->name,
            'template_approved' => (bool) ($template->is_approved ?? false),
            'modality' => $data['modality'] ?? $template->specialty,
            'clinical_diagnosis' => $data['clinical_diagnosis'] ?? null,
            'referred_from' => $data['referred_from'] ?? $kunjungan->unit_name,
            'findings' => [],
            'performed_by_practitioner_id' => $data['performed_by_practitioner_id'] ?? $kunjungan->practitioner_id,
            'performed_by_name' => $data['performed_by_name'] ?? $kunjungan->practitioner_name,
            'performed_at' => $data['performed_at'] ?? now(),
            'status' => DiagnosticReport::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Menyimpan temuan.
     *
     * @throws ClinicalException
     */
    public function save(DiagnosticReport $report, array $findings, array $data = []): DiagnosticReport
    {
        if (! $report->isEditable()) {
            throw new ClinicalException(
                'Hasil yang sudah difinalkan tidak bisa diubah. Batalkan lalu buat hasil baru bila memang keliru — '
                . 'hasil yang sudah dibaca dokter lain tidak boleh berubah tanpa jejak.'
            );
        }

        $template = $this->frozenTemplate($report);
        $dikenal = $this->templates->questions($template);
        $asing = array_diff(array_keys($findings), array_keys($dikenal));

        if ($asing !== []) {
            throw new ClinicalException(
                'Temuan tidak dikenali template ini: ' . implode(', ', $asing)
                . '. Formulir mungkin dibuka sebelum templatenya berubah — buka ulang.'
            );
        }

        $report->update([
            'findings' => $findings,
            'conclusion' => $data['conclusion'] ?? $report->conclusion,
            'recommendation' => $data['recommendation'] ?? $report->recommendation,
            'clinical_diagnosis' => $data['clinical_diagnosis'] ?? $report->clinical_diagnosis,
            'performed_by_name' => $data['performed_by_name'] ?? $report->performed_by_name,
        ]);

        return $report->refresh();
    }

    /**
     * Mengunci hasil sebagai bagian rekam medis.
     *
     * @throws ClinicalException
     */
    public function finalize(DiagnosticReport $report, ?User $actor = null): DiagnosticReport
    {
        if ($report->status === DiagnosticReport::FINAL) {
            throw new ClinicalException('Hasil ini sudah difinalkan.');
        }

        if ($report->status === DiagnosticReport::DIBATALKAN) {
            throw new ClinicalException('Hasil yang dibatalkan tidak bisa difinalkan.');
        }

        if (blank($report->conclusion)) {
            throw new ClinicalException(
                'Kesimpulan wajib diisi sebelum hasil difinalkan. Hasil tanpa kesimpulan adalah kumpulan '
                . 'angka yang tidak ditafsirkan siapa pun, dan pembacanya akan menganggap tidak ada temuan.'
            );
        }

        if (blank($report->performed_by_name)) {
            throw new ClinicalException(
                'Nama pemeriksa wajib disebut: tafsiran klinis adalah pernyataan seseorang, dan hasil yang '
                . 'tidak menyebut pembacanya tidak bisa dipertanggungjawabkan.'
            );
        }

        $report->update([
            'status' => DiagnosticReport::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $report->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(DiagnosticReport $report, string $reason): DiagnosticReport
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($report->status === DiagnosticReport::DIBATALKAN) {
            throw new ClinicalException('Hasil ini sudah dibatalkan.');
        }

        $report->update([
            'status' => DiagnosticReport::DIBATALKAN,
            'recommendation' => trim(($report->recommendation ? $report->recommendation . ' ' : '') . "[Dibatalkan: {$alasan}]"),
        ]);

        return $report->refresh();
    }

    // ---------------------------------------------------------------- baca

    /** Hasil final satu kunjungan. */
    public function finalizedFor(int $registrationId, ?string $modality = null): Collection
    {
        return DiagnosticReport::query()
            ->where('registration_id', $registrationId)
            ->where('status', DiagnosticReport::FINAL)
            ->when($modality, fn ($q) => $q->where('modality', $modality))
            ->orderBy('performed_at')
            ->get();
    }

    /**
     * Riwayat satu jenis pemeriksaan untuk seorang pasien.
     *
     * Inilah yang membuat pemeriksaan berulang berguna: EKG hari ini
     * hanya berarti bila dibandingkan dengan EKG sebelumnya.
     */
    public function historyFor(int $patientId, string $templateCode, int $limit = 20): Collection
    {
        return DiagnosticReport::query()
            ->where('patient_id', $patientId)
            ->where('template_code', $templateCode)
            ->where('status', DiagnosticReport::FINAL)
            ->orderByDesc('performed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Hasil yang masih draf lebih lama dari sekian jam.
     *
     * Pemeriksaan yang sudah dikerjakan tapi hasilnya tidak pernah
     * difinalkan adalah pekerjaan yang hilang: pasien sudah menjalani
     * pemeriksaannya, dan tidak ada yang bisa membacanya.
     */
    public function stalledDrafts(int $hours = 24): Collection
    {
        return DiagnosticReport::query()
            ->where('status', DiagnosticReport::DRAF)
            ->where('performed_at', '<', now()->subHours($hours))
            ->orderBy('performed_at')
            ->get();
    }

    /** Template hasil pemeriksaan yang tersedia. */
    public function availableTemplates(): Collection
    {
        return $this->templates->actives('hasil-pemeriksaan');
    }

    /**
     * @throws ClinicalException
     */
    private function frozenTemplate(DiagnosticReport $report): stdClass
    {
        return $this->templates->version($report->template_code, $report->template_version)
            ?? throw new ClinicalException(
                "Versi {$report->template_version} template '{$report->template_code}' tidak ditemukan. "
                . 'Versi template tidak boleh dihapus: hasil yang menunjuknya kehilangan pertanyaannya.'
            );
    }
}
