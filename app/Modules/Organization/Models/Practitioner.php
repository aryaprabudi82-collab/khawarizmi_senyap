<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Practitioner extends Model
{
    use SoftDeletes;

    protected $table = 'organization.practitioners';

    protected $fillable = [
        'code', 'employee_number', 'name', 'title', 'specialty',
        'category', 'staff_kind', 'support_type',
        'position', 'unit_name', 'employment_status', 'entry_status',
        'sip_number', 'sip_valid_until', 'phone', 'email',
        'is_active', 'active_from', 'active_until',
    ];

    /**
     * Kategori profesi.
     *
     * Rumahnya di model, bukan di penggolong berkas SDM: kategori adalah
     * atribut praktisi yang dibaca controller, layar, dan laporan — sedangkan
     * penggolong cuma salah satu cara mengisinya.
     */
    public const DOKTER = 'dokter';

    public const PERAWAT = 'perawat';

    public const PENUNJANG = 'penunjang';

    public const NON_MEDIS = 'non-medis';

    /** Label kategori untuk ditampilkan, bukan kode mentah. */
    public const LABEL_KATEGORI = [
        'dokter' => 'Medis',
        'perawat' => 'Farmasi, Perawat, dan Tenaga Kesehatan Lainnya',
        'penunjang' => 'Penunjang Pelayanan',
        'non-medis' => 'Penunjang Non Pelayanan',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sip_valid_until' => 'date',
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    /**
     * Praktisi yang boleh melayani pada satu tanggal.
     *
     * Masa aktif dievaluasi terhadap tanggal pelayanan, bukan terhadap kolom
     * is_active saja. Khawarizmi menjalankan pembaruan massal setiap kali
     * halaman dibuka untuk menonaktifkan dokter yang lewat tanggal; di sini
     * aturannya cukup dijadikan bagian dari kueri.
     */
    public function scopeServingOn(Builder $query, \DateTimeInterface $date): Builder
    {
        $on = $date->format('Y-m-d');

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $on))
            ->where(fn (Builder $q) => $q->whereNull('active_until')->orWhere('active_until', '>=', $on));
    }

    /**
     * Pencarian untuk layar data pegawai.
     *
     * NIP dan nama dicari dengan cara berbeda dan itu disengaja: NIP dicocokkan
     * PERSIS karena petugas yang mengetikkannya sudah tahu nomor yang dicari,
     * sedangkan nama dicari sebagian karena ejaan nama Indonesia jarang diingat
     * utuh. Jabatan dan unit kerja ikut dicari sebagian supaya "radiografer"
     * atau "cssd" menemukan seluruh orangnya tanpa perlu tahu namanya.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('employee_number', $term)
                ->orWhere('code', $term)
                ->orWhere('name', 'ilike', '%'.$term.'%')
                ->orWhere('position', 'ilike', '%'.$term.'%')
                ->orWhere('unit_name', 'ilike', '%'.$term.'%')
                ->orWhere('specialty', 'ilike', '%'.$term.'%');
        });
    }

    /** Label kategori yang terbaca manusia. */
    public function categoryLabel(): string
    {
        return self::LABEL_KATEGORI[$this->category] ?? '—';
    }

    /**
     * Praktisi yang boleh menjadi DPJP.
     *
     * HANYA PEGAWAI. RSUI rumah sakit pendidikan, dan ekspor tenaga
     * kesehatannya memuat 4.484 mahasiswa serta dokter residen dari 6.825
     * baris. Mereka memang hadir di ruangan, tapi penanggung jawab pelayanan
     * bukan mahasiswa — dan daftar pilihan yang memuat 1.346 mahasiswa
     * kedokteran cepat atau lambat akan terpilih salah satu.
     */
    public function scopeEligibleAsAttending(Builder $query): Builder
    {
        return $query->where('staff_kind', 'pegawai')
            ->whereIn('category', ['dokter']);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'organization.practitioner_units')
            ->withPivot('is_primary');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PracticeSchedule::class)->orderBy('day_of_week')->orderBy('start_time');
    }

    public function displayName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->name);
    }
}
