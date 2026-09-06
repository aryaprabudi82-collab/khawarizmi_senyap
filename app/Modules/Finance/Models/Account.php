<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public const TYPE_KAS = 'kas';
    public const TYPE_PIUTANG = 'piutang';
    public const TYPE_PENDAPATAN = 'pendapatan';
    public const TYPE_BEBAN = 'beban';
    public const TYPE_UTANG = 'utang';
    // Ditambahkan domain K item E: bagan akun yang tidak mengenal aset dan
    // modal tidak bisa menampung neraca.
    public const TYPE_ASET = 'aset';
    public const TYPE_MODAL = 'modal';

    public const JENIS = [
        self::TYPE_KAS, self::TYPE_PIUTANG, self::TYPE_PENDAPATAN,
        self::TYPE_BEBAN, self::TYPE_UTANG, self::TYPE_ASET, self::TYPE_MODAL,
    ];

    /**
     * Akun yang saldonya BERTAMBAH oleh debit.
     *
     * Dipakai buku besar untuk menyajikan saldo sesuai arah normalnya.
     * Tanpa ini setiap akun kredit-normal akan tampil negatif dan
     * pembacanya menyimpulkan ada yang rusak.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [self::TYPE_KAS, self::TYPE_PIUTANG, self::TYPE_BEBAN, self::TYPE_ASET], true);
    }

    protected $table = 'finance.chart_of_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
