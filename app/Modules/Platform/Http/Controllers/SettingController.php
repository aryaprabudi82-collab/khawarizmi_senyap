<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\Institution;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Services\SettingStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Layar pengaturan aplikasi & identitas rumah sakit.
 *
 * Sebelas kode Khanza dilayani satu layar berkelompok. Khanza memberi satu
 * menu per tabel pengaturan — sebelas layar untuk sebelas baris tunggal,
 * dan tidak satu pun di antaranya menunjukkan yang lain, sehingga tidak ada
 * tempat yang bisa menjawab "apa saja yang belum diatur".
 */
class SettingController
{
    public function __construct(private readonly SettingStore $store) {}

    public function index(): View
    {
        return view('platform::pengaturan.index', [
            'pengaturan' => Setting::query()->where('is_active', true)
                ->orderBy('group')->orderBy('key')->get()->groupBy('group'),

            // Daftar kejujuran: pertanyaannya sudah pasti, jawabannya belum.
            'belumDitetapkan' => $this->store->belumDitetapkan(),

            'institusi' => Institution::berlaku(),
        ]);
    }

    public function update(Request $request, Setting $pengaturan): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:250'],
            'effective_from' => ['required', 'date'],

            /*
             * Alasan wajib untuk pengaturan yang menyentuh uang. Yang
             * membacanya belakangan adalah orang yang sedang mencari sebab
             * selisih tagihan, dan "diubah" tanpa "kenapa" tidak menutup
             * pemeriksaan apa pun.
             */
            'reason' => [$pengaturan->value_type === Setting::TIPE_UANG ? 'required' : 'nullable',
                'string', 'max:500'],
        ], [], ['value' => 'nilai', 'effective_from' => 'berlaku sejak', 'reason' => 'alasan']);

        try {
            $this->store->set(
                $pengaturan->key,
                $data['value'] ?? null,
                $request->user()?->getKey(),
                $request->user()?->name,
                $data['reason'] ?? null,
                $data['effective_from'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengaturan "'.$pengaturan->label.'" diperbarui.');
    }

    public function saveInstitution(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:250'],
            'city' => ['nullable', 'string', 'max:60'],
            'province' => ['nullable', 'string', 'max:60'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'code_kemenkes' => ['nullable', 'string', 'max:20'],
            'code_bpjs' => ['nullable', 'string', 'max:20'],
            'code_inhealth' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => 'nama rumah sakit', 'address' => 'alamat']);

        /*
         * updateOrCreate pada id tetap, BUKAN pada namanya. Khanza mengunci
         * identitas institusi dengan nama instansinya sendiri, sehingga
         * mengganti nama melahirkan rumah sakit kedua alih-alih mengubah
         * yang ada.
         */
        Institution::query()->updateOrCreate(['id' => Institution::ID], $data);

        return back()->with('sukses', 'Identitas rumah sakit disimpan.');
    }
}
