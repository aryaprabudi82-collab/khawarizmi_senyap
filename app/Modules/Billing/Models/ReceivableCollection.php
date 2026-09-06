<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu upaya penagihan piutang pasien.
 *
 * Ini catatan KONTAK, bukan catatan uang. Pembayarannya tetap lewat
 * billing.payments atas tagihannya — lihat catatan migrasinya.
 */
class ReceivableCollection extends Model
{
    protected $table = 'billing.patient_receivable_collections';

    protected $guarded = ['id'];

    public const HASIL = [
        'dijanjikan' => 'Pasien berjanji membayar',
        'menolak' => 'Pasien menolak',
        'tidak-terhubung' => 'Tidak berhasil dihubungi',
        'dibayar-sebagian' => 'Dibayar sebagian',
        'lunas' => 'Dilunasi',
    ];

    public const KANAL = ['telepon', 'surat', 'kunjungan', 'pesan'];

    protected function casts(): array
    {
        return [
            'contacted_on' => 'date',
            'promised_on' => 'date',
            'validated_at' => 'datetime',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(PatientReceivable::class, 'receivable_id');
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }
}
