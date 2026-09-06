<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu kolom kredensial sistem luar.
 *
 * value TERENKRIPSI lewat cast: isinya tidak terbaca dari basis data
 * maupun salinan cadangan. Enkripsinya bergantung pada APP_KEY —
 * kehilangan APP_KEY berarti kehilangan seluruh kredensial, dan itu
 * dinyatakan terang di layarnya.
 *
 * tail sengaja TIDAK terenkripsi: empat huruf terakhir cukup untuk
 * memastikan yang terpasang benar, tidak cukup untuk menyalinnya, dan
 * menyimpannya terpisah membuat layar tidak perlu mendekripsi apa pun.
 */
class IntegrationCredential extends Model
{
    protected $table = 'integration.integration_credentials';

    protected $guarded = ['id'];

    /** Nilai tidak pernah ikut serialisasi — termasuk ke log dan respons JSON. */
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
