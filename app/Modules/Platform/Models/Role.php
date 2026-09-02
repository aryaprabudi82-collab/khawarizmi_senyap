<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'platform.roles';

    protected $fillable = ['code', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'platform.role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'platform.user_role');
    }

    /** Menyamakan daftar permission peran ini dengan kode yang diberikan. */
    public function syncPermissionCodes(array $codes): void
    {
        $ids = Permission::query()->whereIn('code', $codes)->pluck('id');

        $this->permissions()->sync($ids);
    }
}
