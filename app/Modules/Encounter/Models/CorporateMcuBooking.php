<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Model;

class CorporateMcuBooking extends Model
{
    public const STATUS_DIJADWALKAN = 'dijadwalkan';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'encounter.corporate_mcu_bookings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'booked_at' => 'datetime',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DIJADWALKAN => 'Dijadwalkan',
            self::STATUS_SELESAI => 'Selesai',
            self::STATUS_DIBATALKAN => 'Dibatalkan',
            default => $status,
        };
    }
}
