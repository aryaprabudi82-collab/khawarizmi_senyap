<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan pengiriman satu tahap antrean.
 *
 * sent_at adalah KAPAN TAHAP DIKIRIM, bukan kapan tahapnya terjadi —
 * waktunya sendiri ada di encounter.registrations.
 */
class BpjsQueueTask extends Model
{
    protected $table = 'integration.bpjs_queue_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'success' => 'boolean'];
    }
}
