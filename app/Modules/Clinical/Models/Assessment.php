<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asesmen dan catatan SOAP.
 *
 * Permenkes 24/2022 menuntut rekam medis elektronik punya jejak perubahan.
 * Terjemahannya di sini: begitu difinalkan, isi asesmen tidak lagi disunting
 * di tempat. Ralat menghasilkan versi baru, dan versi lama disimpan utuh di
 * assessment_revisions.
 */
class Assessment extends Model
{
    use SoftDeletes;

    public const KIND_KEPERAWATAN = 'asesmen-awal-keperawatan';
    public const KIND_SOAP = 'soap-dokter';
    public const KIND_LANJUTAN = 'asesmen-lanjutan';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';
    public const STATUS_AMENDED = 'amended';

    /** Bagian isi yang ikut disimpan saat versi lama diarsipkan. */
    public const CONTENT_FIELDS = [
        'chief_complaint', 'subjective', 'objective', 'assessment', 'plan',
    ];

    protected $table = 'clinical.assessments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'finalized_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AssessmentRevision::class)->orderByDesc('version');
    }

    /** Sudah terkunci, sehingga perubahan harus lewat ralat berversi. */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_FINAL, self::STATUS_AMENDED], true);
    }

    /** Isi asesmen sebagaimana disimpan pada versi berjalan. */
    public function contentSnapshot(): array
    {
        return $this->only(self::CONTENT_FIELDS);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_KEPERAWATAN => 'Asesmen Awal Keperawatan',
            self::KIND_SOAP => 'SOAP Dokter',
            self::KIND_LANJUTAN => 'Asesmen Lanjutan',
            default => $kind,
        };
    }
}
