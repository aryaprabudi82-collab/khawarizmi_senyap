<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\AnaesthesiaRecord;
use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Clinical\Models\Operation;
use App\Modules\Clinical\Models\PostoperativeOrder;
use App\Modules\Clinical\Models\RecoveryAssessment;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;

/**
 * Anestesi & pasca-operasi (domain M item M).
 *
 * LIMA ATURAN.
 *
 * 1. MELEKAT PADA OPERASI. catatan_anestesi_sedasi,
 *    penilaian_pre_anestesi, dan catatan_pengkajian_paska_operasi Khanza
 *    berkunci no_rawat saja, sehingga pasien yang dioperasi dua kali
 *    dalam satu perawatan punya catatan yang tidak bisa dibedakan milik
 *    operasi yang mana — dan pada pembedahan ulang karena perdarahan,
 *    perbandingan antara keduanya justru yang paling dicari.
 *
 * 2. SKOR PEMULIHAN TIDAK DISIMPAN ULANG. Aldrete, Bromage, dan Steward
 *    sudah jadi template instrumen sejak item F berikut aturan skornya,
 *    dan form_responses sudah menghitung serta membekukannya sejak item
 *    A. Yang dibuat di sini penyambungnya ke operasi, bukan tabel skor
 *    kedua — Khanza menyimpan label, angka, DAN totalnya sekaligus,
 *    tiga tempat untuk satu kebenaran.
 *
 * 3. SKOR YANG MASIH DRAF BUKAN SKOR. Penilaian pemulihan hanya boleh
 *    menunjuk jawaban formulir yang sudah difinalkan; keputusan
 *    memindahkan pasien dari ruang pulih tidak boleh bersandar pada
 *    angka yang masih bisa berubah.
 *
 * 4. MEMINDAHKAN PASIEN DI BAWAH AMBANG ALDRETE WAJIB BERALASAN, bukan
 *    dilarang. Ambang 8 adalah praktik yang lazim, tapi keputusannya
 *    kebijakan rumah sakit dan pertimbangan dokter anestesi — bukan
 *    aturan yang boleh dikarang program. Yang ditegakkan: alasannya
 *    tercatat, alih-alih dilarang atau lewat tanpa jejak.
 *
 * 5. INSTRUMEN HARUS COCOK DENGAN JENIS ANESTESINYA. Bromage menilai
 *    pulihnya blokade motorik setelah anestesi spinal atau epidural;
 *    memakainya pada anestesi umum menghasilkan angka yang tidak
 *    mengukur apa pun. Ketidakcocokan ditolak dengan menyebut alasannya.
 */
class AnaesthesiaService
{
    /** Instrumen yang wajar dipakai untuk tiap jenis anestesi. */
    private const INSTRUMEN_PER_JENIS = [
        'umum' => [RecoveryAssessment::ALDRETE, RecoveryAssessment::STEWARD],
        'sedasi' => [RecoveryAssessment::ALDRETE, RecoveryAssessment::STEWARD],
        'spinal' => [RecoveryAssessment::BROMAGE, RecoveryAssessment::ALDRETE],
        'epidural' => [RecoveryAssessment::BROMAGE, RecoveryAssessment::ALDRETE],
        'blok-perifer' => [RecoveryAssessment::BROMAGE, RecoveryAssessment::ALDRETE],
        'lokal' => [RecoveryAssessment::ALDRETE],
    ];

    /**
     * Membuka catatan anestesi untuk sebuah operasi.
     *
     * Idempoten: satu operasi satu catatan anestesi.
     *
     * @throws ClinicalException
     */
    public function open(Operation $operation, array $data = [], ?User $actor = null): AnaesthesiaRecord
    {
        $ada = AnaesthesiaRecord::query()
            ->where('operation_id', $operation->id)
            ->where('status', '<>', AnaesthesiaRecord::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        return AnaesthesiaRecord::query()->create([
            'operation_id' => $operation->id,
            'registration_id' => $operation->registration_id,
            'patient_id' => $operation->patient_id,
            'registration_number' => $operation->registration_number,
            'patient_mrn' => $operation->patient_mrn,
            'patient_name' => $operation->patient_name,
            // Disalin dari operasinya, tidak diketik ulang.
            'surgeon_name' => $operation->surgeon_name,
            'procedure_name' => $operation->service_name,
            'anaesthetist_practitioner_id' => $data['anaesthetist_practitioner_id'] ?? null,
            'anaesthetist_name' => $data['anaesthetist_name'] ?? null,
            'anaesthesia_type' => $data['anaesthesia_type'] ?? $this->fromOperation($operation),
            'status' => AnaesthesiaRecord::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function save(AnaesthesiaRecord $record, array $data): AnaesthesiaRecord
    {
        $this->assertEditable($record);

        if (isset($data['anaesthesia_type']) && ! array_key_exists($data['anaesthesia_type'], AnaesthesiaRecord::JENIS)) {
            throw new ClinicalException(
                "Jenis anestesi '{$data['anaesthesia_type']}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(AnaesthesiaRecord::JENIS)).'.'
            );
        }

        if (isset($data['airway']) && ! array_key_exists($data['airway'], AnaesthesiaRecord::JALAN_NAPAS)) {
            throw new ClinicalException("Jenis jalan napas '{$data['airway']}' tidak dikenali.");
        }

        if (array_key_exists('asa_class', $data) && $data['asa_class'] !== null) {
            $this->assertAsa((string) $data['asa_class']);
        }

        $record->update(array_intersect_key($data, array_flip([
            'anaesthetist_practitioner_id', 'anaesthetist_name', 'surgeon_name',
            'pre_op_diagnosis', 'procedure_name', 'post_op_diagnosis',
            'asa_class', 'anaesthesia_type', 'airway', 'airway_note',
            'anaesthesia_start_at', 'surgery_start_at', 'surgery_end_at', 'anaesthesia_end_at',
            'premedication', 'induction_agents', 'maintenance_agents', 'muscle_relaxant',
            'reversal_agents', 'analgesia', 'fluids', 'complications',
        ])));

        return $record->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function finalize(AnaesthesiaRecord $record, ?User $actor = null): AnaesthesiaRecord
    {
        $this->assertEditable($record);

        $kurang = [];

        if (blank($record->anaesthetist_name)) {
            $kurang[] = 'nama dokter anestesi';
        }

        if (blank($record->anaesthesia_type)) {
            $kurang[] = 'jenis anestesi';
        }

        if ($record->anaesthesia_start_at === null) {
            $kurang[] = 'waktu mulai anestesi';
        }

        if ($record->anaesthesia_end_at === null) {
            $kurang[] = 'waktu selesai anestesi';
        }

        if ($kurang !== []) {
            throw new ClinicalException(
                'Belum lengkap: '.implode(', ', $kurang).'. Catatan anestesi tanpa keempatnya tidak '
                .'bisa dipertanggungjawabkan maupun dihitung lamanya.'
            );
        }

        $record->update([
            'status' => AnaesthesiaRecord::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $record->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(AnaesthesiaRecord $record, string $reason): AnaesthesiaRecord
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($record->status === AnaesthesiaRecord::DIBATALKAN) {
            throw new ClinicalException('Catatan anestesi ini sudah dibatalkan.');
        }

        $record->update([
            'status' => AnaesthesiaRecord::DIBATALKAN,
            'complications' => trim(
                ($record->complications ? $record->complications.' ' : '')."[Dibatalkan: {$alasan}]"
            ),
        ]);

        return $record->refresh();
    }

    // ------------------------------------------------------- ruang pulih

    /**
     * Mencatat satu penilaian pemulihan atas operasi ini.
     *
     * @throws ClinicalException
     */
    public function recordRecovery(
        AnaesthesiaRecord $record,
        FormResponse $response,
        array $data = [],
        ?User $actor = null,
    ): RecoveryAssessment {
        if (! array_key_exists($response->template_code, RecoveryAssessment::INSTRUMEN)) {
            throw new ClinicalException(
                "Formulir '{$response->template_code}' bukan instrumen pemulihan pasca anestesi. "
                .'Pilihannya: '.implode(', ', array_keys(RecoveryAssessment::INSTRUMEN)).'.'
            );
        }

        if ($response->registration_id !== $record->registration_id) {
            throw new ClinicalException(
                'Penilaian pemulihan ini milik kunjungan lain. Skor pasien lain yang tersambung ke operasi '
                .'ini akan jadi dasar keputusan memindahkan pasien yang salah.'
            );
        }

        if ($response->status !== FormResponse::FINAL) {
            throw new ClinicalException(
                'Penilaian pemulihan masih draf. Keputusan memindahkan pasien dari ruang pulih tidak boleh '
                .'bersandar pada angka yang masih bisa berubah — finalkan penilaiannya dulu.'
            );
        }

        $this->assertInstrumentFits($record, $response->template_code);

        $keputusan = $data['decision'] ?? null;
        $alasan = trim($data['decision_note'] ?? '');

        if ($keputusan !== null) {
            $this->assertDecision($keputusan, $response, $alasan);
        }

        $urutan = (int) RecoveryAssessment::query()
            ->where('operation_id', $record->operation_id)
            ->where('instrument_code', $response->template_code)
            ->max('sequence') + 1;

        return RecoveryAssessment::query()->create([
            'operation_id' => $record->operation_id,
            'anaesthesia_record_id' => $record->id,
            'form_response_id' => $response->id,
            'instrument_code' => $response->template_code,
            'assessed_at' => $data['assessed_at'] ?? $response->finalized_at ?? now(),
            'sequence' => $urutan,
            'decision' => $keputusan,
            'decision_note' => $alasan !== '' ? $alasan : null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /** Riwayat penilaian pemulihan satu operasi, berikut skornya. */
    public function recoveryTrend(int $operationId): Collection
    {
        return RecoveryAssessment::query()
            ->where('operation_id', $operationId)
            ->with('formResponse')
            ->orderBy('assessed_at')
            ->get();
    }

    // ---------------------------------------------------- instruksi pasca

    /**
     * Mencatat instruksi pasca-operasi.
     *
     * @throws ClinicalException
     */
    public function orderPostoperativeCare(
        Operation $operation,
        array $parts,
        array $data = [],
        ?User $actor = null,
    ): PostoperativeOrder {
        $isi = array_intersect_key($parts, PostoperativeOrder::BAGIAN);
        $terisi = array_filter($isi, fn ($nilai) => filled($nilai));

        if ($terisi === []) {
            throw new ClinicalException(
                'Instruksi pasca-operasi harus mengisi setidaknya satu bagian. Instruksi kosong membuat '
                .'perawat bangsal yang menerima pasien tidak tahu apa yang harus dikerjakan.'
            );
        }

        $penulis = trim($data['practitioner_name'] ?? $actor?->name ?? '');

        if ($penulis === '') {
            throw new ClinicalException(
                'Nama dokter yang memberi instruksi wajib disebut: instruksi pasca-operasi adalah perintah '
                .'seseorang, dan yang menjalankannya berhak tahu dari siapa.'
            );
        }

        return PostoperativeOrder::query()->create($isi + [
            'operation_id' => $operation->id,
            'registration_id' => $operation->registration_id,
            'patient_id' => $operation->patient_id,
            'registration_number' => $operation->registration_number,
            'patient_mrn' => $operation->patient_mrn,
            'patient_name' => $operation->patient_name,
            'ordered_at' => $data['ordered_at'] ?? now(),
            'practitioner_id' => $data['practitioner_id'] ?? null,
            'practitioner_name' => $penulis,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    public function postoperativeOrdersFor(int $operationId): Collection
    {
        return PostoperativeOrder::query()
            ->where('operation_id', $operationId)
            ->orderBy('ordered_at')
            ->get();
    }

    public function forOperation(int $operationId): ?AnaesthesiaRecord
    {
        return AnaesthesiaRecord::query()
            ->where('operation_id', $operationId)
            ->where('status', '<>', AnaesthesiaRecord::DIBATALKAN)
            ->with('recoveryAssessments.formResponse')
            ->first();
    }

    // ------------------------------------------------------------ internal

    /**
     * @throws ClinicalException
     */
    private function assertDecision(string $decision, FormResponse $response, string $note): void
    {
        $sah = [
            RecoveryAssessment::LANJUT_OBSERVASI, RecoveryAssessment::PINDAH_BANGSAL,
            RecoveryAssessment::PINDAH_ICU, RecoveryAssessment::PULANG,
        ];

        if (! in_array($decision, $sah, true)) {
            throw new ClinicalException("Keputusan '{$decision}' tidak dikenali.");
        }

        $keluar = in_array($decision, RecoveryAssessment::KELUAR_RUANG_PULIH, true);
        $aldrete = $response->template_code === RecoveryAssessment::ALDRETE;
        $skor = $response->score;

        // Pindah ke ICU sengaja TIDAK menuntut alasan tambahan: skor rendah
        // memang salah satu alasan pasien dikirim ke ICU, dan meminta
        // pembenaran atas keputusan yang justru lebih aman itu keliru.
        if ($keluar && $aldrete && $skor !== null && $skor < RecoveryAssessment::AMBANG_ALDRETE && $note === '') {
            throw new ClinicalException(sprintf(
                'Skor Aldrete %d masih di bawah %d, yang lazim dipakai sebagai syarat meninggalkan ruang '
                .'pulih. Memindahkan pasien tetap boleh — itu pertimbangan dokter anestesi — tapi '
                .'alasannya wajib dicatat.',
                $skor,
                RecoveryAssessment::AMBANG_ALDRETE,
            ));
        }
    }

    /**
     * @throws ClinicalException
     */
    private function assertInstrumentFits(AnaesthesiaRecord $record, string $instrumentCode): void
    {
        $jenis = $record->anaesthesia_type;

        if ($jenis === null || ! array_key_exists($jenis, self::INSTRUMEN_PER_JENIS)) {
            return;
        }

        if (in_array($instrumentCode, self::INSTRUMEN_PER_JENIS[$jenis], true)) {
            return;
        }

        throw new ClinicalException(sprintf(
            "Instrumen '%s' tidak menilai pemulihan dari anestesi %s. Bromage mengukur pulihnya blokade "
            .'motorik setelah anestesi spinal atau epidural, dan pada anestesi umum angkanya tidak '
            .'mengukur apa pun. Yang sesuai: %s.',
            $instrumentCode,
            $jenis,
            implode(' atau ', self::INSTRUMEN_PER_JENIS[$jenis]),
        ));
    }

    /**
     * @throws ClinicalException
     */
    private function assertAsa(string $asa): void
    {
        $sah = ['1', '2', '3', '4', '5', '6', '1E', '2E', '3E', '4E', '5E'];

        if (! in_array($asa, $sah, true)) {
            throw new ClinicalException(
                "Kelas ASA '{$asa}' tidak dikenali. Pilihannya 1 sampai 6, dengan akhiran E untuk "
                .'operasi darurat (mis. 2E).'
            );
        }
    }

    private function fromOperation(Operation $operation): ?string
    {
        // operasi.anesthesia_type memakai kosakata lama (umum, lokal,
        // regional, tanpa) yang lebih kasar daripada kosakata di sini;
        // yang bisa dipetakan dipetakan, sisanya dibiarkan kosong agar
        // diisi dokter anestesinya sendiri.
        return match ($operation->anesthesia_type) {
            'umum' => 'umum',
            'lokal' => 'lokal',
            default => null,
        };
    }

    /**
     * @throws ClinicalException
     */
    private function assertEditable(AnaesthesiaRecord $record): void
    {
        if ($record->isEditable()) {
            return;
        }

        throw new ClinicalException(
            $record->status === AnaesthesiaRecord::FINAL
                ? 'Catatan anestesi yang sudah difinalkan tidak bisa diubah.'
                : 'Catatan anestesi ini sudah dibatalkan.'
        );
    }
}
