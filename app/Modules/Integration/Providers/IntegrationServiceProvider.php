<?php

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Services\Bpjs\BpjsClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsClient;
use App\Modules\Integration\Services\Satusehat\FakeSatusehatClient;
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
         * Tidak ada sandbox BPJS/SATUSEHAT yang bisa dipanggil dari lingkungan
         * pengembangan ini — kredensialnya (consumer id/secret VClaim, client
         * id/secret SATUSEHAT) hanya diterbitkan untuk faskes yang sudah
         * terdaftar. Kalau kredensial belum diisi di .env, adapter otomatis
         * jatuh ke implementasi palsu yang deterministik, supaya alur
         * aplikasi (form, validasi, ledger idempoten) tetap bisa dikerjakan
         * dan diuji tanpa tergantung API pihak luar tersedia.
         *
         * Begitu kredensial nyata terisi, tidak ada kode lain yang perlu
         * berubah — binding ini yang menentukan implementasi mana yang aktif.
         */
        $this->app->singleton(BpjsClient::class, function () {
            if (blank(config('services.bpjs.cons_id'))) {
                return new FakeBpjsClient();
            }

            return new BpjsVclaimClient(
                baseUrl: (string) config('services.bpjs.base_url'),
                consId: (string) config('services.bpjs.cons_id'),
                secretKey: (string) config('services.bpjs.secret_key'),
                userKey: (string) config('services.bpjs.user_key'),
            );
        });

        $this->app->singleton(SatusehatClient::class, function () {
            if (blank(config('services.satusehat.client_id'))) {
                return new FakeSatusehatClient();
            }

            return new SatusehatFhirClient(
                baseUrl: (string) config('services.satusehat.base_url'),
                authUrl: (string) config('services.satusehat.auth_url'),
                clientId: (string) config('services.satusehat.client_id'),
                clientSecret: (string) config('services.satusehat.client_secret'),
                organizationId: (string) config('services.satusehat.organization_id'),
            );
        });
    }
}
