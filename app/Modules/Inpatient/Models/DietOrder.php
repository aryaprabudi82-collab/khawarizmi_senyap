<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DietOrder extends Model
{
    public const TYPES = [
        'biasa', 'lunak', 'cair', 'bubur', 'diabetes',
        'rendah-garam', 'rendah-lemak', 'tinggi-protein', 'bebas-gluten', 'lainnya',
    ];

    public const STATUS_AKTIF = 'aktif';
    public const STATUS_DIHENTIKAN = 'dihentikan';

    protected $table = 'inpatient.diet_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
