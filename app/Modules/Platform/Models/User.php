<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public const SUPER_ADMIN = 'super-admin';

    protected $table = 'platform.users';

    protected $fillable = [
        'username', 'nip', 'name', 'email', 'password',
        'is_active', 'must_change_password', 'mfa_required',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<string, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'mfa_required' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform.user_role');
    }

    public function hasRole(string $code): bool
    {
        return $this->roles->contains('code', $code);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }

    /**
     * Seluruh kode permission yang diwarisi dari peran-peran pengguna ini.
     *
     * Dimuat sekali per instance: sebuah layar bisa memeriksa belasan permission
     * dan tidak boleh menghasilkan belasan query.
     */
    public function permissionCodes(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $codes = Permission::query()
            ->join('platform.role_permission as rp', 'rp.permission_id', '=', 'platform.permissions.id')
            ->join('platform.user_role as ur', 'ur.role_id', '=', 'rp.role_id')
            ->where('ur.user_id', $this->getKey())
            ->distinct()
            ->pluck('platform.permissions.code')
            ->all();

        return $this->permissionCache = array_combine($codes, $codes) ?: [];
    }

    public function hasPermission(string $code): bool
    {
        return isset($this->permissionCodes()[$code]);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
