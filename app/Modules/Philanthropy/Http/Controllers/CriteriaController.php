<?php

namespace App\Modules\Philanthropy\Http\Controllers;

use App\Modules\Philanthropy\Models\AssessmentCriterion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kriteria asesmen — keenam belas kosakata domain T dalam SATU layar.
 *
 * Enam belas kode Khanza berarti enam belas menu untuk enam belas daftar
 * berbentuk sama persis. Di sini satu layar dengan penyaring kategori:
 * yang dikerjakan orangnya memang satu pekerjaan — menyusun instrumen
 * survei — bukan enam belas.
 */
class CriteriaController
{
    public function index(Request $request): View
    {
        $kategori = $request->query('kategori');

        if (! in_array($kategori, AssessmentCriterion::KATEGORI, true)) {
            $kategori = null;
        }

        $kueri = AssessmentCriterion::query()->orderBy('category')->orderBy('position')->orderBy('id');

        if ($kategori !== null) {
            $kueri->where('category', $kategori);
        }

        return view('philanthropy::kriteria.index', [
            'kriteria' => $kueri->get()->groupBy('category'),
            'kategoriDipilih' => $kategori,

            /*
             * Kategori yang masih kosong DITAMPILKAN, bukan didiamkan.
             * Kategori tanpa kosakata tidak bisa disurvei, dan lebih baik
             * itu terlihat sebagai daftar pekerjaan yang belum selesai
             * daripada tersembunyi sebagai pertanyaan yang tak pernah
             * muncul di formulir.
             */
            'kategoriKosong' => AssessmentCriterion::kategoriKosong(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(AssessmentCriterion::KATEGORI)],
            'code' => ['required', 'string', 'max:20', 'unique:App\Modules\Philanthropy\Models\AssessmentCriterion,code'],
            'name' => ['required', 'string', 'max:120'],

            // Bobot boleh kosong, dan itu keadaan bawaannya: instrumen yang
            // belum diskor tetap instrumen yang sah.
            'weight' => ['nullable', 'integer', 'between:-999,999'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'category' => 'kategori', 'code' => 'kode', 'name' => 'uraian',
            'weight' => 'bobot', 'position' => 'urutan',
        ]);

        AssessmentCriterion::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Kriteria "'.$data['name'].'" ditambahkan.');
    }

    public function toggle(AssessmentCriterion $kriteria): RedirectResponse
    {
        /*
         * DINONAKTIFKAN, TIDAK DIHAPUS. Kriteria yang pernah dipakai
         * menjawab asesmen tidak boleh lenyap: putusan atas nasib orang
         * harus tetap bisa dibaca sebagaimana ia diambil. Jawabannya
         * sendiri sudah membekukan salinan labelnya, tapi menghapus
         * kriterianya tetap memutus penelusuran ke daftar aslinya.
         */
        $kriteria->update(['is_active' => ! $kriteria->is_active]);

        return back()->with('sukses', 'Kriteria "'.$kriteria->name.'" '
            .($kriteria->is_active ? 'diaktifkan' : 'dinonaktifkan').'.');
    }
}
