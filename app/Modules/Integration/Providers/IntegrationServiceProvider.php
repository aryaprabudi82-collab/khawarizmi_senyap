<?php

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Services\Bpjs\BpjsClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimClient;
use App\Modules\Integration\Services\Bpjs\AplicaresClient;
use App\Modules\Integration\Services\Bpjs\BpjsClaimClient;
use App\Modules\Integration\Services\Bpjs\BpjsQueueClient;
use App\Modules\Integration\Services\Bpjs\ClaimClient;
use App\Modules\Integration\Services\Bpjs\QueueClient;
use App\Modules\Integration\Services\Bpjs\BpjsAplicaresClient;
use App\Modules\Integration\Services\Bpjs\BpjsAccidentClient;
use App\Modules\Integration\Services\Bpjs\BpjsAdmissionClient;
use App\Modules\Integration\Services\Bpjs\BpjsApolApotekClient;
use App\Modules\Integration\Services\Bpjs\BpjsApotekClient;
use App\Modules\Integration\Services\Bpjs\BpjsFhirSmartClaimClient;
use App\Modules\Integration\Services\Bpjs\BpjsSmartClaimClient;
use App\Modules\Integration\Services\Bpjs\BpjsMemberClient;
use App\Modules\Integration\Services\Bpjs\BpjsReferralClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimAccidentClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimAdmissionClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimMemberClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimReferralClient;
use App\Modules\Integration\Services\Bpjs\FakeAplicaresClient;
use App\Modules\Integration\Services\Bpjs\FakeClaimClient;
use App\Modules\Integration\Services\Bpjs\FakeQueueClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsAccidentClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsAdmissionClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsApotekClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsSmartClaimClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsMemberClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsReferralClient;
use App\Modules\Integration\Services\CredentialStore;
use App\Modules\Integration\Services\Satusehat\FakeSatusehatClient;
use App\Modules\Integration\Services\Sisrute\FakeSisruteClient;
use App\Modules\Integration\Services\Sisrute\HttpSisruteClient;
use App\Modules\Integration\Services\Sisrute\SisruteClient;
use App\Modules\Integration\Services\Satusehat\SatusehatClient;
use App\Modules\Integration\Services\Satusehat\SatusehatFhirClient;
use App\Modules\ModuleServiceProvider;

class IntegrationServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'integration';
    }

    public function register(): void
    {
        /*
         * PEMILIHAN ADAPTER ASLI ATAU PALSU DIPUTUSKAN DI SINI, dan sejak
         * "rumah integrasi" dipasang, dasarnya adalah CredentialStore —
         * bukan lagi config/.env langsung.
         *
         * Bedanya penting: CredentialStore membaca basis data lebih dulu,
         * jadi kredensial yang diisi lewat layar langsung berlaku tanpa
         * penerapan ulang aplikasi dan tanpa akses shell ke server. Nilai
         * di .env tetap dibaca sebagai cadangan, supaya pemasangan yang
         * terlanjur memakainya tidak mendadak berhenti bekerja.
         *
         * Sistem dianggap siap hanya kalau SELURUH kolom wajibnya terisi.
         * Sistem yang setengah terisi tetap memakai adapter palsu: ia akan
         * gagal pada panggilan pertama dengan pesan yang tidak masuk akal,
         * dan itu jauh lebih membingungkan daripada sistem yang jelas
         * belum disetel.
         *
         * Adapter palsu deterministik memastikan seluruh alur aplikasi —
         * formulir, validasi, ledger idempoten, penanganan galat — tetap
         * bisa dikerjakan dan diuji tanpa tergantung API pihak luar.
         */
        $this->app->singleton(CredentialStore::class);
        $this->app->singleton(BpjsClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsClient();
            }

            return new BpjsVclaimClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter rujukan dipisah dari BpjsClient (domain L item A):
         * antarmuka yang membesar terus akhirnya diimplementasikan
         * setengah-setengah dengan method yang melempar "belum didukung".
         * Binding-nya mengikuti pola yang sama — palsu selama kredensial
         * kosong, asli begitu terisi, tanpa kode lain berubah.
         */
        $this->app->singleton(BpjsReferralClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsReferralClient();
            }

            return new BpjsVclaimReferralClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter pencarian & riwayat peserta (domain L item J) — alasan
         * pemisahannya sama seperti adapter rujukan di atas.
         */
        $this->app->singleton(BpjsMemberClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsMemberClient();
            }

            return new BpjsVclaimMemberClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter kecelakaan & Jasa Raharja (domain L item K).
         */
        $this->app->singleton(BpjsAccidentClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsAccidentClient();
            }

            return new BpjsVclaimAccidentClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter Surat PRI & reklasifikasi SEP (domain L item L).
         */
        $this->app->singleton(BpjsAdmissionClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsAdmissionClient();
            }

            return new BpjsVclaimAdmissionClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter Apotek Online BPJS (domain L item M).
         */
        $this->app->singleton(BpjsApotekClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsApotekClient();
            }

            return new BpjsApolApotekClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter Smart Klaim FHIR BPJS (domain L item N).
         */
        $this->app->singleton(BpjsSmartClaimClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeBpjsSmartClaimClient();
            }

            return new BpjsFhirSmartClaimClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        /*
         * Adapter Sisrute Kemenkes (domain L item P). Kredensialnya
         * TERSENDIRI, bukan kredensial BPJS: Sisrute sistem Kemenkes dengan
         * skema otentikasi sendiri, dan menumpang kredensial BPJS akan
         * membuat kegagalannya tampak seperti kredensial BPJS yang salah.
         */
        $this->app->singleton(SisruteClient::class, function () {
            if (! $this->kredensial()->isReady('sisrute')) {
                return new FakeSisruteClient();
            }

            return new HttpSisruteClient(
                baseUrl: (string) $this->kredensial()->get('sisrute', 'base_url'),
                username: (string) $this->kredensial()->get('sisrute', 'username'),
                password: (string) $this->kredensial()->get('sisrute', 'password'),
                facilityCode: (string) $this->kredensial()->get('sisrute', 'facility_code'),
            );
        });

        $this->app->singleton(AplicaresClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeAplicaresClient();
            }

            return new BpjsAplicaresClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
                ppkCode: (string) $this->kredensial()->get('bpjs', 'ppk_code'),
            );
        });

        /*
         * Grouper INA-CBG. Selama kredensial kosong dipakai FakeClaimClient,
         * yang sengaja mengembalikan kode CBG mencolok palsu (diawali "X-")
         * supaya tidak pernah tertukar dengan kode asli.
         */
        $this->app->singleton(ClaimClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeClaimClient();
            }

            return new BpjsClaimClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
                ppkCode: (string) $this->kredensial()->get('bpjs', 'ppk_code'),
            );
        });

        $this->app->singleton(QueueClient::class, function () {
            if (! $this->kredensial()->isReady('bpjs')) {
                return new FakeQueueClient();
            }

            return new BpjsQueueClient(
                baseUrl: (string) $this->kredensial()->get('bpjs', 'base_url'),
                consId: (string) $this->kredensial()->get('bpjs', 'cons_id'),
                secretKey: (string) $this->kredensial()->get('bpjs', 'secret_key'),
                userKey: (string) $this->kredensial()->get('bpjs', 'user_key'),
            );
        });

        $this->app->singleton(SatusehatClient::class, function () {
            if (! $this->kredensial()->isReady('satusehat')) {
                return new FakeSatusehatClient();
            }

            return new SatusehatFhirClient(
                baseUrl: (string) $this->kredensial()->get('satusehat', 'base_url'),
                authUrl: (string) $this->kredensial()->get('satusehat', 'auth_url'),
                clientId: (string) $this->kredensial()->get('satusehat', 'client_id'),
                clientSecret: (string) $this->kredensial()->get('satusehat', 'client_secret'),
                organizationId: (string) $this->kredensial()->get('satusehat', 'organization_id'),
            );
        });
    }

    /**
     * Penyimpan kredensial, diambil saat closure dijalankan — bukan saat
     * didaftarkan. Kalau diambil saat pendaftaran, nilainya terkunci pada
     * keadaan boot dan kredensial yang baru diisi tidak akan berlaku
     * sampai proses di-restart, persis masalah yang rumah ini dibangun
     * untuk menghilangkannya.
     */
    private function kredensial(): CredentialStore
    {
        return $this->app->make(CredentialStore::class);
    }
}
