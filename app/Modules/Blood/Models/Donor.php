<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;

class Donor extends Model
{
    protected $table = 'blood.donors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'blocked_until' => 'date',
        ];
    }

    /** utd_cekal_darah — dicekal permanen (blocked_until kosong) atau masih dalam jangka cekal sementara. */
    public function isBlocked(): bool
    {
        if ($this->block_reason === null) {
            return false;
        }

        return $this->blocked_until === null || ! $this->blocked_until->isPast();
    }
}
