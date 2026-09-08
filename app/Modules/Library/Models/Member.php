<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    public const JENIS = ['pasien', 'pegawai', 'umum'];

    protected $table = 'library.members';

    protected $guarded = ['id'];

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'member_id');
    }

    /**
     * Masa berlaku habis. DIHITUNG dari tanggal, bukan penanda yang
     * disimpan: penanda yang disimpan menuntut ada yang menjalankannya
     * tiap hari, dan anggota yang kedaluwarsa pada hari sistem itu mati
     * akan selamanya tampak masih berlaku.
     */
    public function kedaluwarsa(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore(now()->startOfDay());
    }

    /** Denda yang belum dibayar maupun dibebaskan. */
    public function dendaTertunggak(): float
    {
        return (float) $this->fines()
            ->whereNull('paid_at')
            ->whereNull('waived_at')
            ->sum('amount');
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_at' => 'date',
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
