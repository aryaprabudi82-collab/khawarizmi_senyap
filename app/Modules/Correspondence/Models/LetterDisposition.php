<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterDisposition extends Model
{
    protected $table = 'correspondence.letter_dispositions';

    protected $guarded = ['id'];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(IncomingLetter::class, 'incoming_letter_id');
    }

    public function indexTerm(): BelongsTo
    {
        return $this->belongsTo(LetterIndexTerm::class, 'index_term_id');
    }

    /**
     * Tenggat lewat dan belum selesai. DIHITUNG dari tenggat — disposisi
     * yang tidak bisa dilaporkan terlambat tidak pernah ditagih siapa pun.
     */
    public function terlambat(): bool
    {
        return $this->completed_at === null
            && $this->due_date !== null
            && $this->due_date->isBefore(now()->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
