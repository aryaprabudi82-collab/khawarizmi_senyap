<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingLetter extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_TERKIRIM = 'terkirim';

    public const SIFAT = ['biasa', 'terbatas', 'rahasia', 'sangat-rahasia'];

    public const DERAJAT = ['biasa', 'segera', 'amat-segera'];

    protected $table = 'correspondence.outgoing_letters';

    protected $guarded = ['id'];

    public function classification(): BelongsTo
    {
        return $this->belongsTo(LetterClassification::class, 'classification_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LetterLocation::class, 'location_id');
    }

    public function answers(): BelongsTo
    {
        return $this->belongsTo(IncomingLetter::class, 'replies_to_letter_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    protected function casts(): array
    {
        return ['sent_at' => 'date'];
    }
}
