<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class IcareHistoryLookup extends Model
{
    protected $table = 'integration.icare_history_lookups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['found' => 'boolean', 'raw_response' => 'array'];
    }
}
