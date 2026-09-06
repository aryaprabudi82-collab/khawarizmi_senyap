<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu klaim ke BPJS.
 *
 * cbg_code dan cbg_tariff SELALU berasal dari jawaban grouper Kemenkes,
 * tidak pernah dihitung di sini. diagnoses dan procedures adalah SALINAN
 * yang dibekukan saat klaim dikirim — lihat catatan migrasinya.
 */
class Claim extends Model
{
    protected $table = 'integration.claims';

    protected $guarded = ['id'];

    public const DRAF = 'draf';
    public const TERKIRIM = 'terkirim';
    public const DIKEMBALIKAN = 'dikembalikan';
    public const TERVERIFIKASI = 'terverifikasi';
    public const DITOLAK = 'ditolak';
    public const BATAL = 'batal';

    public const INACBG = 'inacbg';
    public const SMART_KLAIM = 'smart-klaim';
    public const JASA_RAHARJA = 'jasa-raharja';
    public const APOTEK = 'apotek';

    protected function casts(): array
    {
        return [
            'admitted_on' => 'date',
            'discharged_on' => 'date',
            'diagnoses' => 'array',
            'procedures' => 'array',
            'hospital_charge' => 'decimal:2',
            'cbg_tariff' => 'decimal:2',
            'grouper_response' => 'array',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Selisih tarif CBG terhadap biaya rumah sakit.
     *
     * Negatif berarti klaim ini merugi: tarif CBG dibayar tetap sedangkan
     * biaya rumah sakit berbeda tiap pasien. Angka ini yang paling sering
     * tidak terlihat sampai kerugiannya menumpuk.
     */
    public function margin(): ?float
    {
        if ($this->cbg_tariff === null) {
            return null;
        }

        return round((float) $this->cbg_tariff - (float) $this->hospital_charge, 2);
    }

    public function isLoss(): bool
    {
        $margin = $this->margin();

        return $margin !== null && $margin < 0;
    }
}
