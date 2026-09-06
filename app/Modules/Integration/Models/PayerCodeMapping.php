<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan satu kode lokal ke kode milik penjamin.
 *
 * Sengaja TERPISAH dari satusehat_code_mappings: pemetaan SATUSEHAT
 * menunjuk terminologi standar dan wajib menyebut sistem kodenya,
 * sedangkan pemetaan penjamin menunjuk kode milik penjamin itu sendiri.
 * Lihat catatan migrasinya.
 */
class PayerCodeMapping extends Model
{
    protected $table = 'integration.payer_code_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'mapped_at' => 'datetime'];
    }

    public function isMapped(): bool
    {
        return $this->payer_code !== null;
    }
}
