<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca katalog pengukuran & panel observasi milik konteks catalog,
 * lewat kontrak yang diterbitkannya.
 *
 * RENTANG YANG DIKEMBALIKAN SUDAH RENTANG YANG BERLAKU — panel
 * mengalahkan bawaan kodenya, dan penggabungan itu terjadi di kontraknya,
 * bukan di sini. Kalau tiap pemanggil menggabungkan sendiri, sebagian akan
 * memakai rentang dewasa untuk bayi, dan bayi akan ditandai abnormal
 * sepanjang hari.
 */
class ObservationCatalogContext
{
    private const KODE = 'catalog.v_observation_code';
    private const BUTIR = 'catalog.v_observation_panel_item';

    /** Satu kode pengukuran. */
    public function code(string $code): ?stdClass
    {
        return DB::table(self::KODE)->where('code', $code)->first();
    }

    /**
     * Seluruh kode aktif, berindeks kodenya.
     *
     * @return Collection<string, stdClass>
     */
    public function codes(): Collection
    {
        return DB::table(self::KODE)->where('is_active', true)->get()->keyBy('code');
    }

    /**
     * Kode pada kategori tertentu saja.
     *
     * Dipakai layar yang memang cuma mengukur sebagian: layar pemeriksaan
     * rawat jalan tidak perlu setelan ventilator, dan menampilkannya bukan
     * cuma mengganggu — ia mengundang pengisian yang tidak berarti.
     *
     * @param  array<int, string>  $categories
     * @return Collection<string, stdClass>
     */
    public function codesInCategories(array $categories): Collection
    {
        return DB::table(self::KODE)
            ->where('is_active', true)
            ->whereIn('category', $categories)
            ->get()
            ->keyBy('code');
    }

    /**
     * Butir satu panel, urut tampil, berikut rentang yang BERLAKU.
     *
     * @return Collection<string, stdClass>
     */
    public function panelItems(string $panelCode): Collection
    {
        return DB::table(self::BUTIR)
            ->where('panel_code', $panelCode)
            ->where('panel_is_active', true)
            ->orderBy('sequence')
            ->get()
            ->keyBy('code');
    }

    /** Panel yang tersedia, boleh disaring konteks perawatannya. */
    public function panels(?string $careContext = null): Collection
    {
        return DB::table(self::BUTIR)
            ->where('panel_is_active', true)
            ->when($careContext, fn ($q) => $q->where('care_context', $careContext))
            ->select('panel_code', 'panel_name', 'care_context', 'age_group')
            ->distinct()
            ->orderBy('panel_name')
            ->get();
    }

    /**
     * Apakah satu nilai di luar rentang rujukan yang berlaku.
     *
     * Rentang yang tidak lengkap berarti pengukuran ini memang tidak punya
     * batas normal universal (berat badan, volume tidal) — dan yang tidak
     * punya batas TIDAK PERNAH ditandai abnormal, bukan ditandai normal
     * secara diam-diam.
     */
    public function isAbnormal(stdClass $item, ?float $value): bool
    {
        if ($value === null || $item->reference_low === null || $item->reference_high === null) {
            return false;
        }

        return $value < (float) $item->reference_low || $value > (float) $item->reference_high;
    }
}
