<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Operation;
use App\Modules\Clinical\Models\SurgicalSafetyChecklist;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;

/**
 * Daftar tilik keselamatan bedah WHO (domain M item C) — 3 kode:
 * signin_sebelum_anestesi, timeout_sebelum_insisi,
 * signout_sebelum_menutup_luka.
 *
 * Juga butir akreditasi KARS Sasaran Keselamatan Pasien IV: kepastian
 * tepat lokasi, tepat prosedur, tepat pasien operasi.
 *
 * URUTAN FASE DITEGAKKAN, DAN ITU INTI KELAS INI. Sign In sebelum
 * anestesi, Time Out sebelum insisi, Sign Out sebelum menutup luka. Khanza
 * menyimpan ketiganya sebagai tabel terpisah tanpa saling tahu, sehingga
 * Time Out bisa tercatat tanpa Sign In — artinya pemeriksaan sebelum
 * pembiusan tidak pernah terjadi, tapi dokumennya tetap rapi. Daftar tilik
 * yang bisa diisi terbalik bukan daftar tilik, cuma formulir.
 *
 * BUTIR YANG MENUNTUT PERHATIAN DICATAT TERPISAH. Daftar tilik yang
 * menemukan masalah — alergi, risiko aspirasi, hitungan kasa tidak cocok —
 * tapi temuannya terkubur di dalam jawaban, tidak menolong siapa pun saat
 * ditanya belakangan.
 *
 * HITUNGAN KASA DAN INSTRUMEN YANG TIDAK COCOK MENAHAN SIGN OUT. Sign Out
 * gunanya justru itu: memastikan tidak ada yang tertinggal di dalam tubuh
 * pasien. Meloloskannya dengan catatan "nanti dicek lagi" adalah persis
 * kejadian yang daftar tilik ini ada untuk mencegahnya.
 */
class SurgicalSafetyService
{
    /**
     * Butir Sign Out yang jawabannya harus "ya" — hitungan yang tidak
     * cocok berarti ada yang mungkin tertinggal di dalam tubuh pasien.
     */
    private const WAJIB_COCOK = [
        'verbal_kelengkapan_kasa' => 'Hitungan kasa',
        'verbal_instrumen' => 'Hitungan instrumen',
        'verbal_alat_tajam' => 'Hitungan alat tajam',
    ];

    /**
     * Mencatat satu fase daftar tilik.
     *
     * @throws ClinicalException
     */
    public function record(Operation $operation, string $phase, array $answers, array $data = [], ?User $actor = null): SurgicalSafetyChecklist
    {
        if (! in_array($phase, SurgicalSafetyChecklist::URUTAN, true)) {
            throw new ClinicalException("Fase daftar tilik '{$phase}' tidak dikenal.");
        }

        $sudahAda = SurgicalSafetyChecklist::query()
            ->where('operation_id', $operation->id)
            ->where('phase', $phase)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException(
                SurgicalSafetyChecklist::LABEL[$phase] . ' sudah dicatat untuk operasi ini.'
            );
        }

        $this->assertPhaseOrder($operation, $phase);

        if ($phase === SurgicalSafetyChecklist::SIGN_OUT) {
            $this->assertCountsMatch($answers);
        }

        return SurgicalSafetyChecklist::query()->create([
            'operation_id' => $operation->id,
            'registration_id' => $operation->registration_id,
            'patient_id' => $operation->patient_id,
            'patient_name' => $operation->patient_name,
            'phase' => $phase,
            // Disalin: nama tindakan boleh diperbaiki belakangan, yang
            // diverifikasi di kamar operasi tidak boleh ikut berubah.
            'procedure_name' => $operation->service_name,
            'surgeon_name' => $data['surgeon_name'] ?? $operation->surgeon_name,
            'anesthetist_name' => $data['anesthetist_name'] ?? null,
            'scrub_nurse_name' => $data['scrub_nurse_name'] ?? null,
            'answers' => $answers,
            'concerns' => $data['concerns'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
            'performed_at' => $data['performed_at'] ?? now(),
        ]);
    }

    /** Daftar tilik satu operasi, urut fase. */
    public function forOperation(Operation $operation): Collection
    {
        return SurgicalSafetyChecklist::query()
            ->where('operation_id', $operation->id)
            ->get()
            ->sortBy(fn ($c) => array_search($c->phase, SurgicalSafetyChecklist::URUTAN, true))
            ->values();
    }

    /**
     * Fase yang belum dicatat untuk satu operasi.
     *
     * Inilah daftar yang menjawab pertanyaan audit "operasi mana yang
     * daftar tiliknya tidak lengkap" — pertanyaan yang selalu muncul saat
     * akreditasi, dan yang tidak bisa dijawab kalau ketiganya cuma
     * disimpan terpisah tanpa saling tahu.
     *
     * @return array<int, string>
     */
    public function missingPhases(Operation $operation): array
    {
        $ada = SurgicalSafetyChecklist::query()
            ->where('operation_id', $operation->id)
            ->pluck('phase')
            ->all();

        return array_values(array_diff(SurgicalSafetyChecklist::URUTAN, $ada));
    }

    public function isComplete(Operation $operation): bool
    {
        return $this->missingPhases($operation) === [];
    }

    /**
     * Operasi yang daftar tiliknya belum lengkap, dalam satu rentang.
     */
    public function incompleteBetween(string $from, string $until): Collection
    {
        return Operation::query()
            ->whereBetween('performed_at', [$from . ' 00:00:00', $until . ' 23:59:59'])
            ->get()
            ->filter(fn (Operation $o) => ! $this->isComplete($o))
            ->map(fn (Operation $o) => (object) [
                'operation_id' => $o->id,
                'patient_name' => $o->patient_name,
                'procedure_name' => $o->service_name,
                'performed_at' => $o->performed_at,
                'missing' => $this->missingPhases($o),
            ])
            ->values();
    }

    // ---------------------------------------------------------------- privat

    /**
     * @throws ClinicalException
     */
    private function assertPhaseOrder(Operation $operation, string $phase): void
    {
        $urutan = array_search($phase, SurgicalSafetyChecklist::URUTAN, true);

        if ($urutan === 0) {
            return;
        }

        $sebelumnya = SurgicalSafetyChecklist::URUTAN[$urutan - 1];

        $ada = SurgicalSafetyChecklist::query()
            ->where('operation_id', $operation->id)
            ->where('phase', $sebelumnya)
            ->exists();

        if (! $ada) {
            throw new ClinicalException(
                SurgicalSafetyChecklist::LABEL[$phase] . ' tidak bisa dicatat sebelum '
                . SurgicalSafetyChecklist::LABEL[$sebelumnya] . '. '
                . 'Urutan fase adalah isi aturannya, bukan tata letak layar.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws ClinicalException
     */
    private function assertCountsMatch(array $answers): void
    {
        $tidakCocok = [];

        foreach (self::WAJIB_COCOK as $kunci => $sebutan) {
            $jawab = $answers[$kunci] ?? null;

            if ($jawab === null) {
                $tidakCocok[] = $sebutan . ' belum dijawab';

                continue;
            }

            if ($jawab !== true && $jawab !== 'ya') {
                $tidakCocok[] = $sebutan . ' tidak cocok';
            }
        }

        if ($tidakCocok !== []) {
            throw new ClinicalException(
                'Sign Out tidak bisa diselesaikan: ' . implode(', ', $tidakCocok) . '. '
                . 'Hitungan yang tidak cocok berarti ada yang mungkin tertinggal di dalam tubuh pasien — '
                . 'selesaikan hitungannya dulu, jangan diloloskan dengan catatan.'
            );
        }
    }
}
