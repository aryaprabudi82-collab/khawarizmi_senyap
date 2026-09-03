<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CssdCirculation extends Model
{
    public const STATUS_KOTOR = 'kotor';
    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_STERIL = 'steril';
    public const STATUS_DIDISTRIBUSIKAN = 'didistribusikan';

    protected $table = 'asset.cssd_circulations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'sterilized_at' => 'datetime',
            'distributed_at' => 'datetime',
        ];
    }

    public function cssdItem(): BelongsTo
    {
        return $this->belongsTo(CssdItem::class);
    }
}
