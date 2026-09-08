<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\MedicalCertificate;
use Illuminate\Support\Facades\DB;

class CertificateService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function issue(array $data, int $issuedBy): MedicalCertificate
    {
        $this->assertTemuanDicatat($data);
        $this->assertPihakKedua($data);

        if (($data['certificate_type'] ?? null) === MedicalCertificate::JENIS_RAWAT_INAP) {
            $data = $this->salinPeriodeRawatInap($data);
        }

        return MedicalCertificate::query()->create($data + [
            'certificate_number' => $this->numbers->allocate('SKT'),
            'issued_by' => $issuedBy,
            'issued_at' => now(),
            'status' => MedicalCertificate::STATUS_DITERBITKAN,
        ]);
    }

    public function cancel(MedicalCertificate $certificate): MedicalCertificate
    {
        if ($certificate->status !== MedicalCertificate::STATUS_DITERBITKAN) {
            throw new CorrespondenceException('Surat ini sudah dibatalkan.');
        }

        $certificate->update(['status' => MedicalCertificate::STATUS_DIBATALKAN]);

        return $certificate->refresh();
    }

    /**
     * Surat yang menyatakan ketiadaan sesuatu wajib mencatat hasil
     * pemeriksaannya.
     *
     * Tanpa ini, layar "Surat Bebas Tato" cuma bisa menerbitkan surat yang
     * menyatakan bebas — hasil pemeriksaan ditentukan oleh nama
     * formulirnya, bukan oleh pemeriksanya.
     */
    private function assertTemuanDicatat(array $data): void
    {
        $jenis = $data['certificate_type'] ?? null;

        if (! in_array($jenis, MedicalCertificate::JENIS_BERTEMUAN, true)) {
            if (array_key_exists('is_clear', $data) && $data['is_clear'] !== null) {
                throw new CorrespondenceException(
                    'Surat jenis "'.$jenis.'" tidak memeriksa apa pun, jadi tidak punya kesimpulan pemeriksaan.'
                );
            }

            return;
        }

        // array_key_exists, bukan empty(): false adalah jawaban yang sah dan
        // justru jawaban yang paling perlu bisa dicatat.
        if (! array_key_exists('is_clear', $data) || $data['is_clear'] === null) {
            throw new CorrespondenceException(
                'Surat "'.$jenis.'" harus menyebutkan hasil pemeriksaannya — termasuk ketika hasilnya '.
                'berlawanan dengan judul suratnya.'
            );
        }

        if (blank($data['examination_result'] ?? null)) {
            throw new CorrespondenceException('Temuan pemeriksaan harus dituliskan, bukan hanya kesimpulannya.');
        }
    }

    /**
     * Surat sakit pihak kedua diserahkan kepada atasan ORANG LAIN.
     *
     * Menuliskan diagnosis pasien di situ berarti menyerahkan rahasia
     * medis seorang pasien kepada perusahaan tempat kerabatnya bekerja.
     * Basis data juga menolaknya; di sini supaya pesannya menjelaskan
     * alasannya, bukan sekadar melanggar constraint.
     */
    private function assertPihakKedua(array $data): void
    {
        if (($data['certificate_type'] ?? null) !== MedicalCertificate::JENIS_SAKIT_PIHAK_KEDUA) {
            return;
        }

        if (blank($data['third_party_name'] ?? null) || blank($data['third_party_relationship'] ?? null)) {
            throw new CorrespondenceException(
                'Surat sakit pihak kedua harus menyebutkan nama dan hubungan orang yang membutuhkannya.'
            );
        }

        if (filled($data['diagnosis'] ?? null)) {
            throw new CorrespondenceException(
                'Diagnosis pasien tidak boleh dicantumkan pada surat sakit pihak kedua — suratnya '.
                'diserahkan kepada atasan orang lain, bukan kepada pasien.'
            );
        }
    }

    /**
     * Periode dirawat DISALIN dari admisi, tidak diterima dari pengisi.
     *
     * Surat keterangan rawat inap dipakai untuk klaim asuransi dan izin
     * kerja; tanggal yang diketik ulang bisa berbeda dari tanggal admisi
     * tanpa ada yang tahu, dan yang harus menyangkal suratnya sendiri
     * belakangan adalah rumah sakit.
     */
    private function salinPeriodeRawatInap(array $data): array
    {
        $registrationId = $data['registration_id'] ?? null;

        if ($registrationId === null) {
            throw new CorrespondenceException(
                'Surat keterangan rawat inap harus menunjuk kunjungan rawat inapnya — '.
                'periodenya disalin dari admisi, tidak diketik.'
            );
        }

        $admisi = DB::table('inpatient.v_admission_period')
            ->where('registration_id', $registrationId)
            ->orderByDesc('admitted_at')
            ->first();

        if ($admisi === null) {
            throw new CorrespondenceException(
                'Tidak ada admisi rawat inap untuk kunjungan tersebut.'
            );
        }

        $data['valid_from'] = substr((string) $admisi->admitted_at, 0, 10);

        // Pasien yang masih dirawat memang belum punya tanggal pulang.
        // Suratnya berbunyi "sampai saat ini masih dalam perawatan" —
        // dikosongkan, bukan diisi tanggal hari ini.
        $data['valid_until'] = $admisi->discharged_at !== null
            ? substr((string) $admisi->discharged_at, 0, 10)
            : null;

        $data['patient_name'] = $admisi->patient_name;

        return $data;
    }
}
