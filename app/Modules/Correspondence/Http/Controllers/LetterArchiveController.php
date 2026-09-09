<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\LetterClassification;
use App\Modules\Correspondence\Models\LetterIndexTerm;
use App\Modules\Correspondence\Models\LetterLocation;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\LetterMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master arsip surat: lokasi fisik, klasifikasi perihal, indeks temu balik.
 *
 * Enam kode Khanza (surat_ruang, surat_almari, surat_rak, surat_map,
 * surat_klasifikasi, surat_indeks) di satu layar, di bawah gerbang
 * `surat_masuk` yang sama dengan register suratnya — yang mengelola
 * arsipnya adalah yang mencatat suratnya.
 */
class LetterArchiveController
{
    public function __construct(private readonly LetterMasterService $master) {}

    public function index(): View
    {
        return view('correspondence::surat.master', [
            'lokasi' => LetterLocation::query()->with('parent.parent.parent')->orderBy('level')->orderBy('code')->get(),
            'klasifikasi' => LetterClassification::query()->with('parent')->orderBy('code')->get(),
            'indeks' => LetterIndexTerm::query()->orderBy('code')->get(),
            'jenjang' => LetterLocation::JENJANG,
        ]);
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(LetterLocation::JENJANG)],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterLocation,id'],
        ], [], ['level' => 'jenjang', 'code' => 'kode', 'name' => 'nama', 'parent_id' => 'induk']);

        try {
            $this->master->addLocation(
                $data['level'],
                $data['code'],
                $data['name'],
                isset($data['parent_id']) ? LetterLocation::query()->find($data['parent_id']) : null
            );
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Lokasi arsip "'.$data['name'].'" ditambahkan.');
    }

    public function storeClassification(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:App\Modules\Correspondence\Models\LetterClassification,code'],
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterClassification,id'],
        ], [], ['code' => 'kode klasifikasi', 'name' => 'nama', 'parent_id' => 'induk']);

        try {
            $this->master->addClassification(
                $data['code'],
                $data['name'],
                isset($data['parent_id']) ? LetterClassification::query()->find($data['parent_id']) : null
            );
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Klasifikasi "'.$data['code'].'" ditambahkan.');
    }

    public function storeIndexTerm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:App\Modules\Correspondence\Models\LetterIndexTerm,code'],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode indeks', 'name' => 'nama indeks']);

        $this->master->addIndexTerm($data['code'], $data['name']);

        return back()->with('sukses', 'Indeks "'.$data['name'].'" ditambahkan.');
    }
}
