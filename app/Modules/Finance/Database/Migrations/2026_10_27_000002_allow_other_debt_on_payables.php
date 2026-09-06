<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Beban hutang lain ikut buku hutang yang sudah ada (domain K item C).
 *
 * Menaungi beban_hutang_lain, pemberi_hutang_lain,
 * bayar_beban_hutang_lain, dan ringkasan_beban_hutang_lain.
 *
 * Khanza menaruh keempatnya di menu piutang, tapi arahnya berlawanan:
 * ini uang yang akan KELUAR — rumah sakit yang berhutang. Bentuknya sama
 * persis dengan hutang vendor: kepada siapa, sejumlah berapa, jatuh tempo
 * kapan, dicicil berapa kali. Membuat tabel tersendiri berarti menyalin
 * seluruh hitungan umur hutang dan pelunasan sekali lagi, dan salinan
 * kedua itulah yang kelak ketinggalan saat aturannya berubah.
 *
 * Jadi cukup satu nilai baru pada source_context. Yang berbeda hanya satu
 * hal, dan itu ditegakkan di service: hutang 'lain' TIDAK melewati alur
 * titip-faktur-lalu-validasi, karena tidak ada vendor yang menitipkan
 * apa pun — yang mencatat sudah keuangan sendiri. Ia langsung berstatus
 * tervalidasi.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const SUMBER_LAMA = ['farmasi', 'non-medis', 'dapur', 'aset'];

    private const SUMBER_BARU = ['farmasi', 'non-medis', 'dapur', 'aset', 'lain'];

    public function up(): void
    {
        $this->ganti(self::SUMBER_BARU);
    }

    public function down(): void
    {
        DB::table(self::S . '.payables')->where('source_context', 'lain')->delete();

        $this->ganti(self::SUMBER_LAMA);
    }

    private function ganti(array $sumber): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.payables DROP CONSTRAINT IF EXISTS payables_source_check');

        DB::statement('ALTER TABLE ' . self::S . ".payables
            ADD CONSTRAINT payables_source_check
            CHECK (source_context IN ('" . implode("','", $sumber) . "'))");
    }
};
