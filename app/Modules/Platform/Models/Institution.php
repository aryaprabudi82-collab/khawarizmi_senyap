<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Identitas rumah sakit — satu baris, dan kuncinya BUKAN namanya.
 *
 * `setting` Khanza memakai `nama_instansi` sebagai PRIMARY KEY. Artinya
 * mengganti nama rumah sakit tidak mengubah rumah sakitnya, melainkan
 * MELAHIRKAN yang kedua — dan yang lama tetap di sana bersama seluruh
 * rujukan yang menempel padanya. Nama adalah atribut yang boleh berubah;
 * identitas tidak.
 */
class Institution extends Model
{
    /** Baris tunggal, dipaksa CHECK (id = 1) di basis data. */
    public const ID = 1;

    protected $table = 'platform.institution';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /**
     * Identitas yang berlaku, atau null kalau belum pernah diisi.
     *
     * Sengaja TIDAK membuat baris kosong dengan nama karangan: rumah sakit
     * yang belum mengisi identitasnya harus terbaca sebagai belum mengisi,
     * bukan sebagai rumah sakit bernama "RS" yang lalu tercetak di kuitansi.
     */
    public static function berlaku(): ?self
    {
        return static::query()->find(self::ID);
    }
}
