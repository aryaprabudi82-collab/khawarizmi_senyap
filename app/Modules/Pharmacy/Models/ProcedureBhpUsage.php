<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** penggunaan_bhp_ok — BHP dipakai saat tindakan di OK/VK. */
class ProcedureBhpUsage extends Model
{
    protected $table = 'pharmacy.procedure_bhp_usages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcedureBhpUsageItem::class, 'usage_id');
    }
}
