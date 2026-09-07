<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pemeriksaan gula darah berikut obat yang diberikan atasnya.
 *
 * Keduanya dicatat bersama, sebagaimana catatan_cek_gds Khanza — dan itu
 * benar: dosis insulin ditentukan oleh angka gula darah pada saat itu
 * juga, dan memisahkannya memaksa pasangan angka-dosis dicocokkan
 * kembali dari dua tabel berbeda.
 */
class GlucoseMonitoring extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.glucose_monitorings';

    protected $guarded = ['id'];

    public const LABORATORIUM = 'laboratorium';

    public const POINT_OF_CARE = 'point-of-care';

    public const WAKTU = [
        'puasa' => 'Puasa',
        'sebelum-makan' => 'Sebelum makan',
        '2-jam-setelah-makan' => 'Dua jam setelah makan',
        'sewaktu' => 'Sewaktu',
        'sebelum-tidur' => 'Sebelum tidur',
    ];

    /** Ambang hipoglikemia yang menuntut tindakan segera. */
    public const AMBANG_HIPOGLIKEMIA = 70;

    /** Ambang hipoglikemia berat. */
    public const AMBANG_HIPOGLIKEMIA_BERAT = 54;

    /** Ambang hiperglikemia yang lazim menuntut peninjauan terapi. */
    public const AMBANG_HIPERGLIKEMIA = 250;

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'glucose_mg_dl' => 'integer',
        ];
    }

    public function isHypoglycaemia(): bool
    {
        return $this->glucose_mg_dl < self::AMBANG_HIPOGLIKEMIA;
    }

    public function isSevereHypoglycaemia(): bool
    {
        return $this->glucose_mg_dl < self::AMBANG_HIPOGLIKEMIA_BERAT;
    }

    public function isHyperglycaemia(): bool
    {
        return $this->glucose_mg_dl > self::AMBANG_HIPERGLIKEMIA;
    }

    /**
     * Angka yang menuntut tindakan tapi tidak ada tindakan tercatat.
     *
     * Disebutkan, bukan ditolak: perawat yang menemukan gula darah 45
     * harus bisa mencatatnya SEKARANG lalu bertindak, bukan ditahan
     * formulir sampai tindakannya selesai diketik.
     */
    public function isUnactioned(): bool
    {
        return ($this->isHypoglycaemia() || $this->isHyperglycaemia())
            && blank($this->action_taken)
            && blank($this->insulin)
            && blank($this->oral_agent);
    }
}
