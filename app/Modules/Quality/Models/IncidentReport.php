<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentReport extends Model
{
    public const TYPE_KPC = 'kpc';
    public const TYPE_KNC = 'knc';
    public const TYPE_KTC = 'ktc';
    public const TYPE_KTD = 'ktd';
    public const TYPE_SENTINEL = 'sentinel';

    public const STATUS_DILAPORKAN = 'dilaporkan';
    public const STATUS_DITINJAU = 'ditinjau';
    public const STATUS_DITUTUP = 'ditutup';

    protected $table = 'quality.incident_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_DITUTUP;
    }
}
