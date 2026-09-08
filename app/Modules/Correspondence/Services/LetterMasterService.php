<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\LetterClassification;
use App\Modules\Correspondence\Models\LetterIndexTerm;
use App\Modules\Correspondence\Models\LetterLocation;

/**
 * Master arsip surat: lokasi fisik, klasifikasi perihal, indeks temu balik.
 *
 * Satu layar untuk sembilan kode Khanza — surat_ruang, surat_almari,
 * surat_rak, surat_map, surat_klasifikasi, surat_indeks, dan (lewat
 * pemisahan sifat/derajat pada tabel surat) surat_sifat, surat_status,
 * surat_balas.
 */
class LetterMasterService
{
    /**
     * Menambah simpul lokasi arsip.
     *
     * Jenjang induk ditegakkan di sini, bukan di CHECK, karena CHECK tidak
     * bisa membaca baris lain. Yang dijaga: rak harus berada di dalam
     * almari, map di dalam rak, almari di dalam ruang — dan ruang tidak
     * berada di dalam apa pun.
     */
    public function addLocation(string $level, string $code, string $name, ?LetterLocation $parent = null): LetterLocation
    {
        if (! in_array($level, LetterLocation::JENJANG, true)) {
            throw new CorrespondenceException('Jenjang lokasi "'.$level.'" tidak dikenal.');
        }

        $jenjangInduk = LetterLocation::jenjangInduk($level);

        if ($jenjangInduk === null) {
            if ($parent !== null) {
                throw new CorrespondenceException('Ruang arsip tidak berada di dalam apa pun.');
            }
        } else {
            if ($parent === null) {
                throw new CorrespondenceException(
                    ucfirst($level).' harus berada di dalam '.$jenjangInduk.'.'
                );
            }

            if ($parent->level !== $jenjangInduk) {
                throw new CorrespondenceException(
                    ucfirst($level).' harus berada di dalam '.$jenjangInduk.
                    ', bukan langsung di dalam '.$parent->level.'.'
                );
            }
        }

        return LetterLocation::query()->create([
            'parent_id' => $parent?->id,
            'level' => $level,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function addClassification(string $code, string $name, ?LetterClassification $parent = null): LetterClassification
    {
        /*
         * Sub-klasifikasi hanya boleh satu tingkat di bawah klasifikasi.
         * Pola klasifikasi arsip memang berjenjang dua; membiarkannya lebih
         * dalam menghasilkan kode perihal yang tidak bisa dibaca sebagai
         * kode perihal lagi.
         */
        if ($parent !== null && $parent->parent_id !== null) {
            throw new CorrespondenceException(
                'Klasifikasi arsip hanya dua tingkat: klasifikasi dan sub-klasifikasi.'
            );
        }

        return LetterClassification::query()->create([
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function addIndexTerm(string $code, string $name): LetterIndexTerm
    {
        return LetterIndexTerm::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * Seluruh map yang bisa dipakai menyimpan surat, berikut jalurnya.
     *
     * Hanya map: surat disimpan DI DALAM map, dan menawarkan ruang atau rak
     * sebagai tempat penyimpanan akan menghasilkan catatan lokasi yang
     * tidak menuntun siapa pun ke berkasnya.
     *
     * @return array<int, string>
     */
    public function storableLocations(): array
    {
        return LetterLocation::query()
            ->where('level', LetterLocation::JENJANG_MAP)
            ->where('is_active', true)
            ->with('parent.parent.parent')
            ->get()
            ->mapWithKeys(fn (LetterLocation $map) => [$map->id => $map->jalur()])
            ->all();
    }
}
