<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeRecord extends Model
{
    public const TYPE_PENGHARGAAN = 'penghargaan';
    public const TYPE_PERINGATAN = 'peringatan';
    public const TYPE_KEGIATAN_ILMIAH = 'kegiatan_ilmiah';
    public const TYPE_PENELITIAN = 'penelitian';

    public const TYPES = [
        self::TYPE_PENGHARGAAN, self::TYPE_PERINGATAN, self::TYPE_KEGIATAN_ILMIAH, self::TYPE_PENELITIAN,
    ];

    protected $table = 'hr.employee_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['record_date' => 'date'];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PENGHARGAAN => 'Penghargaan',
            self::TYPE_PERINGATAN => 'Surat Peringatan',
            self::TYPE_KEGIATAN_ILMIAH => 'Kegiatan Ilmiah & Pelatihan',
            self::TYPE_PENELITIAN => 'Penelitian',
            default => $type,
        };
    }
}
