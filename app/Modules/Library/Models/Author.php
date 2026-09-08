<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Author extends Model
{
    protected $table = 'library.authors';

    protected $guarded = ['id'];

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'library.collection_authors', 'author_id', 'collection_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
