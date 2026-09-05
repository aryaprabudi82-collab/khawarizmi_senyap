<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    protected $table = 'catalog.tariffs';

    protected $fillable = [
        'service_id', 'payer_id', 'care_class',
        'amount', 'amount_returning', 'valid_from', 'valid_until',
        // Komponen jasa medis (domain I item C). Wajib terdaftar di sini:
        // model ini memakai daftar putih, jadi kolom yang tidak disebut
        // akan dibuang diam-diam saat create/update.
        'share_facility', 'share_bhp', 'share_doctor',
        'share_paramedic', 'share_kso', 'share_management',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_returning' => 'decimal:2',
            'share_facility' => 'decimal:2',
            'share_bhp' => 'decimal:2',
            'share_doctor' => 'decimal:2',
            'share_paramedic' => 'decimal:2',
            'share_kso' => 'decimal:2',
            'share_management' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class);
    }
}
