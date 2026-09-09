<?php

namespace App\Modules\Philanthropy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calon/penerima dana kesehatan.
 *
 * TIDAK ADA DI KHANZA. `ambil_dankes` mencatat pengambilan dana kesehatan
 * dengan tanggal, kategori, dan jumlah — tanpa menyebut penerimanya sama
 * sekali. Enam belas kosakata untuk menilai kelayakan seseorang, tapi
 * orangnya sendiri tidak punya tempat.
 *
 * Rujukan ke pasien sengaja longgar: yang datang meminta bantuan sering
 * belum jadi pasien, dan mengikatnya keras akan menutup pintu justru pada
 * keadaan yang paling sering terjadi.
 */
class Recipient extends Model
{
    protected $table = 'philanthropy.recipients';

    protected $guarded = ['id'];

    public function asnaf(): BelongsTo
    {
        return $this->belongsTo(AssessmentCriterion::class, 'asnaf_criteria_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'recipient_id')->orderByDesc('assessed_on');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'recipient_id')->orderByDesc('disbursed_on');
    }

    /**
     * Total yang pernah disalurkan kepada orang ini.
     *
     * DIHITUNG, tidak disimpan — pertanyaan "sudah pernah dibantu berapa"
     * harus selalu sama dengan penjumlahan penyalurannya, dan saldo yang
     * disimpan terpisah akan melenceng begitu satu penyaluran dikoreksi.
     */
    public function totalDiterima(?string $sumber = null): string
    {
        $kueri = $this->disbursements();

        if ($sumber !== null) {
            $kueri->where('fund_source', $sumber);
        }

        return (string) $kueri->sum('amount');
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
