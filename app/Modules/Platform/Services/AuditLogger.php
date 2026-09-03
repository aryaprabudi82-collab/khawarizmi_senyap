<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Jejak audit generik untuk aksi administratif (Permenkes 24/2022 mensyaratkan
 * rekam medis elektronik punya jejak audit siapa-mengubah-apa-kapan). Dipakai
 * di luar login/logout (yang sudah punya pencatatannya sendiri di AuthController)
 * supaya UserController dan RoleController tidak menduplikasi query insert ini.
 */
class AuditLogger
{
    public function log(
        Request $request,
        string $context,
        string $action,
        ?Model $subject = null,
        ?array $changes = null,
    ): void {
        /** @var User|null $user */
        $user = $request->user();

        DB::table('platform.audit_logs')->insert([
            'created_at' => now(),
            'user_id' => $user?->id,
            'username' => $user?->username,
            'context' => $context,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'changes' => $changes !== null ? json_encode($changes) : null,
        ]);
    }
}
