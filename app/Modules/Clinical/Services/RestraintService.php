<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\RestraintEpisode;
use App\Modules\Clinical\Models\RestraintReview;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Restrain (domain M item T).
 *
 * LIMA ATURAN, dan semuanya menutup lubang pengkajian_restrain Khanza.
 *
 * 1. PERINTAH DOKTER WAJIB. Khanza hanya mencatat perawat yang mengisi.
 *    Restrain tanpa perintah dokter adalah pengekangan tanpa dasar, dan
 *    catatan yang tidak menyebut pemerintahnya membuat tanggung
 *    jawabnya jatuh ke perawat yang memasangnya.
 *
 * 2. JAM MULAI DAN JAM LEPAS DICATAT, lamanya dihitung. Khanza hanya
 *    punya tanggal penilaian, jadi berapa lama pasien terikat tidak
 *    bisa dijawab sama sekali.
 *
 * 3. UPAYA YANG LEBIH RINGAN WAJIB DICATAT. Restrain pilihan terakhir;
 *    yang harus dibuktikan justru bahwa cara lain sudah dicoba. Daftar
 *    kosong TETAP BOLEH dicatat — kegawatan nyata memang ada — tapi
 *    episodenya bisa disebutkan sebagai temuan saat ditinjau.
 *
 * 4. PENILAIAN ULANG DITAGIH. Restrain menuntut pemeriksaan berkala
 *    atas sirkulasi, kulit, dan apakah pengekangannya masih perlu.
 *
 * 5. MASA BERLAKU PERINTAH MENAGIH, TIDAK MELEPAS. Melepas pasien yang
 *    masih berbahaya karena perintahnya kedaluwarsa lebih berbahaya
 *    daripada perintah yang telat diperbarui.
 *
 * BEBAN PEMBENARANNYA SENGAJA TIDAK SIMETRIS: meneruskan pengekangan
 * wajib beralasan, melepaskannya tidak. Yang perlu dibenarkan adalah
 * membatasi orang, bukan membebaskannya.
 */
class RestraintService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Memulai episode restrain.
     *
     * @throws ClinicalException
     */
    public function start(int $registrationId, array $data, ?User $actor = null): RestraintEpisode
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $berjalan = RestraintEpisode::query()
            ->where('registration_id', $registrationId)
            ->where('status', RestraintEpisode::BERJALAN)
            ->exists();

        if ($berjalan) {
            throw new ClinicalException(
                'Pasien ini sedang dalam episode restrain yang berjalan. Penambahan titik pengekangan '
                .'dicatat pada episode yang sama, bukan sebagai episode kedua.'
            );
        }

        $pemerintah = trim($data['ordered_by_name'] ?? '');

        if ($pemerintah === '') {
            throw new ClinicalException(
                'Nama dokter yang memerintahkan restrain wajib diisi. Restrain tanpa perintah dokter '
                .'adalah pengekangan tanpa dasar, dan catatan yang tidak menyebut pemerintahnya membuat '
                .'tanggung jawabnya jatuh ke perawat yang memasangnya.'
            );
        }

        $indikasi = $data['indication'] ?? '';

        if (! array_key_exists($indikasi, RestraintEpisode::INDIKASI)) {
            throw new ClinicalException(
                "Indikasi restrain '{$indikasi}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(RestraintEpisode::INDIKASI)).'.'
            );
        }

        $perilaku = trim($data['observed_behaviour'] ?? '');

        if ($perilaku === '') {
            throw new ClinicalException(
                'Perilaku yang teramati wajib diuraikan. Indikasi saja tidak menjelaskan apa yang '
                .'sebenarnya terjadi pada pasien, dan itulah yang ditinjau kemudian.'
            );
        }

        $jenis = $this->validList(
            $data['restraint_types'] ?? [],
            RestraintEpisode::JENIS,
            'Jenis restrain',
        );

        $farmakologi = trim($data['pharmacological_restraint'] ?? '');

        if ($jenis === [] && $farmakologi === '') {
            throw new ClinicalException(
                'Setidaknya satu titik pengekangan atau restrain farmakologi wajib disebut. Restrain '
                .'tanpa keduanya bukan restrain.'
            );
        }

        $diperintahkan = isset($data['ordered_at']) ? Carbon::parse($data['ordered_at']) : now();
        $mulai = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();

        if ($mulai->lessThan($diperintahkan)) {
            throw new ClinicalException(
                'Restrain tidak boleh dipasang sebelum diperintahkan. Bila memang dipasang lebih dulu '
                .'karena kegawatan, waktu perintahnya yang perlu dicatat apa adanya.'
            );
        }

        return RestraintEpisode::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'ordered_by' => $data['ordered_by'] ?? null,
            'ordered_by_name' => $pemerintah,
            'ordered_at' => $diperintahkan,
            'order_expires_at' => $diperintahkan->copy()->addHours(RestraintEpisode::MASA_PERINTAH_JAM),
            'indication' => $indikasi,
            'indication_note' => $data['indication_note'] ?? null,
            'observed_behaviour' => $perilaku,
            'alternatives_tried' => $this->validList(
                $data['alternatives_tried'] ?? [],
                RestraintEpisode::ALTERNATIF,
                'Upaya yang lebih ringan',
            ),
            'alternatives_note' => $data['alternatives_note'] ?? null,
            'restraint_types' => $jenis,
            'restraint_types_note' => $data['restraint_types_note'] ?? null,
            'pharmacological_restraint' => $farmakologi !== '' ? $farmakologi : null,
            'family_informed' => $data['family_informed'] ?? false,
            'consenting_family_name' => $data['consenting_family_name'] ?? null,
            'consenting_family_relation' => $data['consenting_family_relation'] ?? null,
            'started_at' => $mulai,
            'status' => RestraintEpisode::BERJALAN,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => trim($data['recorded_by_name'] ?? $actor?->name ?? 'Tidak disebutkan'),
        ]);
    }

    /**
     * Memperbarui perintah restrain yang masa berlakunya habis.
     *
     * @throws ClinicalException
     */
    public function renewOrder(RestraintEpisode $episode, string $orderedByName, array $data = []): RestraintEpisode
    {
        if (! $episode->isRunning()) {
            throw new ClinicalException('Episode ini sudah tidak berjalan.');
        }

        $pemerintah = trim($orderedByName);

        if ($pemerintah === '') {
            throw new ClinicalException('Nama dokter yang memperbarui perintah wajib diisi.');
        }

        $diperintahkan = isset($data['ordered_at']) ? Carbon::parse($data['ordered_at']) : now();

        $episode->update([
            'ordered_by' => $data['ordered_by'] ?? $episode->ordered_by,
            'ordered_by_name' => $pemerintah,
            'ordered_at' => $diperintahkan,
            'order_expires_at' => $diperintahkan->copy()->addHours(RestraintEpisode::MASA_PERINTAH_JAM),
        ]);

        return $episode->refresh();
    }

    /**
     * Mencatat penilaian ulang.
     *
     * @throws ClinicalException
     */
    public function review(RestraintEpisode $episode, array $data, ?User $actor = null): RestraintReview
    {
        if (! $episode->isRunning()) {
            throw new ClinicalException(
                'Episode ini sudah tidak berjalan, jadi penilaian ulang tidak bisa ditambahkan.'
            );
        }

        if (! array_key_exists('still_needed', $data) || ! is_bool($data['still_needed'])) {
            throw new ClinicalException(
                'Keputusan apakah restrain masih diperlukan wajib dijawab. Itulah pertanyaan pokok tiap '
                .'peninjauan, dan mengosongkannya membuat peninjauannya tidak menghasilkan apa pun.'
            );
        }

        $alasan = trim($data['reason'] ?? '');

        // Beban pembenarannya sengaja tidak simetris — lihat catatan kelas.
        if ($data['still_needed'] === true && $alasan === '') {
            throw new ClinicalException(
                'Alasan meneruskan pengekangan wajib diisi. Yang perlu dibenarkan adalah membatasi '
                .'orang, bukan membebaskannya.'
            );
        }

        $penilai = trim($data['reviewed_by_name'] ?? $actor?->name ?? '');

        if ($penilai === '') {
            throw new ClinicalException('Nama penilai wajib dicatat.');
        }

        return RestraintReview::query()->create([
            'episode_id' => $episode->id,
            'reviewed_at' => $data['reviewed_at'] ?? now(),
            'circulation' => $data['circulation'] ?? null,
            'skin_condition' => $data['skin_condition'] ?? null,
            'position_changed' => $data['position_changed'] ?? null,
            'basic_needs_met' => $data['basic_needs_met'] ?? null,
            'still_needed' => $data['still_needed'],
            'reason' => $alasan !== '' ? $alasan : null,
            'reviewed_by' => $actor?->id,
            'reviewed_by_name' => $penilai,
        ]);
    }

    /**
     * Melepas restrain.
     *
     * @throws ClinicalException
     */
    public function release(RestraintEpisode $episode, string $reason, array $data = []): RestraintEpisode
    {
        if (! $episode->isRunning()) {
            throw new ClinicalException('Episode ini sudah tidak berjalan.');
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException(
                'Alasan pelepasan wajib diisi: kapan dan mengapa restrain dihentikan adalah bagian dari '
                .'pembenarannya.'
            );
        }

        $episode->update([
            'released_at' => $data['released_at'] ?? now(),
            'release_reason' => $alasan,
            'status' => RestraintEpisode::DILEPAS,
        ]);

        return $episode->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function runningFor(int $registrationId): ?RestraintEpisode
    {
        return RestraintEpisode::query()
            ->where('registration_id', $registrationId)
            ->where('status', RestraintEpisode::BERJALAN)
            ->with('reviews')
            ->first();
    }

    /**
     * Episode berjalan yang perintahnya sudah kedaluwarsa.
     *
     * MENAGIH, BUKAN MELEPAS — lihat aturan 5 pada catatan kelas.
     */
    public function withExpiredOrder(): Collection
    {
        return RestraintEpisode::query()
            ->where('status', RestraintEpisode::BERJALAN)
            ->where('order_expires_at', '<', now())
            ->orderBy('order_expires_at')
            ->get();
    }

    /** Episode berjalan yang sudah lewat tenggat penilaian ulang. */
    public function reviewOverdue(): Collection
    {
        return RestraintEpisode::query()
            ->where('status', RestraintEpisode::BERJALAN)
            ->with('reviews')
            ->get()
            ->filter(fn (RestraintEpisode $e) => $e->isReviewOverdue())
            ->values();
    }

    /**
     * Episode yang dipasang tanpa satu pun upaya lebih ringan dicoba.
     *
     * Bukan pelanggaran yang dihalangi sistem — kegawatan nyata memang
     * ada — melainkan temuan yang harus terlihat saat komite meninjau
     * penggunaan restrain.
     */
    public function withoutAlternativesTried(string $from, string $until): Collection
    {
        return RestraintEpisode::query()
            ->whereBetween('started_at', [$from, $until])
            ->where('status', '<>', RestraintEpisode::DIBATALKAN)
            ->whereRaw('jsonb_array_length(alternatives_tried) = 0')
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Episode yang penilaian ulangnya menemukan cedera akibat
     * pengekangannya.
     */
    public function withInjury(string $from, string $until): Collection
    {
        return RestraintEpisode::query()
            ->whereBetween('started_at', [$from, $until])
            ->with('reviews')
            ->get()
            ->filter(fn (RestraintEpisode $e) => $e->reviews->contains(
                fn (RestraintReview $r) => $r->hasRestraintInjury()
            ))
            ->values();
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  mixed  $nilai
     * @param  array<string, string>  $kosakata
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validList($nilai, array $kosakata, string $label): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException("{$label} harus berupa daftar.");
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys($kosakata));

        if ($asing !== []) {
            throw new ClinicalException(
                "{$label} tidak dikenali: ".implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys($kosakata)).'.'
            );
        }

        return $bersih;
    }
}
