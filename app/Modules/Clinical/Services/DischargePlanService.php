<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\DischargePlan;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Perencanaan pemulangan (domain M item H).
 *
 * TIGA ATURAN.
 *
 * 1. BANTUAN YANG DIBUTUHKAN ADALAH DAFTAR. Khanza memakai enum MySQL
 *    untuk bantuan_diperlukan_dalam, artinya satu pilihan saja — pasien
 *    stroke yang butuh bantuan mandi, berpakaian, sekaligus minum obat
 *    harus memilih salah satunya. Di sini bentuknya daftar, dan isinya
 *    tetap kosakata tertutup supaya bisa dihitung.
 *
 * 2. "BELUM DITANYAKAN" BUKAN "TIDAK". Pertanyaan pengaruh rawat inap
 *    terhadap keluarga, pekerjaan, dan keuangan boleh kosong. Menyimpan
 *    false untuk yang belum sempat ditanyakan menghasilkan perencanaan
 *    yang tampak lengkap padahal belum dikerjakan — aturan yang sama
 *    dengan pertanyaan kecelakaan pada domain L.
 *
 * 3. RENCANA DISUSUN SEJAK AWAL. Standar akreditasi menghendaki
 *    perencanaan pemulangan disusun pada hari-hari pertama rawat inap,
 *    bukan di hari kepulangan, supaya keluarga punya waktu menyiapkan
 *    perawatan di rumah. Service ini tidak MELARANG rencana yang telat —
 *    melarangnya hanya membuat rencana telat tidak dicatat sama sekali —
 *    tapi menghitung keterlambatannya dan bisa menyebutkan admisi mana
 *    yang sudah lewat tenggat tanpa rencana.
 */
class DischargePlanService
{
    private const ADMISI = 'inpatient.v_admission_summary';

    /**
     * Membuka rencana pulang untuk satu admisi.
     *
     * Idempoten: satu admisi satu rencana. Rencana pulang bukan catatan
     * berulang melainkan dokumen yang tumbuh — dilengkapi sepanjang
     * perawatan, bukan dibuat ulang tiap kali ditinjau.
     *
     * @throws ClinicalException
     */
    public function open(int $admissionId, ?User $actor = null): DischargePlan
    {
        $ada = DischargePlan::query()
            ->where('admission_id', $admissionId)
            ->where('status', '<>', DischargePlan::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        $admisi = DB::table(self::ADMISI)->where('admission_id', $admissionId)->first()
            ?? throw new ClinicalException('Admisi tidak ditemukan.');

        return DischargePlan::query()->create([
            'admission_id' => $admissionId,
            'registration_id' => $admisi->registration_id,
            'patient_id' => $admisi->patient_id,
            'admission_number' => $admisi->admission_number,
            'patient_mrn' => $admisi->patient_mrn,
            'patient_name' => $admisi->patient_name,
            'admitted_at' => $admisi->admitted_at,
            'assistance_needed' => [],
            'status' => DischargePlan::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Melengkapi rencana.
     *
     * @throws ClinicalException
     */
    public function save(DischargePlan $plan, array $data): DischargePlan
    {
        if (! $plan->isEditable()) {
            throw new ClinicalException(
                'Rencana pulang yang sudah difinalkan tidak bisa diubah. Batalkan lalu susun ulang bila '
                .'rencananya memang berubah — keluarga sudah diberi tahu isi yang lama.'
            );
        }

        $isi = array_intersect_key($data, array_flip([
            'planned_discharge_on', 'estimated_care_days', 'admission_reason', 'medical_diagnosis',
            'affects_family', 'affects_family_note',
            'affects_work_or_school', 'affects_work_or_school_note',
            'affects_finance', 'affects_finance_note',
            'anticipated_problems', 'anticipated_problems_note',
            'assistance_note', 'education_given', 'caregiver_name', 'caregiver_relation',
        ]));

        if (array_key_exists('assistance_needed', $data)) {
            $isi['assistance_needed'] = $this->validAssistance($data['assistance_needed']);
        }

        $plan->update($isi);

        return $plan->refresh();
    }

    /**
     * Menutup rencana sebagai dokumen yang sudah dibicarakan dengan keluarga.
     *
     * NAMA KELUARGA WAJIB. Perencanaan pemulangan yang tidak menyebut
     * siapa yang akan merawat di rumah adalah rencana yang tidak pernah
     * diserahkan kepada siapa pun — itulah yang dicatat Khanza sebagai
     * bukti_perencanaan_pemulangan_saksikeluarga, dan di sini jadi syarat.
     *
     * @throws ClinicalException
     */
    public function finalize(DischargePlan $plan, ?User $actor = null): DischargePlan
    {
        if ($plan->status === DischargePlan::FINAL) {
            throw new ClinicalException('Rencana pulang ini sudah difinalkan.');
        }

        if ($plan->status === DischargePlan::DIBATALKAN) {
            throw new ClinicalException('Rencana pulang yang dibatalkan tidak bisa difinalkan.');
        }

        if (blank($plan->caregiver_name)) {
            throw new ClinicalException(
                'Nama keluarga/penunggu yang akan melanjutkan perawatan di rumah wajib disebut. Rencana pulang '
                .'yang tidak menyebut siapa penerimanya belum diserahkan kepada siapa pun.'
            );
        }

        $belum = $this->unanswered($plan);

        if ($belum !== []) {
            throw new ClinicalException(
                'Masih ada pertanyaan pengkajian yang belum dijawab: '.implode(', ', $belum)
                .'. Kosong berarti belum ditanyakan, bukan "tidak" — jawab dulu sebelum rencananya ditutup.'
            );
        }

        $plan->update([
            'status' => DischargePlan::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $plan->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(DischargePlan $plan, string $reason): DischargePlan
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($plan->status === DischargePlan::DIBATALKAN) {
            throw new ClinicalException('Rencana pulang ini sudah dibatalkan.');
        }

        $plan->update([
            'status' => DischargePlan::DIBATALKAN,
            'education_given' => trim(
                ($plan->education_given ? $plan->education_given.' ' : '')."[Dibatalkan: {$alasan}]"
            ),
        ]);

        return $plan->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forAdmission(int $admissionId): ?DischargePlan
    {
        return DischargePlan::query()
            ->where('admission_id', $admissionId)
            ->where('status', '<>', DischargePlan::DIBATALKAN)
            ->first();
    }

    /**
     * Admisi yang masih dirawat, sudah lewat tenggat, dan belum punya
     * rencana pulang sama sekali.
     *
     * Inilah gunanya perencanaan pemulangan sebagai data: daftar ini yang
     * dibaca kepala ruang tiap pagi, bukan formulir yang baru dicari saat
     * pasien sudah menunggu di depan pintu.
     */
    public function overdue(): Collection
    {
        $sudah = DischargePlan::query()
            ->where('status', '<>', DischargePlan::DIBATALKAN)
            ->pluck('admission_id');

        return collect(DB::table(self::ADMISI)
            ->whereNull('discharged_at')
            ->where('admitted_at', '<', now()->subHours(DischargePlan::TENGGAT_JAM))
            ->whereNotIn('admission_id', $sudah->all())
            ->orderBy('admitted_at')
            ->get());
    }

    // ------------------------------------------------------------ internal

    /**
     * @return array<int, string>
     */
    private function unanswered(DischargePlan $plan): array
    {
        $pertanyaan = [
            'affects_family' => 'pengaruh terhadap keluarga',
            'affects_work_or_school' => 'pengaruh terhadap pekerjaan/sekolah',
            'affects_finance' => 'pengaruh terhadap keuangan',
            'anticipated_problems' => 'antisipasi masalah saat pulang',
        ];

        $belum = [];

        foreach ($pertanyaan as $kolom => $label) {
            // Sengaja membandingkan dengan null, bukan memakai empty():
            // false adalah jawaban yang sah dan justru jawaban yang paling
            // sering benar. empty() akan menganggapnya belum dijawab.
            if ($plan->{$kolom} === null) {
                $belum[] = $label;
            }
        }

        return $belum;
    }

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validAssistance($nilai): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException('Bantuan yang dibutuhkan harus berupa daftar.');
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys(DischargePlan::BANTUAN));

        if ($asing !== []) {
            throw new ClinicalException(
                'Jenis bantuan tidak dikenali: '.implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys(DischargePlan::BANTUAN)).'.'
            );
        }

        return $bersih;
    }
}
