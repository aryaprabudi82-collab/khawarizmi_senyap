<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use SoftDeletes;

    protected $table = 'identity.patients';

    protected $fillable = [
        'medical_record_number', 'nik', 'name', 'sex', 'birth_place', 'birth_date',
        'mother_name', 'blood_type', 'religion', 'marital_status', 'education', 'occupation',
        'address', 'rt_rw', 'village_code', 'village_name', 'district_name',
        'city_name', 'province_name', 'postal_code', 'phone', 'email',
        'guardian_name', 'guardian_relation', 'guardian_phone', 'guardian_address',
        'special_precautions', 'special_precautions_color',
        'registered_on', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'registered_on' => 'date',
        ];
    }

    /**
     * Pencarian bebas untuk loket pendaftaran.
     *
     * Nama memakai ILIKE yang ditopang index GIN trigram; tanpa itu, pada
     * jutaan baris tiap ketikan petugas berarti sequential scan.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('medical_record_number', $term)
                ->orWhere('nik', $term)
                ->orWhere('phone', $term)
                ->orWhere('name', 'ilike', '%' . $term . '%');
        });
    }

    /** Umur pasien pada satu tanggal, dipecah tahun/bulan/hari. */
    public function ageOn(\DateTimeInterface $date): array
    {
        if ($this->birth_date === null) {
            return ['years' => null, 'months' => null, 'days' => null];
        }

        $diff = $this->birth_date->diff(\DateTimeImmutable::createFromInterface($date));

        return ['years' => $diff->y, 'months' => $diff->m, 'days' => $diff->d];
    }
}
