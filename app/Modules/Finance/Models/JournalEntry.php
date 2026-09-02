<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header jurnal. Hanya menerima INSERT lewat PostingService::post() —
 * tidak ada jalur update. Koreksi salah posting memakai entri pembalik.
 */
class JournalEntry extends Model
{
    protected $table = 'finance.journal_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
