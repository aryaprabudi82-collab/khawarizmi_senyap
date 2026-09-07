<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penilaian APGAR satu bayi pada satu menit penilaian.
 *
 * LIMA KOMPONEN TERSIMPAN SENDIRI-SENDIRI, JUMLAHNYA DIHITUNG. Khanza
 * menyimpan seluruhnya sebagai apgar_score varchar(20), sehingga tidak
 * ada yang bisa memeriksa apakah angkanya benar dan tidak ada yang bisa
 * tahu komponen mana yang rendah — padahal komponen itulah yang
 * menentukan tindakan resusitasi.
 */
class DeliveryBabyApgarScore extends Model
{
    protected $table = 'clinical.delivery_baby_apgar_scores';

    protected $guarded = ['id'];

    /** Menit penilaian baku: 1, 5, dan 10. */
    public const MENIT = [1, 5, 10];

    /** Menit yang wajib dinilai pada setiap bayi lahir hidup. */
    public const MENIT_WAJIB = [1, 5];

    public const KOMPONEN = [
        'appearance' => 'Warna kulit',
        'pulse' => 'Denyut jantung',
        'grimace' => 'Refleks terhadap rangsang',
        'activity' => 'Tonus otot',
        'respiration' => 'Usaha napas',
    ];

    /** Jumlah kelima komponen — dihitung, tidak disimpan. */
    public function total(): int
    {
        return (int) array_sum(array_map(
            fn ($komponen) => (int) $this->{$komponen},
            array_keys(self::KOMPONEN),
        ));
    }

    /**
     * Tafsiran baku APGAR.
     *
     * 0-3 asfiksia berat, 4-6 asfiksia sedang, 7-10 normal.
     */
    public function interpretation(): string
    {
        $nilai = $this->total();

        return match (true) {
            $nilai <= 3 => 'asfiksia-berat',
            $nilai <= 6 => 'asfiksia-sedang',
            default => 'normal',
        };
    }

    /**
     * Komponen yang bernilai di bawah dua.
     *
     * Inilah yang dibaca saat memutuskan resusitasi, dan yang hilang
     * begitu APGAR disimpan sebagai satu angka.
     *
     * @return array<int, string>
     */
    public function weakComponents(): array
    {
        $lemah = [];

        foreach (self::KOMPONEN as $kolom => $label) {
            if ((int) $this->{$kolom} < 2) {
                $lemah[] = $label;
            }
        }

        return $lemah;
    }

    public function baby(): BelongsTo
    {
        return $this->belongsTo(DeliveryBaby::class, 'baby_id');
    }
}
