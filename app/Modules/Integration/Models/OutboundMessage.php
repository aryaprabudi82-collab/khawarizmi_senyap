<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class OutboundMessage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'integration.outbound_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_event_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
