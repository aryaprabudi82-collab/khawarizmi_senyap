<?php

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setiap mesin harus bisa dijalankan seseorang — atau tercatat kenapa belum.
 *
 * MENGAPA UJI INI ADA. Verifikasi domain L menemukan TIGA BELAS layanan
 * integrasi yang terbangun lengkap berikut ujinya — klaim INA-CBG, Smart
 * Klaim, Antrean Mobile JKN, Aplicares, Program Rujuk Balik, apotek BPJS,
 * kecelakaan/Jasa Raharja, SPRI, pencarian peserta, Sisrute, Inhealth, dan
 * tiga rantai SATUSEHAT — dan tidak satu pun punya rute, controller, atau
 * perintah konsol yang memanggilnya. Tidak ada seorang pun di rumah sakit
 * yang bisa menjalankannya.
 *
 * SELURUH 21 BERKAS UJI INTEGRASI TETAP HIJAU, karena semuanya memanggil
 * service langsung. Uji yang memanggil service tidak pernah menanyakan
 * apakah ada jalan bagi manusia menuju service itu.
 *
 * Ini pengulangan closing_kasir pada domain U: mesin penutupan shift
 * lengkap berikut ujinya, tanpa satu pun jalan bagi kasir menjalankannya.
 * Sekali adalah kelalaian; tiga belas kali adalah pola, dan pola butuh
 * penjaga yang tidak bisa lupa.
 *
 * YANG DIJAGA UJI INI BUKAN "semuanya sudah punya layar" — daftar
 * BELUM_TERJANGKAU di bawah justru mengakui yang belum. Yang dijaga adalah
 * daftar itu tidak BERTAMBAH diam-diam, dan tidak menyimpan nama yang
 * sudah selesai.
 */
class ServiceReachabilityTest extends TestCase
{
    /**
     * Layanan yang mesinnya ada tapi belum punya jalan bagi penggunanya.
     *
     * MENGHAPUS baris dari sini adalah tujuannya: begitu layarnya dibangun,
     * namanya keluar. MENAMBAH baris berarti menyatakan dengan sadar bahwa
     * sebuah mesin dibangun tanpa jalan masuk — dan itu harus terlihat,
     * bukan terjadi diam-diam.
     *
     * @var array<string, string>
     */
    private const BELUM_TERJANGKAU = [
        'Bpjs/ClaimService' => 'Klaim INA-CBG & monitoring klaim (domain L item C).',
        'Bpjs/SmartClaimService' => 'Smart Klaim BPJS bentuk FHIR (domain L item N).',
        'Bpjs/QueueService' => 'Antrean Mobile JKN — kewajiban sejak 2022.',
        'Bpjs/AplicaresService' => 'Pelaporan ketersediaan tempat tidur & iCare (domain L item B).',
        'Bpjs/PrbService' => 'Program Rujuk Balik & pelayanan obatnya (domain L item G).',
        'Bpjs/ApotekPrescriptionService' => 'Resep Apotek Online BPJS & resep iterasi (domain L item M).',
        'Bpjs/AccidentService' => 'Kecelakaan & penjaminan Jasa Raharja (domain L item K).',
        'Bpjs/AdmissionOrderService' => 'Surat Perintah Rawat Inap & reklasifikasi SEP (domain L item L).',
        'Bpjs/MemberLookupService' => 'Pencarian & riwayat peserta BPJS (domain L item J).',
        'Sisrute/SisruteReferralService' => 'Rujukan Sisrute masuk & keluar (domain L item P).',
        'Inhealth/InhealthService' => 'Eligibilitas, SJP, dan tagihan Inhealth (domain L item Q).',
        'Satusehat/ClinicalNoteSyncService' => 'ClinicalImpression, CarePlan, diet (domain L item O).',
        'Satusehat/DiagnosticSyncService' => 'Rantai penunjang SATUSEHAT (domain L item H).',
        'Satusehat/MedicationSyncService' => 'Rantai farmasi SATUSEHAT (domain L item I).',
        'Satusehat/CodeMappingService' => 'Pemetaan kode SNOMED/LOINC/KFA (domain L item D).',
        'PayerReferenceService' => 'Referensi & pemetaan kode penjamin (domain L item E). '
            .'Sebagiannya terjangkau lewat BpjsController::mapPoli, tapi hanya pemetaan poli.',
    ];

    /**
     * Layanan yang memang tidak dipanggil controller mana pun karena
     * dipakai layanan lain, bukan oleh manusia.
     *
     * @var array<int, string>
     */
    private const PEMBANTU = [
        'IntegrationRegistry', 'IntegrationException', 'CredentialStore',
        'OutboundMessageLedger', 'IdentityMappingService', 'IntegrationHealthService',
        'AssessmentContext', 'DiagnosisContext', 'OrderContext', 'OrganizationContext',
        'PatientContext', 'PayerContext', 'PharmacyContext', 'ReferralContext',
        'RegistrationContext',
    ];

    #[Test]
    public function daftar_mesin_tanpa_layar_tidak_bertambah(): void
    {
        $dasar = app_path('Modules/Integration/Services');
        $kodeAplikasi = $this->kodeAplikasiTanpaLayananIntegrasi();

        $baru = [];

        foreach ($this->berkasLayanan($dasar) as $berkas) {
            $relatif = str_replace('\\', '/', substr($berkas, strlen($dasar) + 1, -4));
            $kelas = basename($relatif);

            if ($this->bukanLayanan($kelas)) {
                continue;
            }

            if (array_key_exists($relatif, self::BELUM_TERJANGKAU)) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($kelas, '/').'\b/', $kodeAplikasi) === 1) {
                continue;
            }

            $baru[] = $relatif;
        }

        $this->assertSame([], $baru,
            count($baru).' layanan integrasi BARU tidak terjangkau dari mana pun — tidak '
            ."ada rute, controller, maupun perintah konsol yang memanggilnya:\n  "
            .implode("\n  ", $baru)
            ."\n\nMesin yang tidak bisa dijalankan siapa pun adalah pekerjaan yang belum "
            .'selesai, sebanyak apa pun ujinya hijau — uji memanggil service langsung, '
            .'pengguna tidak bisa. Bangun layarnya, ATAU daftarkan di BELUM_TERJANGKAU '
            .'supaya utangnya ikut terhitung dan terlihat.');
    }

    #[Test]
    public function daftar_tidak_menyimpan_layanan_yang_sudah_punya_layar(): void
    {
        $kodeAplikasi = $this->kodeAplikasiTanpaLayananIntegrasi();
        $sudahTerjangkau = [];

        foreach (array_keys(self::BELUM_TERJANGKAU) as $relatif) {
            $kelas = basename($relatif);

            if (preg_match('/\b'.preg_quote($kelas, '/').'\b/', $kodeAplikasi) === 1) {
                $sudahTerjangkau[] = $relatif;
            }
        }

        /*
         * Daftar utang yang menyimpan nama yang sudah lunas berhenti
         * dipercaya, lalu berhenti dibaca — dan utang berikutnya masuk
         * tanpa ada yang memperhatikan.
         */
        $this->assertSame([], $sudahTerjangkau,
            "Layanan berikut sudah punya jalan masuk tapi masih tercatat sebagai utang:\n  "
            .implode("\n  ", $sudahTerjangkau)
            ."\n\nKeluarkan dari BELUM_TERJANGKAU.");
    }

    #[Test]
    public function daftar_tidak_menyebut_layanan_yang_sudah_tidak_ada(): void
    {
        $dasar = app_path('Modules/Integration/Services');
        $hilang = [];

        foreach (array_keys(self::BELUM_TERJANGKAU) as $relatif) {
            if (! is_file($dasar.'/'.$relatif.'.php')) {
                $hilang[] = $relatif;
            }
        }

        $this->assertSame([], $hilang,
            "BELUM_TERJANGKAU menyebut layanan yang berkasnya sudah tidak ada:\n  "
            .implode("\n  ", $hilang));
    }

    /**
     * Seluruh kode aplikasi DI LUAR app/Modules/Integration/Services.
     *
     * Yang dicari adalah jalan masuk dari luar — controller, rute, perintah
     * konsol, penjadwalan. Layanan yang cuma dipanggil layanan tetangganya
     * sendiri tetap tidak terjangkau siapa pun, jadi direktori itu sengaja
     * DIKELUARKAN, bukan disapu ikut.
     */
    private function kodeAplikasiTanpaLayananIntegrasi(): string
    {
        $dasar = str_replace('\\', '/', app_path('Modules/Integration/Services'));
        $isi = '';

        foreach ([app_path(), base_path('routes')] as $akar) {
            foreach ($this->berkasPhp($akar) as $berkas) {
                if (str_starts_with(str_replace('\\', '/', $berkas), $dasar)) {
                    continue;
                }

                $isi .= (string) file_get_contents($berkas);
            }
        }

        return $isi;
    }

    /** @return array<int, string> */
    private function berkasLayanan(string $dasar): array
    {
        return $this->berkasPhp($dasar);
    }

    /** @return array<int, string> */
    private function berkasPhp(string $akar): array
    {
        if (! is_dir($akar)) {
            return [];
        }

        $hasil = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $berkas) {
            if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                $hasil[] = $berkas->getPathname();
            }
        }

        sort($hasil);

        return $hasil;
    }

    /**
     * Client, adapter palsu, kontrak, pengecualian, mapper, dan pembaca
     * konteks bukan "mesin" dalam arti uji ini: yang dicari layanan yang
     * MENGERJAKAN sesuatu atas perintah orang.
     */
    private function bukanLayanan(string $kelas): bool
    {
        return in_array($kelas, self::PEMBANTU, true)
            || str_ends_with($kelas, 'Client')
            || str_ends_with($kelas, 'Exception')
            || str_ends_with($kelas, 'Mapper')
            /*
             * Kelas kesiapan didaftarkan ModuleServiceProvider LEWAT
             * KONVENSI NAMA — nama kelasnya disusun sebagai string saat
             * jalan, jadi ia tidak pernah muncul harfiah di kode mana pun.
             * Uji ini menangkapnya sebagai "tidak terjangkau" pada
             * percobaan pertama, dan tuduhannya salah: ia justru dijalankan
             * tiap kali `siap:periksa` dipanggil.
             */
            || str_ends_with($kelas, 'Readiness')
            || str_starts_with($kelas, 'Fake')
            || str_starts_with($kelas, 'Signs');
    }
}
