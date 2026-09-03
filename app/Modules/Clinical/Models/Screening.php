<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

class Screening extends Model
{
    public const FALL_RISK_LEVELS = ['rendah', 'sedang', 'tinggi'];

    protected $table = 'clinical.screenings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pain_score' => 'integer',
            'nutrition_at_risk' => 'boolean',
            'infectious_symptom' => 'boolean',
            'screened_at' => 'datetime',
        ];
    }

    /** Skrining "berat" kalau ada satu saja domain yang menandakan risiko — dipakai menyorot di layar. */
    public function hasFlags(): bool
    {
        return $this->fall_risk_level !== 'rendah'
            || $this->pain_score >= 4
            || $this->nutrition_at_risk
            || $this->infectious_symptom;
    }
}
