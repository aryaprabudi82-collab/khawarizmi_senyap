<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\NursingCarePlan;
use App\Modules\Catalog\Models\NursingProblem;
use Illuminate\Support\Collection;

/**
 * Master masalah & rencana keperawatan (domain M item B).
 *
 * Menaungi 16 kode master Khanza — delapan masalah dan delapan rencana,
 * satu pasang untuk tiap spesialisasi. Keenam belasnya berbentuk identik
 * di skema Khanza; yang berbeda cuma spesialisasinya, jadi di sini jadi
 * dua tabel dengan kolom specialty.
 *
 * RENCANA WAJIB PUNYA MASALAH INDUK. Itu bukan aturan karangan: skema
 * Khanza sendiri memasang foreign key dari master_rencana_keperawatan ke
 * master_masalah_keperawatan. Rencana keperawatan tanpa masalah adalah
 * intervensi tanpa indikasi.
 *
 * SPESIALISASI RENCANA MENGIKUTI MASALAHNYA, tidak diisi sendiri. Kalau
 * bisa diisi terpisah, akan ada rencana "anak" di bawah masalah "geriatri"
 * — dan tidak ada satu pun aturan yang menahannya.
 *
 * MASTER TIDAK DIHAPUS, HANYA DINONAKTIFKAN. Asuhan keperawatan yang sudah
 * ditulis menunjuk kode ini; menghapusnya membuat rekam medis lama
 * kehilangan nama masalah yang ditegakkan perawat.
 */
class NursingCareMasterService
{
    /**
     * @throws CatalogException
     */
    public function addProblem(array $data): NursingProblem
    {
        $kode = trim((string) ($data['code'] ?? ''));
        $nama = trim((string) ($data['name'] ?? ''));

        if ($kode === '' || $nama === '') {
            throw new CatalogException('Kode dan nama masalah keperawatan wajib diisi.');
        }

        $spesialisasi = $data['specialty'] ?? null;

        $ada = NursingProblem::query()
            ->where('code', $kode)
            ->where('specialty', $spesialisasi)
            ->exists();

        if ($ada) {
            throw new CatalogException(
                "Masalah keperawatan '{$kode}' sudah ada untuk spesialisasi "
                . ($spesialisasi ?? 'umum') . '.'
            );
        }

        return NursingProblem::query()->create([
            'code' => $kode,
            'name' => $nama,
            'specialty' => $spesialisasi,
            'standard_code' => $data['standard_code'] ?? null,
            'definition' => $data['definition'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @throws CatalogException
     */
    public function addCarePlan(NursingProblem $problem, array $data): NursingCarePlan
    {
        $kode = trim((string) ($data['code'] ?? ''));
        $rencana = trim((string) ($data['plan'] ?? ''));

        if ($kode === '' || $rencana === '') {
            throw new CatalogException('Kode dan bunyi rencana keperawatan wajib diisi.');
        }

        if (! $problem->is_active) {
            throw new CatalogException(
                "Masalah '{$problem->name}' sudah tidak aktif; rencana baru di bawahnya tidak akan pernah bisa dipilih."
            );
        }

        $ada = NursingCarePlan::query()
            ->where('nursing_problem_id', $problem->id)
            ->where('code', $kode)
            ->exists();

        if ($ada) {
            throw new CatalogException("Rencana '{$kode}' sudah ada di bawah masalah '{$problem->name}'.");
        }

        return NursingCarePlan::query()->create([
            'nursing_problem_id' => $problem->id,
            'code' => $kode,
            'plan' => $rencana,
            'standard_code' => $data['standard_code'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * Menonaktifkan masalah BERIKUT seluruh rencana di bawahnya.
     *
     * Rencana yang tetap aktif di bawah masalah yang sudah tidak dipakai
     * akan muncul di layar tanpa induk yang bisa dipilih — dan perawat
     * tidak punya cara tahu kenapa.
     *
     * @throws CatalogException
     */
    public function deactivateProblem(NursingProblem $problem): NursingProblem
    {
        if (! $problem->is_active) {
            throw new CatalogException('Masalah ini sudah tidak aktif.');
        }

        $problem->update(['is_active' => false]);
        $problem->carePlans()->update(['is_active' => false]);

        return $problem->refresh();
    }

    /** Masalah aktif, boleh disaring spesialisasinya. */
    public function problems(?string $specialty = null, bool $includeGeneral = true): Collection
    {
        return NursingProblem::query()
            ->where('is_active', true)
            ->when($specialty !== null, function ($q) use ($specialty, $includeGeneral) {
                // Spesialisasi tertentu biasanya dipakai BERSAMA yang umum:
                // perawat anak tetap menegakkan "nyeri akut" yang berlaku
                // untuk siapa saja.
                $includeGeneral
                    ? $q->where(fn ($w) => $w->where('specialty', $specialty)->orWhereNull('specialty'))
                    : $q->where('specialty', $specialty);
            })
            ->orderBy('code')
            ->get();
    }

    /** Rencana aktif di bawah satu masalah. */
    public function carePlansFor(NursingProblem $problem): Collection
    {
        return $problem->carePlans()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }
}
