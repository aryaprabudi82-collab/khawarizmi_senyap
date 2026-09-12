<?php

namespace App\Modules\Keuangan\MasterData\Providers;

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
}
