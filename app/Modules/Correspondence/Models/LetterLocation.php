<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokasi arsip sebagai SATU POHON, bukan empat daftar datar.
 *
 * Khanza menyimpan ruang, lemari, rak, dan map sebagai empat tabel
 * terpisah lalu menaruh keempat kodenya berdampingan pada surat. Susunan
 * itu tidak bisa menyatakan bahwa rak 3 berada di dalam almari B di dalam
 * ruang arsip — dan karena tidak bisa, ia juga tidak bisa menolak
 * kombinasi yang mustahil.
 */
class LetterLocation extends Model
{
    public const JENJANG_RUANG = 'ruang';

    public const JENJANG_ALMARI = 'almari';

    public const JENJANG_RAK = 'rak';

    public const JENJANG_MAP = 'map';

    /** Urutan berjenjang; induk sebuah jenjang adalah yang tepat di atasnya. */
    public const JENJANG = [
        self::JENJANG_RUANG, self::JENJANG_ALMARI, self::JENJANG_RAK, self::JENJANG_MAP,
    ];

    protected $table = 'correspondence.letter_locations';

    protected $guarded = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Jenjang yang sah untuk menjadi induk jenjang ini; null untuk ruang. */
    public static function jenjangInduk(string $jenjang): ?string
    {
        $posisi = array_search($jenjang, self::JENJANG, true);

        return is_int($posisi) && $posisi > 0 ? self::JENJANG[$posisi - 1] : null;
    }

    /** Jalur lengkap dari ruang sampai map, untuk ditampilkan di layar. */
    public function jalur(): string
    {
        $bagian = [$this->name];
        $induk = $this->parent;

        while ($induk !== null) {
            array_unshift($bagian, $induk->name);
            $induk = $induk->parent;
        }

        return implode(' / ', $bagian);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
