<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu formulir asesmen/skrining yang sudah diisi.
 *
 * template_version, template_name, skor, dan tafsirnya DIBEKUKAN: template
 * boleh direvisi setelahnya, yang tertulis di rekam medis tidak ikut
 * berubah.
 */
class FormResponse extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.form_responses';

    protected $guarded = ['id'];

    public const DRAF = 'draf';
    public const FINAL = 'final';
    public const DIBATALKAN = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'recorded_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /** Hanya yang final yang dianggap bagian rekam medis. */
    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }
}
