<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header jurnal. Hanya menerima INSERT — tidak ada jalur update. Ditulis
 * dari PostingService (invoice/piutang) dan DepositService (deposit_pasien),
 * masing-masing menegakkan sendiri pasangan debit=kredit yang seimbang.
 * Koreksi salah posting memakai entri pembalik, bukan menyunting yang lama.
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
