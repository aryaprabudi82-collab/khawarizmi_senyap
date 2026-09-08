<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

/** Indeks temu balik yang dilekatkan pada disposisi surat masuk. */
class LetterIndexTerm extends Model
{
    protected $table = 'correspondence.letter_index_terms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
