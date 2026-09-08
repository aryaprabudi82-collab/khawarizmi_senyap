<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fine extends Model
{
    public const JENIS_KETERLAMBATAN = 'keterlambatan';

    public const JENIS_KERUSAKAN = 'kerusakan';

    public const JENIS_KEHILANGAN = 'kehilangan';

    public const JENIS_LAIN = 'lain';

    public const JENIS = [
        self::JENIS_KETERLAMBATAN, self::JENIS_KERUSAKAN,
        self::JENIS_KEHILANGAN, self::JENIS_LAIN,
    ];

    protected $table = 'library.fines';

    protected $guarded = ['id'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function fineType(): BelongsTo
    {
        return $this->belongsTo(FineType::class, 'fine_type_id');
    }

    /** Belum dibayar dan belum dibebaskan. */
    public function tertunggak(): bool
    {
        return $this->paid_at === null && $this->waived_at === null;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'charged_at' => 'datetime',
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }
}
