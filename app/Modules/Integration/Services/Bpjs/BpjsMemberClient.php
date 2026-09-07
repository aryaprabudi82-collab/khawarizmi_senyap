<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter VClaim untuk pencarian & riwayat peserta (domain L item J).
 *
 * Empat panggilan, satu bentuk jawaban — sama seperti seluruh adapter BPJS
 * lain, supaya pemanggil tidak perlu tahu jawabannya datang dari API asli
 * atau dari adapter palsu.
 */
interface BpjsMemberClient
{
    /**
     * Mencari peserta menurut NIK kependudukan (bpjs_cek_nik).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function findByNik(string $nik, string $tanggalPelayanan): array;

    /**
     * Surat Keterangan Dalam Perawatan (bpjs_cek_skdp).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function findSkdp(string $noSurat, string $noKartu): array;

    /**
     * Riwayat pelayanan peserta di seluruh fasilitas (bpjs_histori_pelayanan).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function serviceHistory(string $noKartu, string $from, string $until): array;

    /**
     * Status pendaftaran sidik jari peserta (bpjs_daftar_finger_print).
     *
     * Yang ditanyakan status, BUKAN sidik jarinya — sistem ini tidak
     * menyimpan data biometrik apa pun.
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function fingerprintStatus(string $noKartu, string $tanggalPelayanan): array;
}
