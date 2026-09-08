<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan peminjaman, berversi.
 *
 * Khanza menyimpannya sebagai satu baris tanpa kunci, jadi mengubah lama
 * pinjam MENIMPA aturan lama dan pertanyaan "aturan mana yang berlaku
 * waktu itu" tidak punya jawaban. Di sini yang lama dinonaktifkan.
 */
class LoanPolicy extends Model
{
    protected $table = 'library.loan_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'daily_fine' => 'decimal:2',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
