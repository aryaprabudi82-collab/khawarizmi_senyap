<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class ControlLetter extends Model
{
    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_SUDAH_PERIKSA = 'sudah-periksa';

    public const STATUS_BATAL = 'batal';

    protected $table = 'correspondence.control_letters';

    protected $guarded = ['id'];

    /**
     * Tanggal kontrol sudah lewat tapi pasiennya belum datang.
     *
     * DIHITUNG, bukan status keempat yang disimpan. Status "terlewat" yang
     * disimpan menuntut ada yang menjalankannya tiap hari, dan surat yang
     * terlewat pada hari sistem itu mati akan selamanya berstatus
     * menunggu.
     */
    public function terlewat(): bool
    {
        return $this->status === self::STATUS_MENUNGGU
            && $this->control_date->isBefore(now()->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'control_date' => 'date',
            'issued_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }
}
