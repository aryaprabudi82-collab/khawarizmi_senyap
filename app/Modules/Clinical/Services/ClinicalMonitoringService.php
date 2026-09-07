<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\DengueMonitoring;
use App\Modules\Clinical\Models\GlucoseMonitoring;
use App\Modules\Clinical\Models\Procedure;
use App\Modules\Clinical\Models\ProcedureReport;
use App\Modules\Clinical\Models\TransfusionMonitoring;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan tindakan & pemantauan berkala (domain M item R).
 *
 * LIMA ATURAN.
 *
 * 1. LAPORAN TINDAKAN MELEKAT PADA TINDAKANNYA, bukan pada kunjungan —
 *    aturan yang sama dengan catatan anestesi pada item M.
 *
 * 2. KESIMPULAN WAJIB SAAT LAPORAN DIFINALKAN, sama seperti hasil
 *    penunjang pada item G: uraian tanpa kesimpulan adalah cerita yang
 *    tidak ditafsirkan siapa pun.
 *
 * 3. NILAI PEMANTAUAN DBD MENYEBUT SUMBERNYA, dan perbandingan
 *    hematokrit hanya dilakukan antar sumber yang sama. Keputusan pada
 *    demam berdarah bersandar pada kenaikan 20 persen; kenaikan yang
 *    sebenarnya berasal dari pergantian alat tidak boleh terbaca
 *    sebagai perburukan pasien.
 *
 * 4. REAKSI TRANSFUSI YANG DINYATAKAN TERJADI WAJIB MENYEBUT TANDA DAN
 *    TINDAKANNYA. Transfusi yang bereaksi harus dihentikan, dan catatan
 *    tanpa tindakan tidak membuktikan itu dilakukan.
 *
 * 5. ANGKA YANG MENUNTUT TINDAKAN DISEBUTKAN, TIDAK DITAHAN. Perawat
 *    yang menemukan gula darah 45 harus bisa mencatatnya SEKARANG lalu
 *    bertindak; formulir yang menahan pencatatan sampai tindakannya
 *    diketik justru menunda pertolongannya.
 */
class ClinicalMonitoringService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Kantong darah yang benar-benar dikeluarkan UTD (domain N item B).
     *
     * Saat item R ditulis, kontrak ini belum ada dan nomor kantong
     * disimpan apa adanya dengan catatan terang-terangan bahwa ia belum
     * bisa diperiksa. Sekarang bisa — dan rantai dari donor sampai
     * pasien tersambung utuh.
     */
    private const KANTONG_KELUAR = 'blood.v_issued_unit';

    // ------------------------------------------------------ laporan tindakan

    /**
     * @throws ClinicalException
     */
    public function openReport(Procedure $procedure, array $data = [], ?User $actor = null): ProcedureReport
    {
        $ada = ProcedureReport::query()
            ->where('procedure_id', $procedure->id)
            ->where('status', '<>', ProcedureReport::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        $pelaksana = trim($data['practitioner_name'] ?? $procedure->practitioner_name ?? $actor?->name ?? '');

        if ($pelaksana === '') {
            throw new ClinicalException(
                'Nama pelaksana tindakan wajib disebut: laporan tindakan adalah pernyataan seseorang '
                .'tentang apa yang ia kerjakan.'
            );
        }

        return ProcedureReport::query()->create([
            'procedure_id' => $procedure->id,
            'registration_id' => $procedure->registration_id,
            'patient_id' => $procedure->patient_id,
            'registration_number' => $procedure->registration_number,
            'patient_mrn' => $procedure->patient_mrn,
            'patient_name' => $procedure->patient_name,
            'reported_at' => $data['reported_at'] ?? now(),
            'practitioner_id' => $data['practitioner_id'] ?? $procedure->practitioner_id,
            'practitioner_name' => $pelaksana,
            'assistant_name' => $data['assistant_name'] ?? null,
            // Disalin dari tindakannya, tidak diketik ulang.
            'procedure_name' => $data['procedure_name'] ?? $procedure->service_name,
            'pre_procedure_diagnosis' => $data['pre_procedure_diagnosis'] ?? null,
            'description' => $data['description'] ?? '',
            'conclusion' => $data['conclusion'] ?? '',
            'status' => ProcedureReport::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function saveReport(ProcedureReport $report, array $data): ProcedureReport
    {
        if (! $report->isEditable()) {
            throw new ClinicalException(
                'Laporan tindakan yang sudah difinalkan tidak bisa diubah. Batalkan lalu buat laporan '
                .'baru bila memang keliru.'
            );
        }

        $report->update(array_intersect_key($data, array_flip([
            'practitioner_name', 'assistant_name', 'procedure_name',
            'pre_procedure_diagnosis', 'post_procedure_diagnosis',
            'description', 'findings', 'conclusion', 'recommendation', 'complications',
        ])));

        return $report->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function finalizeReport(ProcedureReport $report, ?User $actor = null): ProcedureReport
    {
        if (! $report->isEditable()) {
            throw new ClinicalException(
                $report->status === ProcedureReport::FINAL
                    ? 'Laporan ini sudah difinalkan.'
                    : 'Laporan yang dibatalkan tidak bisa difinalkan.'
            );
        }

        if (blank($report->description)) {
            throw new ClinicalException('Uraian tindakan wajib diisi sebelum laporan difinalkan.');
        }

        if (blank($report->conclusion)) {
            throw new ClinicalException(
                'Kesimpulan wajib diisi sebelum laporan difinalkan. Uraian tanpa kesimpulan adalah cerita '
                .'yang tidak ditafsirkan siapa pun, dan pembacanya akan menganggap tidak ada temuan.'
            );
        }

        $report->update([
            'status' => ProcedureReport::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $report->refresh();
    }

    // -------------------------------------------------------- transfusi

    /**
     * @throws ClinicalException
     */
    public function monitorTransfusion(int $registrationId, array $data, ?User $actor = null): TransfusionMonitoring
    {
        $kunjungan = $this->registration($registrationId);

        $tahap = $data['phase'] ?? '';

        if (! array_key_exists($tahap, TransfusionMonitoring::TAHAP)) {
            throw new ClinicalException(
                "Tahap pemantauan '{$tahap}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(TransfusionMonitoring::TAHAP)).'.'
            );
        }

        $kantong = trim($data['bag_number'] ?? '');

        if ($kantong === '') {
            throw new ClinicalException(
                'Nomor kantong wajib diisi. Reaksi transfusi hanya bisa ditelusuri sampai ke donornya '
                .'lewat nomor kantong.'
            );
        }

        $dikeluarkan = $this->issuedUnit($kantong, $registrationId);

        $tanda = $this->validSigns($data['reaction_signs'] ?? []);
        $adaReaksi = $data['reaction_occurred'] ?? null;
        $tindakan = trim($data['action_taken'] ?? '');

        if ($adaReaksi === true) {
            if ($tanda === []) {
                throw new ClinicalException(
                    'Reaksi yang dinyatakan terjadi wajib menyebut tandanya. Tanpa itu tidak bisa '
                    .'dibedakan reaksi alergi ringan dari reaksi hemolitik yang mengancam nyawa.'
                );
            }

            if ($tindakan === '') {
                throw new ClinicalException(
                    'Tindakan atas reaksi wajib dicatat. Transfusi yang bereaksi harus dihentikan, dan '
                    .'catatan tanpa tindakan tidak membuktikan itu dilakukan.'
                );
            }
        }

        $pengamat = trim($data['observed_by_name'] ?? $actor?->name ?? '');

        if ($pengamat === '') {
            throw new ClinicalException('Nama pengamat wajib dicatat.');
        }

        return TransfusionMonitoring::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            // Jenis komponen disalin dari kantong yang dikeluarkan bila
            // kantongnya dikenali — bukan diketik ulang, karena "PRC"
            // yang diketik pada kantong yang sebenarnya trombosit adalah
            // kesalahan yang tidak akan ketahuan sampai pasien bereaksi.
            'blood_product' => $dikeluarkan?->component ?? $data['blood_product'] ?? 'Tidak disebutkan',
            'bag_number' => $kantong,
            'insertion_site' => $data['insertion_site'] ?? null,
            'observed_at' => $data['observed_at'] ?? now(),
            'phase' => $tahap,
            // NULL berarti belum dinilai, bukan "tidak ada reaksi".
            'reaction_occurred' => $adaReaksi,
            'reaction_signs' => $tanda,
            'reaction_severity' => $data['reaction_severity'] ?? null,
            'action_taken' => $tindakan !== '' ? $tindakan : null,
            'observed_by' => $actor?->id,
            'observed_by_name' => $pengamat,
            'note' => $data['note'] ?? null,
        ]);
    }

    /** Pemantauan satu kantong darah, berurutan menurut tahapnya. */
    public function transfusionTrail(string $bagNumber): Collection
    {
        return TransfusionMonitoring::query()
            ->where('bag_number', $bagNumber)
            ->orderBy('observed_at')
            ->get();
    }

    // -------------------------------------------------------------- DBD

    /**
     * @throws ClinicalException
     */
    public function monitorDengue(int $registrationId, array $data, ?User $actor = null): DengueMonitoring
    {
        $kunjungan = $this->registration($registrationId);

        $sumber = $data['source'] ?? '';

        if (! in_array($sumber, [DengueMonitoring::LABORATORIUM, DengueMonitoring::POINT_OF_CARE], true)) {
            throw new ClinicalException(
                "Sumber nilai '{$sumber}' tidak dikenali. Pilihannya: laboratorium, point-of-care."
            );
        }

        if ($sumber === DengueMonitoring::LABORATORIUM && empty($data['order_id'])) {
            throw new ClinicalException(
                'Nilai yang berasal dari laboratorium wajib menunjuk permintaan penunjangnya. Bila '
                .'diperiksa di samping tempat tidur, sumbernya point-of-care.'
            );
        }

        $nilai = array_intersect_key($data, array_flip([
            'haemoglobin_g_dl', 'haematocrit_percent', 'leucocytes_per_ul', 'platelets_per_ul',
        ]));

        if (array_filter($nilai, fn ($v) => $v !== null) === []) {
            throw new ClinicalException(
                'Setidaknya satu nilai wajib diisi. Pemantauan tanpa satu pun angka bukan pemantauan.'
            );
        }

        return DengueMonitoring::query()->create($nilai + [
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'observed_at' => $data['observed_at'] ?? now(),
            'source' => $sumber,
            'order_id' => $data['order_id'] ?? null,
            'fluid_therapy' => $data['fluid_therapy'] ?? null,
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => trim($data['recorded_by_name'] ?? $actor?->name ?? 'Tidak disebutkan'),
        ]);
    }

    /**
     * Deret pemantauan DBD satu kunjungan dari SUMBER yang sama.
     *
     * Disaring per sumber dengan sengaja: deret yang mencampur
     * laboratorium dan point-of-care tidak bisa dipakai menilai
     * kenaikan hematokrit.
     */
    public function dengueTrend(int $registrationId, string $source): Collection
    {
        return DengueMonitoring::query()
            ->where('registration_id', $registrationId)
            ->where('source', $source)
            ->orderBy('observed_at')
            ->get();
    }

    // ------------------------------------------------------------- GDS

    /**
     * @throws ClinicalException
     */
    public function recordGlucose(int $registrationId, array $data, ?User $actor = null): GlucoseMonitoring
    {
        $kunjungan = $this->registration($registrationId);

        $waktu = $data['timing'] ?? '';

        if (! array_key_exists($waktu, GlucoseMonitoring::WAKTU)) {
            throw new ClinicalException(
                "Waktu pemeriksaan '{$waktu}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(GlucoseMonitoring::WAKTU)).'.'
            );
        }

        $angka = $data['glucose_mg_dl'] ?? null;

        if (! is_int($angka) || $angka < 10 || $angka > 1500) {
            throw new ClinicalException(
                'Gula darah harus bilangan bulat 10 sampai 1500 mg/dL. Salah ketik pada angka ini '
                .'berujung pada dosis insulin yang salah.'
            );
        }

        if (filled($data['insulin'] ?? null) && blank($data['insulin_dose_unit'] ?? null)) {
            throw new ClinicalException(
                'Dosis insulin wajib disebut. "Diberi insulin" tanpa unitnya bukan instruksi yang bisa '
                .'dijalankan maupun ditelusuri.'
            );
        }

        return GlucoseMonitoring::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'checked_at' => $data['checked_at'] ?? now(),
            'timing' => $waktu,
            'glucose_mg_dl' => $angka,
            'source' => $data['source'] ?? GlucoseMonitoring::POINT_OF_CARE,
            'insulin' => $data['insulin'] ?? null,
            'insulin_dose_unit' => $data['insulin_dose_unit'] ?? null,
            'oral_agent' => $data['oral_agent'] ?? null,
            'action_taken' => $data['action_taken'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => trim($data['recorded_by_name'] ?? $actor?->name ?? 'Tidak disebutkan'),
        ]);
    }

    /**
     * Pemeriksaan gula darah di luar rentang yang belum ada tindakannya.
     *
     * Disebutkan, bukan ditolak saat pencatatan — lihat aturan 5 pada
     * catatan kelas.
     */
    public function unactionedGlucose(?int $registrationId = null): Collection
    {
        return GlucoseMonitoring::query()
            ->when($registrationId, fn ($q) => $q->where('registration_id', $registrationId))
            ->where(function ($q) {
                $q->where('glucose_mg_dl', '<', GlucoseMonitoring::AMBANG_HIPOGLIKEMIA)
                    ->orWhere('glucose_mg_dl', '>', GlucoseMonitoring::AMBANG_HIPERGLIKEMIA);
            })
            ->orderBy('checked_at')
            ->get()
            ->filter(fn (GlucoseMonitoring $g) => $g->isUnactioned())
            ->values();
    }

    // ------------------------------------------------------------ internal

    /**
     * Mencocokkan nomor kantong dengan kantong yang benar-benar
     * dikeluarkan untuk kunjungan ini.
     *
     * DUA KESALAHAN YANG DITANGKAP, dan keduanya berbeda beratnya:
     *
     * Kantong yang tidak dikenal sama sekali DITOLAK — nomor yang salah
     * ketik membuat reaksi transfusi tidak bisa ditelusuri sampai ke
     * donornya, dan reaksi yang tidak bisa ditelusuri adalah reaksi yang
     * tidak bisa dicegah terulang.
     *
     * Kantong milik pasien LAIN juga ditolak, dan ini yang lebih gawat:
     * pemantauan yang menempel pada kantong pasien lain menandakan salah
     * satu dari dua hal — nomornya keliru dicatat, atau darahnya
     * benar-benar dipasang pada pasien yang salah. Keduanya menuntut
     * pemeriksaan segera, bukan disimpan diam-diam.
     *
     * @throws ClinicalException
     */
    private function issuedUnit(string $bagNumber, int $registrationId): ?object
    {
        $keluar = DB::table(self::KANTONG_KELUAR)->where('unit_number', $bagNumber)->first();

        if ($keluar === null) {
            throw new ClinicalException(
                "Nomor kantong '{$bagNumber}' tidak ditemukan pada daftar kantong yang dikeluarkan unit "
                .'transfusi darah. Reaksi transfusi hanya bisa ditelusuri sampai ke donornya lewat nomor '
                .'kantong yang benar.'
            );
        }

        if ($keluar->registration_id !== null && $keluar->registration_id !== $registrationId) {
            throw new ClinicalException(sprintf(
                "Kantong '%s' dikeluarkan untuk %s, bukan pasien pada kunjungan ini. Periksa segera: "
                .'entah nomornya keliru dicatat, entah darahnya dipasang pada pasien yang salah.',
                $bagNumber,
                $keluar->patient_name,
            ));
        }

        return $keluar;
    }

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validSigns($nilai): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException('Tanda reaksi harus berupa daftar.');
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys(TransfusionMonitoring::TANDA_REAKSI));

        if ($asing !== []) {
            throw new ClinicalException(
                'Tanda reaksi tidak dikenali: '.implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys(TransfusionMonitoring::TANDA_REAKSI)).'.'
            );
        }

        return $bersih;
    }

    /**
     * @throws ClinicalException
     */
    private function registration(int $registrationId): object
    {
        return DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');
    }
}
