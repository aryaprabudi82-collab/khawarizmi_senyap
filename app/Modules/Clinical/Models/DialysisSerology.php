<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Status serologi pasien dialisis.
 *
 * MELEKAT PADA PASIEN, BUKAN PADA SESI. Khanza menyimpan hbsag, hiv, dan
 * hcv pada setiap baris hemodialisa — pasien yang cuci darah dua kali
 * seminggu selama tiga tahun meninggalkan lebih dari tiga ratus salinan
 * yang bisa saling bertentangan, sementara keputusan yang bergantung
 * padanya berat: pasien HBsAg reaktif wajib memakai mesin terpisah.
 */
class DialysisSerology extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.dialysis_serologies';

    protected $guarded = ['id'];

    public const REAKTIF = 'reaktif';

    public const NON_REAKTIF = 'non-reaktif';

    public const BELUM_DIPERIKSA = 'belum-diperiksa';

    public const HASIL = [
        self::REAKTIF => 'Reaktif',
        self::NON_REAKTIF => 'Non-reaktif',
        self::BELUM_DIPERIKSA => 'Belum diperiksa',
    ];

    /**
     * Masa berlaku pemeriksaan serologi, dalam bulan.
     *
     * Panduan pengendalian infeksi unit dialisis menghendaki pemeriksaan
     * ulang berkala. Satu konstanta, dan masa berlakunya DIHITUNG dari
     * tanggal periksa — bukan disimpan sebagai kolom yang basi begitu
     * aturannya berubah.
     */
    public const MASA_BERLAKU_BULAN = 6;

    protected function casts(): array
    {
        return [
            'tested_on' => 'date',
        ];
    }

    public function expiresOn(): Carbon
    {
        return $this->tested_on->copy()->addMonths(self::MASA_BERLAKU_BULAN);
    }

    public function isValid(?Carbon $on = null): bool
    {
        return $this->expiresOn()->greaterThanOrEqualTo($on ?? now());
    }

    /**
     * Pasien menuntut mesin terpisah.
     *
     * HBsAg reaktif adalah alasan yang diakui luas untuk mesin
     * terdedikasi. HCV dan HIV ditangani dengan kewaspadaan standar,
     * jadi tidak ikut memaksa pemisahan mesin di sini — kalau kebijakan
     * RSP UI menghendaki lain, yang berubah cuma method ini.
     */
    public function needsDedicatedMachine(): bool
    {
        return $this->hbsag === self::REAKTIF;
    }

    /** Ada hasil yang reaktif, apa pun jenisnya. */
    public function hasReactiveResult(): bool
    {
        return in_array(self::REAKTIF, [$this->hbsag, $this->anti_hcv, $this->anti_hiv], true);
    }
}
