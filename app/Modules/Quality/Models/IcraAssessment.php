<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IcraAssessment extends Model
{
    public const STATUS_AKTIF = 'aktif';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'quality.icra_assessments';

    protected $guarded = ['id'];

    public function risks(): HasMany
    {
        return $this->hasMany(IcraAssessmentRisk::class, 'assessment_id')
            ->orderBy('category')->orderBy('id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(IcraAssessmentRequirement::class, 'assessment_id')->orderBy('position');
    }

    public function precautionClass(): BelongsTo
    {
        return $this->belongsTo(IcraPrecautionClass::class, 'precaution_class_id');
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(IcraActivityType::class, 'activity_type_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(IcraArea::class, 'area_id');
    }

    /**
     * Persyaratan yang belum dijawab sama sekali.
     *
     * Menutup pengkajian dengan persyaratan yang belum dijawab berarti
     * tidak ada yang memeriksa apakah barrier benar-benar terpasang.
     *
     * @return list<string>
     */
    public function persyaratanBelumDijawab(): array
    {
        return $this->requirements
            ->filter(fn (IcraAssessmentRequirement $s) => $s->fulfilled === null)
            ->pluck('requirement')
            ->values()
            ->all();
    }

    /**
     * Kategori risiko yang tingkatannya sudah disimpulkan tapi daftar
     * periksanya belum disentuh sama sekali.
     *
     * TIDAK memblokir apa pun — ia daftar kejujuran, sejenis dengan
     * pendingData() pada domain O: kesimpulan tanpa dasar yang bisa
     * dilihat lebih berguna daripada kesimpulan yang tampak beres.
     *
     * @return list<string>
     */
    public function kategoriTanpaBukti(): array
    {
        return $this->risks
            ->groupBy('category')
            ->filter(fn ($butir) => $butir->every(fn (IcraAssessmentRisk $b) => $b->present === null))
            ->keys()
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }
}
