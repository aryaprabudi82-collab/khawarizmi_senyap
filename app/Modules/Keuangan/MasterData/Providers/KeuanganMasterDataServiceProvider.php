<?php

namespace App\Modules\Keuangan\MasterData\Providers;

use App\Modules\Keuangan\MasterData\Application\TariffSourceRegistry;
use App\Modules\Keuangan\MasterData\Infrastructure\Resolvers\CatalogTariffResolver;
use App\Modules\Keuangan\MasterData\Infrastructure\Resolvers\InpatientTariffResolver;
use App\Modules\Keuangan\MasterData\Infrastructure\Resolvers\ParkingTariffResolver;
use App\Modules\Keuangan\MasterData\Infrastructure\Resolvers\PharmacyTariffResolver;
use App\Modules\Keuangan\MasterData\Infrastructure\Resolvers\RetailTariffResolver;
use App\Modules\ModuleServiceProvider;

/**
 * Sub-konteks `keuangan_master` — Modul A, Master Data Keuangan.
 *
 * Bagian dari DOMAIN keuangan, bukan konteks yang berdiri sendiri
 * (keputusan KA-2). Skemanya terpisah supaya batas antar sub-konteks
 * tetap ditegakkan uji — lihat docs/keuangan/03-KEPUTUSAN-ARSITEKTUR.md.
 */
class KeuanganMasterDataServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'keuangan_master';
    }

    /** Bersarang di bawah domain keuangan, bukan `KeuanganMaster` di akar. */
    protected function moduleDirectory(): string
    {
        return 'Keuangan/MasterData';
    }

    /**
     * Registry tarif dirakit DI SINI, dan itu disengaja.
     *
     * Resolver yang didaftarkan sendiri-sendiri lewat auto-discovery
     * membuat "konteks mana saja yang tarifnya bisa ditanyakan" jadi
     * pertanyaan yang jawabannya tersebar di lima berkas. Merakitnya di
     * satu tempat berarti konteks yang resolvernya lupa dipasang terlihat
     * sebagai baris yang hilang di daftar ini — bukan sebagai galat yang
     * baru muncul saat ada yang menagih.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(TariffSourceRegistry::class, fn () => new TariffSourceRegistry([
            new CatalogTariffResolver,
            new PharmacyTariffResolver,
            new InpatientTariffResolver,
            new RetailTariffResolver,
            new ParkingTariffResolver,
        ]));
    }
}
