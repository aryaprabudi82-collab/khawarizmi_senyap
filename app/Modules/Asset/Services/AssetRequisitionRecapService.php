<?php

namespace App\Modules\Asset\Services;

use Illuminate\Support\Facades\DB;

/**
 * rekap_pengajuan_aset_departemen — cuma 1 kode rekap di domain G item
 * B (beda dari domain D/E/F item D yang punya 9-14 kode, jadi tidak
 * dijustifikasi jadi item/layar terpisah) — dilebur jadi tab di layar
 * pengajuan yang sama.
 */
class AssetRequisitionRecapService
{
    /** Jumlah pengajuan per unit/departemen dalam rentang tanggal, dikelompokkan per status. */
    public function perDepartemen(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('asset.requisitions')
            ->whereBetween('created_at', [$dari, $akhir])
            ->selectRaw('unit_name, status, count(*) as jumlah')
            ->groupBy('unit_name', 'status')
            ->orderBy('unit_name')
            ->get()
            ->groupBy('unit_name');
    }
}
