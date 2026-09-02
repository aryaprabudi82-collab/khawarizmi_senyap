<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityMapping extends Model
{
    protected $table = 'integration.identity_mappings';

    protected $fillable = [
        'target_system', 'resource_type', 'source_context', 'source_id',
        'external_id', 'external_meta', 'mapped_by', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'external_meta' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
