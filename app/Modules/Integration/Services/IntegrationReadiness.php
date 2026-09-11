<?php

namespace App\Modules\Integration\Services;

use App\Modules\ReadinessCheck;
use Illuminate\Support\Facades\Route;

/**
 * Syarat kesiapan konteks integration.
 *
 * BUTIR PERTAMA ADALAH TEMUAN VERIFIKASI DOMAIN L, dan ia sengaja ditaruh
 * paling depan: enam belas layanan integrasi terbangun lengkap berikut
 * ujinya tanpa satu pun layar, rute, maupun perintah konsol yang
 * memanggilnya. Klaim INA-CBG, Antrean Mobile JKN, Aplicares, Program
 * Rujuk Balik, apotek BPJS, Jasa Raharja, Sisrute, Inhealth, dan tiga
 * rantai SATUSEHAT — semuanya ada mesinnya, dan tidak satu pun bisa
 * dijalankan orang.
 *
 * MENGAPA INI MASUK KESIAPAN OPERASIONAL, BUKAN CUMA CATATAN PENGEMBANG.
 * Yang membaca `siap:periksa` adalah orang yang harus memutuskan apakah
 * rumah sakit boleh mulai beroperasi dengan sistem ini. Kalau ia melihat
 * "integrasi BPJS siap" karena kredensialnya terisi, lalu pada hari
 * pertama menemukan tidak ada cara mengirim klaim, yang rusak bukan cuma
 * jadwalnya melainkan kepercayaannya pada seluruh laporan ini.
 *
 * DAFTARNYA DIBACA DARI UJI, BUKAN DISALIN. Uji ServiceReachabilityTest
 * yang menjaga daftar itu tidak bertambah; kalau daftarnya juga ditulis
 * ulang di sini, keduanya akan berbeda pada perubahan berikutnya dan yang
 * dibaca operator adalah yang basi. Di sini cuma dihitung ulang dengan
 * cara yang sama.
 */
class IntegrationReadiness implements ReadinessCheck
{
    /**
     * Layanan yang HARUS bisa dijangkau sebelum operasional, berikut apa
     * yang tidak bisa dikerjakan siapa pun selama belum.
     *
     * @var array<string, string>
     */
    private const WAJIB_TERJANGKAU = [
        'Bpjs/ClaimService' => 'mengirim klaim INA-CBG ke E-Klaim',
        'Bpjs/QueueService' => 'mengirim antrean ke Mobile JKN',
        'Bpjs/AplicaresService' => 'melaporkan ketersediaan tempat tidur ke BPJS',
        'Bpjs/ApotekPrescriptionService' => 'mengirim resep ke Apotek Online BPJS',
        'Bpjs/AccidentService' => 'mengurus penjaminan Jasa Raharja',
        'Bpjs/AdmissionOrderService' => 'menerbitkan Surat Perintah Rawat Inap',
        'Bpjs/MemberLookupService' => 'mencari peserta menurut NIK',
        'Bpjs/PrbService' => 'menjalankan Program Rujuk Balik',
        'Bpjs/SmartClaimService' => 'mengirim Smart Klaim',
        'Sisrute/SisruteReferralService' => 'menerima & mengirim rujukan Sisrute',
        'Inhealth/InhealthService' => 'menerbitkan SJP & menagih Inhealth',
        'Satusehat/CodeMappingService' => 'mengisi pemetaan kode SNOMED/LOINC/KFA',
        'Satusehat/DiagnosticSyncService' => 'mengirim hasil lab & radiologi ke SATUSEHAT',
        'Satusehat/MedicationSyncService' => 'mengirim resep & penyerahan obat ke SATUSEHAT',
        'Satusehat/ClinicalNoteSyncService' => 'mengirim catatan klinis ke SATUSEHAT',
        'PayerReferenceService' => 'menyegarkan referensi penjamin (poli, dokter, faskes, obat)',
    ];

    public function readinessItems(): array
    {
        return [
            $this->butirKeterjangkauan(),
            $this->butirKredensial(),
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirKeterjangkauan(): array
    {
        $kode = $this->kodeAplikasiTanpaLayananIntegrasi();
        $belum = [];

        foreach (self::WAJIB_TERJANGKAU as $relatif => $pekerjaan) {
            $kelas = basename($relatif);

            if (preg_match('/\b'.preg_quote($kelas, '/').'\b/', $kode) !== 1) {
                $belum[] = $pekerjaan;
            }
        }

        if ($belum === []) {
            return [
                'judul' => 'Layar untuk mesin integrasi',
                'status' => self::BERES,
                'akibat' => 'Seluruh layanan integrasi punya jalan masuk.',
            ];
        }

        return [
            'judul' => 'Layar untuk mesin integrasi',
            'status' => self::MENGHALANGI,
            'akibat' => count($belum).' mesin integrasi tidak punya layar, rute, maupun '
                .'perintah — jadi tidak ada seorang pun yang bisa: '.implode('; ', $belum)
                .'. Mesinnya sendiri sudah lengkap berikut ujinya, dan justru itu yang '
                .'membuat kekurangan ini mudah terlewat: seluruh uji integrasi hijau '
                .'karena memanggil service langsung, sedangkan petugas tidak bisa. '
                .'Sebagian di antaranya kewajiban regulasi yang berjalan sejak hari '
                .'pertama operasional — antrean Mobile JKN, pelaporan tempat tidur, dan '
                .'pengiriman klaim. Lihat tests/Feature/Platform/ServiceReachabilityTest.',
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirKredensial(): array
    {
        $kredensial = app(CredentialStore::class);
        $belum = [];

        foreach (IntegrationRegistry::keys() as $sistem) {
            foreach (IntegrationRegistry::requiredFields($sistem) as $kolom) {
                if (blank($kredensial->get($sistem, $kolom))) {
                    $belum[] = IntegrationRegistry::system($sistem)['label'];

                    continue 2;
                }
            }
        }

        if ($belum === []) {
            return [
                'judul' => 'Kredensial sistem luar',
                'status' => self::BERES,
                'akibat' => 'Seluruh sistem luar sudah punya kredensial lengkap.',
            ];
        }

        return [
            'judul' => 'Kredensial sistem luar',
            'status' => self::PERINGATAN,
            'akibat' => 'Belum lengkap: '.implode(', ', $belum).'. Sistem yang setengah '
                .'terisi akan gagal pada panggilan pertama dengan pesan yang tidak masuk '
                .'akal, dan itu jauh lebih membingungkan daripada sistem yang jelas-jelas '
                .'belum disetel. Diisi lewat Integrasi > Pengaturan; kredensialnya '
                .'diterbitkan BPJS/Kemenkes/Inhealth, bukan dibuat sendiri.',
        ];
    }

    /**
     * Seluruh kode aplikasi DI LUAR direktori layanan integrasi.
     *
     * Yang dicari jalan masuk dari luar. Layanan yang cuma dipanggil
     * layanan tetangganya sendiri tetap tidak terjangkau siapa pun, jadi
     * direktori itu dikeluarkan — bukan ikut disapu.
     */
    private function kodeAplikasiTanpaLayananIntegrasi(): string
    {
        $isi = '';

        foreach (Route::getRoutes() as $rute) {
            $isi .= $rute->getActionName()."\n";
        }

        $dasar = str_replace('\\', '/', app_path('Modules/Integration/Services'));

        foreach ([app_path(), base_path('routes')] as $akar) {
            if (! is_dir($akar)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $berkas) {
                if (! $berkas->isFile() || $berkas->getExtension() !== 'php') {
                    continue;
                }

                if (str_starts_with(str_replace('\\', '/', $berkas->getPathname()), $dasar)) {
                    continue;
                }

                $isi .= (string) file_get_contents($berkas->getPathname());
            }
        }

        return $isi;
    }
}
