<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu dosis imunisasi yang diterima pasien.
 *
 * riwayat_imunisasi Khanza hanya punya pasien, kode vaksin, dan nomor
 * dosis — tanpa tanggal, batch, kedaluwarsa, maupun penyuntik. Lihat
 * catatan migrasi.
 */
class Immunisation extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.immunisations';

    protected $guarded = ['id'];

    public const TERCATAT = 'tercatat';

    public const DIBATALKAN = 'dibatalkan';

    public const SUMBER = [
        'rumah-sakit-ini' => 'Diberikan di rumah sakit ini',
        'fasilitas-lain' => 'Diberikan di fasilitas lain',
        'dilaporkan-keluarga' => 'Dilaporkan keluarga tanpa bukti',
    ];

    protected function casts(): array
    {
        return [
            'given_on' => 'date',
            'expires_on' => 'date',
            'dose_number' => 'integer',
        ];
    }

    /**
     * Kapan dosis berikutnya paling cepat boleh diberikan.
     *
     * DIHITUNG dari tanggal pemberian ditambah jarak antar dosis — dan
     * inilah yang tidak mungkin dilakukan pada riwayat_imunisasi Khanza,
     * karena tanggalnya tidak ada sama sekali.
     */
    public function nextDoseDueOn(?int $intervalDays): ?Carbon
    {
        if ($intervalDays === null || $intervalDays <= 0) {
            return null;
        }

        return $this->given_on->copy()->addDays($intervalDays);
    }

    /**
     * Catatan yang berasal dari luar rumah sakit ini.
     *
     * Dibedakan dengan sengaja: dosis yang dilaporkan keluarga tanpa
     * bukti tidak setara dengan dosis yang kita suntikkan sendiri, dan
     * cakupan imunisasi yang mencampur keduanya melebih-lebihkan
     * capaian.
     */
    public function isSelfReported(): bool
    {
        return $this->source === 'dilaporkan-keluarga';
    }

    public function isActive(): bool
    {
        return $this->status === self::TERCATAT;
    }
}
