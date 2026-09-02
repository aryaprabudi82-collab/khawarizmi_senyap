<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'platform.permissions';

    protected $fillable = [
        'code', 'name', 'domain_code', 'context', 'kind', 'wave',
        'legacy_class', 'legacy_package',
    ];

    protected function casts(): array
    {
        return ['wave' => 'integer'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform.role_permission');
    }
}
