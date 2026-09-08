<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentTemplateItem extends Model
{
    protected $table = 'correspondence.consent_template_items';

    protected $guarded = ['id'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplate::class, 'template_id');
    }

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }
}
