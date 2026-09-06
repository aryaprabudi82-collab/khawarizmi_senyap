<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivableCategory extends Model
{
    protected $table = 'finance.receivable_categories';

    protected $guarded = ['id'];

    public const JASA_PERUSAHAAN = 'jasa-perusahaan';
    public const PEMINJAMAN_UANG = 'peminjaman-uang';

    public const JENIS = [
        self::JASA_PERUSAHAAN => 'Piutang Jasa Perusahaan',
        self::PEMINJAMAN_UANG => 'Piutang Peminjaman Uang',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
