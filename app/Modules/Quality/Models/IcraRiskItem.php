<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Butir daftar periksa risiko ICRA.
 *
 * Empat kode Khanza (infeksi, keselamatan, kebakaran, utilitas) jadi satu
 * tabel berkolom kategori: bentuk isiannya identik, dan memisahkannya jadi
 * empat tabel berarti empat kali menulis mekanisme yang sama.
 *
 * LAHIR KOSONG: butirnya disusun IPCN RSP UI, dan mengarangnya berarti
 * menerbitkan daftar periksa resmi yang tidak pernah ditinjau siapa pun.
 */
class IcraRiskItem extends Model
{
    public const KATEGORI_INFEKSI = 'infeksi';

    public const KATEGORI_KESELAMATAN = 'keselamatan';

    public const KATEGORI_KEBAKARAN = 'kebakaran';

    public const KATEGORI_UTILITAS = 'utilitas';

    public const KATEGORI = [
        self::KATEGORI_INFEKSI, self::KATEGORI_KESELAMATAN,
        self::KATEGORI_KEBAKARAN, self::KATEGORI_UTILITAS,
    ];

    protected $table = 'quality.icra_risk_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
