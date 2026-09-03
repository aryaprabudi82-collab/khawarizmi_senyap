<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

class K3Incident extends Model
{
    public const STATUS_DILAPORKAN = 'dilaporkan';
    public const STATUS_DITINJAU = 'ditinjau';
    public const STATUS_DITUTUP = 'ditutup';

    protected $table = 'quality.k3_incidents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
