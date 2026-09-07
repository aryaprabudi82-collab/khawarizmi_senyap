<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tanda vital dan pengukuran.
 *
 * Tabelnya dipartisi per bulan dengan primary key (id, observed_at). Eloquent
 * cukup memakai id karena identity-nya unik lintas partisi, dan baris observasi
 * tidak pernah diubah — hanya ditambah.
 */
class Observation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clinical.observations';

    protected $guarded = ['id'];

    /*
     * KATALOG PENGUKURAN DIPINDAH KE DATA (domain M item D).
     *
     * Dulu ia konstanta di kelas ini dengan sembilan pengukuran. Domain M
     * menuntut jauh lebih banyak — GCS, setelan ventilator, gula darah,
     * denyut jantung janin — dan yang lebih menentukan: rentang normalnya
     * BERBEDA menurut kelompok umur. Laju napas 40 normal pada neonatus
     * dan gawat pada dewasa, dan konstanta tidak bisa menyatakan itu.
     *
     * Sekarang katalognya ada di catalog.observation_codes berikut
     * panelnya, dan rentang yang berlaku dibaca lewat
     * ObservationCatalogContext. isAbnormal() ikut pindah ke sana karena
     * jawabannya bergantung pada PANEL, bukan cuma pada kodenya.
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'value_numeric' => 'decimal:2',
            'is_abnormal' => 'boolean',
        ];
    }

}
