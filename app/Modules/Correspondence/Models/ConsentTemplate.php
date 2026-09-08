<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsentTemplate extends Model
{
    protected $table = 'correspondence.consent_templates';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(ConsentTemplateItem::class, 'template_id')->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
