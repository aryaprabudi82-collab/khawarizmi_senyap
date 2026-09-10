<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat & membaca kapan perawatan terjadwal terakhir berjalan.
 *
 * Dipakai untuk mendeteksi hal yang tidak menimbulkan galat apa pun: cron
 * `schedule:run` yang tidak dipasang, atau dipasang lalu berhenti.
 */
class ScheduledTaskLog
{
    /** Perawatan partisi bulanan. */
    public const PARTISI = 'partisi:pastikan';

    /**
     * Batas wajar sebuah tugas harian dianggap masih berjalan.
     *
     * DUA HARI, bukan satu. Tugas harian yang gagal sekali — server
     * dinyalakan ulang tepat pada jam jadwalnya, pemeliharaan singkat —
     * belum berarti cronnya mati, dan peringatan yang berbunyi karena satu
     * malam terlewat akan cepat diabaikan orang. Yang hendak ditangkap
     * adalah cron yang benar-benar berhenti.
     */
    public const BATAS_JAM = 48;

    public function record(string $task, ?string $summary = null): void
    {
        DB::statement(
            'INSERT INTO platform.scheduled_task_runs (task, last_run_at, summary, updated_at)
             VALUES (?, now(), ?, now())
             ON CONFLICT (task) DO UPDATE
                SET last_run_at = now(), summary = excluded.summary, updated_at = now()',
            [$task, $summary]
        );
    }

    public function lastRun(string $task): ?Carbon
    {
        $baris = DB::selectOne(
            'SELECT last_run_at FROM platform.scheduled_task_runs WHERE task = ?', [$task]
        );

        return $baris === null ? null : Carbon::parse($baris->last_run_at);
    }

    public function summary(string $task): ?string
    {
        $baris = DB::selectOne(
            'SELECT summary FROM platform.scheduled_task_runs WHERE task = ?', [$task]
        );

        return $baris?->summary;
    }

    /**
     * Apakah tugas ini masih berjalan sebagaimana mestinya.
     *
     * Belum pernah jalan sama sekali dan sudah lama tidak jalan sengaja
     * dibedakan — yang pertama berarti cronnya belum dipasang, yang kedua
     * berarti pernah dipasang lalu berhenti. Keduanya perlu tindakan
     * berbeda, dan pesan yang menyamakannya membuat orang mencari di tempat
     * yang salah.
     */
    public function isFresh(string $task, ?int $batasJam = null): bool
    {
        $terakhir = $this->lastRun($task);

        return $terakhir !== null
            && $terakhir->greaterThan(now()->subHours($batasJam ?? self::BATAS_JAM));
    }
}
