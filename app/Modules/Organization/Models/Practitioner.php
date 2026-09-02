<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Practitioner extends Model
{
    use SoftDeletes;

    protected $table = 'organization.practitioners';

    protected $fillable = [
        'code', 'employee_number', 'name', 'title', 'specialty',
        'sip_number', 'sip_valid_until', 'phone', 'email',
        'is_active', 'active_from', 'active_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sip_valid_until' => 'date',
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    /**
     * Praktisi yang boleh melayani pada satu tanggal.
     *
     * Masa aktif dievaluasi terhadap tanggal pelayanan, bukan terhadap kolom
     * is_active saja. Khawarizmi menjalankan pembaruan massal setiap kali
     * halaman dibuka untuk menonaktifkan dokter yang lewat tanggal; di sini
     * aturannya cukup dijadikan bagian dari kueri.
     */
    public function scopeServingOn(Builder $query, \DateTimeInterface $date): Builder
    {
        $on = $date->format('Y-m-d');

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $on))
            ->where(fn (Builder $q) => $q->whereNull('active_until')->orWhere('active_until', '>=', $on));
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'organization.practitioner_units')
            ->withPivot('is_primary');
    }

    public function displayName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->name);
    }
}
