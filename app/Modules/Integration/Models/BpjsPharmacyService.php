<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pelayanan obat apotek BPJS (PRB, kronis, atau kemoterapi).
 *
 * items DIBEKUKAN saat dikirim: rincian obat yang dilaporkan ke BPJS harus
 * tetap sama seperti yang dilaporkan, sekalipun harga atau nama sediaannya
 * berubah di master farmasi setelahnya.
 */
class BpjsPharmacyService extends Model
{
    protected $table = 'integration.bpjs_pharmacy_services';

    protected $guarded = ['id'];

    public const PRB = 'prb';
    public const KRONIS = 'kronis';
    public const KEMOTERAPI = 'kemoterapi';

    protected function casts(): array
    {
        return [
            'served_on' => 'date',
            'total_amount' => 'decimal:2',
            'items' => 'array',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(PrbEnrollment::class, 'prb_enrollment_id');
    }
}
