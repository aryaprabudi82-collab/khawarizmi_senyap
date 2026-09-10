<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\Registration;
use App\Modules\Organization\Services\OrganizationDirectory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar antrean pendaftaran & poliklinik (Khanza `display`, domain U).
 *
 * TIDAK ADA TABEL BARU. Nomor antrean dan statusnya sudah tercatat pada
 * `encounter.registrations` sejak konteks ini dibangun; yang belum ada cuma
 * layar yang menampilkannya. Membuat tabel antrean terpisah justru akan
 * melahirkan dua sumber kebenaran yang bisa berbeda tentang siapa yang
 * sedang dipanggil.
 *
 * NAMA PASIEN DISAMARKAN, DAN ITU KEPUTUSAN YANG DISENGAJA. Layar ini
 * menghadap ruang tunggu — siapa pun yang lewat bisa membacanya, termasuk
 * tetangga, atasan, atau mantan pasangan seseorang. Menampilkan nama
 * lengkap berikut nama poliklinik memberitahu seisi ruangan bahwa orang ini
 * sedang berobat, dan ke poli apa. Untuk sebagian poliklinik — jiwa, kulit
 * dan kelamin, VCT — itu bukan ketidaknyamanan, itu pembukaan rahasia
 * medis. Yang ditampilkan cukup nomor antrean dan nama yang disamarkan;
 * pasien mengenali nomornya sendiri, orang lain tidak mengenali siapa pun.
 */
class QueueDisplayController
{
    public function __construct(private readonly OrganizationDirectory $organization) {}

    public function index(Request $request): View
    {
        $unitId = $request->integer('unit') ?: null;
        $tanggal = now()->toDateString();

        $antrean = Registration::query()
            ->whereDate('service_date', $tanggal)
            ->where('status', '!=', Registration::STATUS_BATAL)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('unit_name')
            ->orderBy('queue_number')
            ->get()
            ->groupBy('unit_name');

        return view('encounter::display.antrean', [
            'antrean' => $antrean,
            'unit' => $this->organization->activeUnits(),
            'unitDipilih' => $unitId,
            'tanggal' => $tanggal,
        ]);
    }

    /**
     * Menyamarkan nama untuk layar publik.
     *
     * Kata pertama utuh supaya pasien mengenali dirinya sendiri; sisanya
     * jadi inisial. "Siti Rahmawati Dewi" jadi "Siti R. D." — cukup bagi
     * yang menunggu, tidak cukup bagi yang cuma lewat.
     */
    public static function samarkan(?string $nama): string
    {
        $bagian = preg_split('/\s+/', trim((string) $nama)) ?: [];

        if ($bagian === [] || $bagian[0] === '') {
            return '—';
        }

        $hasil = [array_shift($bagian)];

        foreach ($bagian as $kata) {
            $hasil[] = mb_strtoupper(mb_substr($kata, 0, 1)).'.';
        }

        return implode(' ', $hasil);
    }
}
