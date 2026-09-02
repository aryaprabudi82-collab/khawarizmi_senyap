<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arsip versi asesmen. Hanya menerima INSERT.
 */
class AssessmentRevision extends Model
{
    public $timestamps = false;

    protected $table = 'clinical.assessment_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'revised_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
