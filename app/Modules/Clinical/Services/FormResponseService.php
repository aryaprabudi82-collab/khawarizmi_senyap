<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pengisian formulir asesmen & skrining (domain M item A).
 *
 * Menaungi dua kelompok terbesar domain M sekaligus — ~40 kode penilaian
 * awal per spesialisasi dan ~35 kode skrining — karena perbuatannya satu:
 * mengisi formulir terstruktur tentang seorang pasien pada satu kunjungan.
 *
 * EMPAT ATURAN YANG MEMBENTUK KELAS INI:
 *
 * 1. VERSI TEMPLATE DIBEKUKAN SAAT DIBUKA, bukan dibaca ulang saat
 *    ditampilkan. Formulir yang diisi hari ini tetap berisi pertanyaan
 *    hari ini, sekalipun templatenya direvisi besok. Tanpa ini, isi rekam
 *    medis berubah tanpa ada yang menyentuhnya.
 *
 * 2. SKOR DIHITUNG DARI TEMPLATE YANG DIPAKAI, LALU DISIMPAN. Ini
 *    KEBALIKAN dari aturan durasi pada indikator mutu (domain J item D),
 *    dan bedanya bukan kelalaian: durasi diturunkan dari dua stempel waktu
 *    yang keduanya fakta tercatat, sehingga menghitung ulang selalu
 *    memberi jawaban sama; skor diturunkan dari PEDOMAN yang bisa
 *    direvisi, sehingga menghitung ulang akan mengubah penilaian klinis
 *    yang sudah dinyatakan seseorang.
 *
 * 3. JAWABAN DI LUAR PERTANYAAN TEMPLATE DITOLAK. Jawaban yang tidak
 *    punya pertanyaan tidak bisa ditafsirkan siapa pun, dan yang paling
 *    sering menyebabkannya adalah formulir lama yang dikirim setelah
 *    templatenya berubah — persis keadaan yang harus ketahuan, bukan
 *    disimpan diam-diam.
 *
 * 4. DRAF BUKAN REKAM MEDIS. Hanya yang difinalisasi dianggap pernyataan
 *    klinis; yang masih draf boleh diubah bebas dan tidak ikut dilaporkan.
 *    Aturan yang sama seperti asesmen SOAP.
 */
class FormResponseService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(private readonly FormTemplateContext $templates) {}

    /**
     * Membuka formulir baru untuk satu kunjungan.
     *
     * Idempoten: membuka formulir yang sama dua kali melanjutkan draf yang
     * sudah ada, bukan membuat baris kedua — dua asesmen awal atas
     * kunjungan yang sama berarti dua penilaian yang bisa bertentangan
     * tanpa ada yang tahu mana yang berlaku.
     *
     * @throws ClinicalException
     */
    public function open(int $registrationId, string $templateCode, ?User $actor = null): FormResponse
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $berjalan = FormResponse::query()
            ->where('registration_id', $registrationId)
            ->where('template_code', $templateCode)
            ->where('status', '<>', FormResponse::DIBATALKAN)
            ->first();

        if ($berjalan !== null) {
            return $berjalan;
        }

        $template = $this->activeTemplate($templateCode);

        return FormResponse::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'template_code' => $template->code,
            // Dibekukan di sini, bukan dibaca ulang nanti.
            'template_version' => $template->version,
            'template_name' => $template->name,
            'category' => $template->category,
            'answers' => [],
            'status' => FormResponse::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Menyimpan jawaban. Skor dihitung ulang dari template yang DIPAKAI
     * formulir ini, bukan dari versi terbarunya.
     *
     * @throws ClinicalException
     */
    public function save(FormResponse $response, array $answers, ?string $note = null): FormResponse
    {
        if (! $response->isEditable()) {
            throw new ClinicalException(
                'Formulir yang sudah difinalisasi tidak bisa diubah. Batalkan dan isi ulang bila memang keliru.'
            );
        }

        $template = $this->frozenTemplate($response);

        $this->assertAnswersBelongToTemplate($answers, $template);

        $penilaian = $this->score($template, $answers);

        $response->update([
            'answers' => $answers,
            'note' => $note ?? $response->note,
            'score' => $penilaian['score'],
            'interpretation' => $penilaian['interpretation'],
            'risk_level' => $penilaian['risk_level'],
        ]);

        return $response->refresh();
    }

    /**
     * Mengunci formulir sebagai bagian rekam medis.
     *
     * @throws ClinicalException
     */
    public function finalize(FormResponse $response, ?User $actor = null): FormResponse
    {
        if ($response->status === FormResponse::FINAL) {
            throw new ClinicalException('Formulir ini sudah difinalisasi.');
        }

        if ($response->status === FormResponse::DIBATALKAN) {
            throw new ClinicalException('Formulir yang dibatalkan tidak bisa difinalisasi.');
        }

        $template = $this->frozenTemplate($response);
        $kurang = $this->missingRequired($template, $response->answers ?? []);

        if ($kurang !== []) {
            throw new ClinicalException(
                'Pertanyaan wajib belum dijawab: ' . implode(', ', $kurang) . '.'
            );
        }

        $response->update([
            'status' => FormResponse::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $response->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(FormResponse $response, string $reason): FormResponse
    {
        if ($response->status === FormResponse::DIBATALKAN) {
            throw new ClinicalException('Formulir ini sudah dibatalkan.');
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        $response->update([
            'status' => FormResponse::DIBATALKAN,
            'note' => trim(($response->note ? $response->note . ' ' : '') . "[Dibatalkan: {$alasan}]"),
        ]);

        return $response->refresh();
    }

    // ---------------------------------------------------------------- baca

    /** Formulir final satu kunjungan. */
    public function finalizedFor(int $registrationId, ?string $category = null): Collection
    {
        return FormResponse::query()
            ->where('registration_id', $registrationId)
            ->where('status', FormResponse::FINAL)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderBy('finalized_at')
            ->get();
    }

    /**
     * Riwayat satu jenis formulir untuk seorang pasien, lintas kunjungan.
     *
     * Inilah yang membuat skrining berulang berguna: skor gizi hari ini
     * hanya berarti bila dibandingkan dengan skor sebelumnya.
     */
    public function historyFor(int $patientId, string $templateCode, int $limit = 20): Collection
    {
        return FormResponse::query()
            ->where('patient_id', $patientId)
            ->where('template_code', $templateCode)
            ->where('status', FormResponse::FINAL)
            ->orderByDesc('finalized_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Template yang tersedia untuk satu kunjungan, berikut penanda mana
     * yang SUDAH diisi.
     *
     * "Belum diskrining" dan "sudah diskrining, hasilnya negatif" adalah
     * dua pernyataan berbeda, dan daftar ini yang membuat perbedaannya
     * terlihat di layar.
     */
    public function checklistFor(int $registrationId, ?string $category = null): Collection
    {
        $terisi = FormResponse::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', FormResponse::DIBATALKAN)
            ->get()
            ->keyBy('template_code');

        return $this->templates->actives($category)
            ->map(fn ($t) => (object) [
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category,
                'specialty' => $t->specialty,
                'status' => $terisi->get($t->code)?->status,
                'score' => $terisi->get($t->code)?->score,
                'risk_level' => $terisi->get($t->code)?->risk_level,
            ]);
    }

    // -------------------------------------------------------------- privat

    /**
     * @throws ClinicalException
     */
    private function activeTemplate(string $code): stdClass
    {
        return $this->templates->active($code)
            ?? throw new ClinicalException("Template formulir '{$code}' tidak ada atau sudah tidak aktif.");
    }

    /**
     * Template PERSIS versi yang dipakai formulir ini — bukan versi
     * terbarunya.
     *
     * @throws ClinicalException
     */
    private function frozenTemplate(FormResponse $response): stdClass
    {
        return $this->templates->version($response->template_code, $response->template_version)
            ?? throw new ClinicalException(
                "Versi {$response->template_version} template '{$response->template_code}' tidak ditemukan. "
                . 'Versi template tidak boleh dihapus: rekam medis yang menunjuknya kehilangan pertanyaannya.'
            );
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws ClinicalException
     */
    private function assertAnswersBelongToTemplate(array $answers, stdClass $template): void
    {
        $dikenal = $this->templates->questions($template);
        $asing = array_diff(array_keys($answers), array_keys($dikenal));

        if ($asing !== []) {
            throw new ClinicalException(
                'Jawaban tidak dikenali template ini: ' . implode(', ', $asing)
                . '. Formulir mungkin dibuka sebelum templatenya berubah — buka ulang.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<int, string>
     */
    private function missingRequired(stdClass $template, array $answers): array
    {
        $kurang = [];

        foreach ($this->templates->questions($template) as $kunci => $pertanyaan) {
            if (empty($pertanyaan['required'])) {
                continue;
            }

            $nilai = $answers[$kunci] ?? null;

            // Nol dan "false" adalah jawaban yang sah — hanya null dan
            // string kosong yang berarti belum dijawab. Memakai empty()
            // di sini akan menolak skor nyeri 0, yang justru jawaban yang
            // paling sering benar.
            if ($nilai === null || $nilai === '' || $nilai === []) {
                $kurang[] = $pertanyaan['label'] ?? $kunci;
            }
        }

        return $kurang;
    }

    /**
     * Menghitung skor dan menafsirkannya menurut template yang dipakai.
     *
     * @param  array<string, mixed>  $answers
     * @return array{score: ?int, interpretation: ?string, risk_level: ?string}
     */
    private function score(stdClass $template, array $answers): array
    {
        $aturan = $this->templates->scoring($template);

        if (empty($aturan['bands'] ?? [])) {
            return ['score' => null, 'interpretation' => null, 'risk_level' => null];
        }

        $total = 0;

        foreach ($this->templates->questions($template) as $kunci => $pertanyaan) {
            $jawab = $answers[$kunci] ?? null;

            if ($jawab === null) {
                continue;
            }

            $total += $this->bobot($pertanyaan, $jawab);
        }

        foreach ($aturan['bands'] as $band) {
            $min = $band['min'] ?? PHP_INT_MIN;
            $max = $band['max'] ?? PHP_INT_MAX;

            if ($total >= $min && $total <= $max) {
                return [
                    'score' => $total,
                    'interpretation' => $band['interpretation'] ?? null,
                    'risk_level' => $band['risk_level'] ?? null,
                ];
            }
        }

        // Skor di luar seluruh rentang berarti templatenya sendiri tidak
        // lengkap. Skornya tetap disimpan — yang hilang cuma tafsirnya,
        // dan itu lebih baik daripada menyembunyikan angkanya.
        return ['score' => $total, 'interpretation' => null, 'risk_level' => null];
    }

    /**
     * @param  array<string, mixed>  $pertanyaan
     */
    private function bobot(array $pertanyaan, mixed $jawab): int
    {
        // Pilihan berbobot: bobotnya menempel pada pilihan yang dipilih.
        foreach ($pertanyaan['options'] ?? [] as $pilihan) {
            if (($pilihan['value'] ?? null) === $jawab) {
                return (int) ($pilihan['score'] ?? 0);
            }
        }

        // Pertanyaan ya/tidak dengan satu bobot.
        if (isset($pertanyaan['score']) && ($jawab === true || $jawab === 'ya' || $jawab === 1)) {
            return (int) $pertanyaan['score'];
        }

        // Jawaban berupa angka yang langsung jadi skornya.
        if (($pertanyaan['type'] ?? null) === 'number' && ! empty($pertanyaan['score_is_value']) && is_numeric($jawab)) {
            return (int) $jawab;
        }

        return 0;
    }
}
