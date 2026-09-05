<?php

namespace App\Modules\Parking\Models;

use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    public const BASIS_JAM = 'jam';
    public const BASIS_HARIAN = 'harian';

    protected $table = 'parking.rates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fee' => 'integer',
            'free_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Biaya satu sesi. Basis "jam" dibulatkan ke atas — parkir 61 menit
     * ditagih 2 jam, sebagaimana lazimnya tarif parkir; basis "harian"
     * flat per hari kalender yang dilewati, bukan per 24 jam, supaya
     * menginap semalam tetap terhitung dua hari seperti praktik di
     * lapangan.
     */
    public function feeFor(int $durationMinutes): int
    {
        $ditagih = max(0, $durationMinutes - $this->free_minutes);

        if ($ditagih === 0) {
            return 0;
        }

        $satuan = $this->basis === self::BASIS_HARIAN
            ? (int) ceil($ditagih / (24 * 60))
            : (int) ceil($ditagih / 60);

        return $satuan * $this->fee;
    }
}
