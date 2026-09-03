<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingLetter extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_TERKIRIM = 'terkirim';

    protected $table = 'correspondence.outgoing_letters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'date'];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
