<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

class InfectionEvent extends Model
{
    protected $table = 'quality.infection_events';

    protected $guarded = ['id'];

    /** Jenis infeksi yang disurvei rutin berikut penyebutnya masing-masing. */
    public const JENIS = [
        'vap' => 'VAP — Pneumonia terkait ventilator',
        'iadp' => 'IADP — Infeksi aliran darah primer',
        'isk' => 'ISK — Infeksi saluran kemih terkait kateter',
        'ido' => 'IDO — Infeksi daerah operasi',
        'plebitis' => 'Plebitis',
        'dekubitus' => 'Dekubitus',
    ];

    /**
     * Alat yang jadi penyebut tiap jenis infeksi.
     *
     * IDO, plebitis, dan dekubitus tidak terkait alat invasif, jadi
     * penyebutnya hari-rawat — bukan hari-alat.
     */
    public const PENYEBUT = [
        'vap' => 'ventilator_days',
        'iadp' => 'central_line_days',
        'isk' => 'urinary_catheter_days',
        'ido' => 'patient_days',
        'plebitis' => 'peripheral_line_days',
        'dekubitus' => 'patient_days',
    ];

    public const ALAT = ['ventilator', 'central-line', 'kateter-urin', 'infus-perifer', 'tanpa-alat'];

    protected function casts(): array
    {
        return ['onset_on' => 'date'];
    }
}
