<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Model;

class OperationBooking extends Model
{
    public const STATUS_DIJADWALKAN = 'dijadwalkan';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'encounter.operation_bookings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
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
