<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Facades\DB;

/**
 * Identitas pasien selengkapnya — untuk banner kepala layar RME.
 *
 * MENGAPA TIDAK CUKUP DARI `assessments`. Tabel asesmen membekukan nama,
 * nomor rekam medis, dan unit pada saat asesmen dibuat — itu benar, dan
 * memang harus begitu supaya catatan lama tidak berubah saat data pasien
 * diperbarui. Tapi banner kepala layar perlu hal yang berbeda: jenis
 * kelamin, umur, alamat, telepon, dan PENANDA KEWASPADAAN KHUSUS, yang
 * seluruhnya tidak dibekukan karena memang harus menampilkan keadaan
 * terkini.
 *
 * `special_precautions` YANG PALING MENENTUKAN. Ia penanda yang harus
 * terlihat sebelum pemeriksa menyentuh pasien — kewaspadaan isolasi,
 * riwayat kekerasan, risiko melarikan diri. Menyembunyikannya di layar
 * lain berarti ia dibaca setelah kejadian, bukan sebelum.
 */
class PatientIdentityContext
{
    public function find(int $patientId): ?object
    {
        return DB::table('identity.v_patient_summary')->where('id', $patientId)->first();
    }

    /**
     * Umur dalam bentuk yang dibaca petugas: "23 Th 1 Bl 21 Hr".
     *
     * Dihitung dari tanggal lahir, bukan disimpan. Umur yang disimpan
     * sebagai angka akan basi diam-diam — dan pada bayi, selisih beberapa
     * hari mengubah dosis obat.
     */
    public function umur(?string $tanggalLahir): string
    {
        if ($tanggalLahir === null || $tanggalLahir === '') {
            return '—';
        }

        try {
            $lahir = new \DateTimeImmutable($tanggalLahir);
        } catch (\Exception) {
            return '—';
        }

        $selisih = $lahir->diff(new \DateTimeImmutable('today'));

        return sprintf('%d Th %d Bl %d Hr', $selisih->y, $selisih->m, $selisih->d);
    }

    /** Alamat satu baris, bagian kosong dilewati supaya tidak muncul koma menggantung. */
    public function alamatRingkas(?object $pasien): string
    {
        if ($pasien === null) {
            return '—';
        }

        $bagian = array_filter([
            $pasien->address ?? null,
            $pasien->village_name ?? null,
            $pasien->district_name ?? null,
            $pasien->city_name ?? null,
        ], fn ($b) => $b !== null && trim((string) $b) !== '');

        return $bagian === [] ? '—' : implode(', ', $bagian);
    }
}
