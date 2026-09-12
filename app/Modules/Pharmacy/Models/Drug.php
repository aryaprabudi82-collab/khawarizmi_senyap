<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Drug extends Model
{
    protected $table = 'pharmacy.drugs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean',
            'is_narcotic' => 'boolean',
            'is_psychotropic' => 'boolean',
            'is_high_alert' => 'boolean',
            'is_active' => 'boolean',
            'sell_price' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
        ];
    }

    public function drugCategory(): BelongsTo
    {
        return $this->belongsTo(DrugCategory::class);
    }

    public function drugClass(): BelongsTo
    {
        return $this->belongsTo(DrugClass::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DrugUnit::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where('is_active', true)->where(function (Builder $q) use ($term) {
            $q->where('code', 'ilike', $term.'%')
                ->orWhere('name', 'ilike', '%'.$term.'%')
                ->orWhere('generic_name', 'ilike', '%'.$term.'%');
        });
    }

    /**
     * Mencari SATU obat dari teks yang diketik petugas.
     *
     * DIBUAT SETELAH SEBUAH CACAT NYATA. Formulir peresepan menyimpan id
     * obat pada input tersembunyi yang diisi JavaScript dengan mencocokkan
     * teks kotak isian ke label hasil pencarian, PERSIS SAMA PERSIS.
     * Begitu petugas memilih obat dari daftar, kotaknya terisi label
     * lengkap, lalu pencarian otomatis berjalan lagi memakai label itu
     * sebagai kata kunci — dan `name ilike '%Parasetamol 500 mg — tablet%'`
     * tidak cocok dengan apa pun. Hasilnya kosong, peta label-ke-id ikut
     * kosong, dan id yang sudah benar DIHAPUS diam-diam beberapa ratus
     * milidetik setelah petugas memilihnya.
     *
     * Yang terlihat di layar: isian sudah benar, lalu ditolak "obat wajib
     * diisi". Tidak ada petunjuk apa pun tentang sebabnya, dan petugas
     * yang mengalaminya akan mencoba hal yang sama berulang-ulang.
     *
     * MAKA PENYELESAIANNYA DIPINDAH KE SERVER. Peramban boleh membantu
     * dengan saran dan boleh mengirim id kalau punya, tapi kebenaran
     * akhirnya ditentukan di sini — bukan oleh kecocokan string di
     * peramban yang bisa gagal karena satu tanda hubung.
     *
     * Ambigu DITOLAK, bukan ditebak: kalau teksnya cocok dengan lebih dari
     * satu obat, yang salah bukan petugasnya melainkan pertanyaannya belum
     * cukup sempit. Menebak yang pertama berarti meresepkan obat yang
     * tidak dipilih siapa pun.
     *
     * @return array{obat: self|null, kandidat: Collection<int, self>}
     */
    public static function resolve(string $teks): array
    {
        $teks = trim($teks);
        $kosong = ['obat' => null, 'kandidat' => collect()];

        if ($teks === '') {
            return $kosong;
        }

        // Label yang utuh dikenali lebih dulu — itu yang paling sering
        // dikirim, karena begitulah bunyi kotak isian setelah memilih.
        $persis = self::query()->where('is_active', true)->get()
            ->filter(fn (self $d) => mb_strtolower($d->label()) === mb_strtolower($teks));

        if ($persis->count() === 1) {
            return ['obat' => $persis->first(), 'kandidat' => collect()];
        }

        // Kode obat bersifat unik, jadi cocok kode berarti selesai.
        $kode = self::query()->where('is_active', true)
            ->whereRaw('lower(code) = ?', [mb_strtolower($teks)])->first();

        if ($kode !== null) {
            return ['obat' => $kode, 'kandidat' => collect()];
        }

        $kandidat = self::query()->search($teks)->orderBy('name')->limit(20)->get();

        return $kandidat->count() === 1
            ? ['obat' => $kandidat->first(), 'kandidat' => collect()]
            : ['obat' => null, 'kandidat' => $kandidat];
    }

    /** Obat yang butuh pengawasan khusus dan wajib dilaporkan per batch. */
    public function isControlled(): bool
    {
        return $this->is_narcotic || $this->is_psychotropic;
    }

    /**
     * Label yang dilihat petugas saat memilih obat.
     *
     * KEKUATAN TIDAK DIULANG kalau namanya sudah memuatnya. Seeder menulis
     * nama "Parasetamol 500 mg" sekaligus mengisi strength "500 mg", dan
     * label lama menggabungkan keduanya apa adanya menjadi "Parasetamol
     * 500 mg 500 mg — tablet". Petugas yang membacanya wajar menduga ada
     * dua sediaan berbeda, dan itu keraguan yang tidak perlu ada di layar
     * peresepan.
     */
    public function label(): string
    {
        $kekuatan = trim((string) ($this->strength ?? ''));
        $nama = trim($this->name);

        if ($kekuatan !== '' && ! str_contains(mb_strtolower($nama), mb_strtolower($kekuatan))) {
            $nama .= ' '.$kekuatan;
        }

        return $nama.' — '.$this->form;
    }
}
