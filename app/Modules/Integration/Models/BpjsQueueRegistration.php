<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu pendaftaran antrean Mobile JKN.
 *
 * registration_id boleh kosong: pendaftaran datang dari Mobile JKN sebelum
 * pasien tiba dan kunjungannya dibuat.
 */
class BpjsQueueRegistration extends Model
{
    protected $table = 'integration.bpjs_queue_registrations';

    protected $guarded = ['id'];

    public const TERDAFTAR = 'terdaftar';
    public const DILAYANI = 'dilayani';
    public const SELESAI = 'selesai';
    public const BATAL = 'batal';

    protected function casts(): array
    {
        return ['service_date' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function isLinked(): bool
    {
        return $this->registration_id !== null;
    }
}
