<?php

namespace App\Modules\Integration\Http\Controllers;

use App\Modules\Integration\Services\CredentialStore;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\IntegrationHealthService;
use App\Modules\Integration\Services\IntegrationRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar pengaturan kredensial integrasi — "rumah" sistem luar.
 *
 * Ini satu-satunya tempat kredensial BPJS, SATUSEHAT, E-Klaim, SIRANAP,
 * dan Inhealth dimasukkan. Sebelum layar ini ada, mengisinya menuntut
 * akses shell ke server dan penerapan ulang aplikasi — pekerjaan yang
 * tidak bisa dilakukan orang yang memegang kredensialnya.
 *
 * NILAI RAHASIA TIDAK PERNAH DIKIRIM KE LAYAR. Yang tampil cuma penanda
 * terisi berikut empat huruf terakhirnya, dan kotak isian yang dikosongkan
 * berarti "jangan diubah" — bukan "hapus". Penghapusan disediakan
 * tersendiri supaya selalu disengaja.
 */
class IntegrationSettingController
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly IntegrationHealthService $health,
    ) {}

    public function index(): View
    {
        return view('integration::pengaturan.index', [
            'sistem' => $this->credentials->summary(),
            'siap' => $this->credentials->readyCount(),
            'total' => count(IntegrationRegistry::keys()),
            'ujiTerakhir' => $this->health->latest(),
        ]);
    }

    public function update(Request $request, string $system): RedirectResponse
    {
        try {
            $definisi = IntegrationRegistry::system($system);
        } catch (IntegrationException $e) {
            return back()->withErrors(['system' => $e->getMessage()]);
        }

        // Aturan validasi dirakit dari registry, bukan ditulis ulang per
        // sistem — menambah sistem baru tidak menuntut menyentuh controller.
        $aturan = [];

        foreach ($definisi['fields'] as $field => $def) {
            $aturan["fields.{$field}"] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($aturan);

        try {
            $this->credentials->put(
                $system,
                $data['fields'] ?? [],
                $request->user()?->id,
                $request->user()?->name,
            );
        } catch (IntegrationException $e) {
            return back()->withErrors(['fields' => $e->getMessage()]);
        }

        $kurang = $this->credentials->missingFields($system);

        if ($kurang !== []) {
            return back()->with('status', sprintf(
                'Tersimpan, tapi %s belum siap dipakai — kolom wajib yang masih kosong: %s.',
                $definisi['label'],
                implode(', ', $kurang),
            ));
        }

        return back()->with('status', $definisi['label'] . ' tersimpan dan siap dipakai. Sebaiknya diuji koneksinya sekarang.');
    }

    public function forget(string $system, string $field): RedirectResponse
    {
        try {
            $this->credentials->forget($system, $field);
        } catch (IntegrationException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', "Kolom {$field} dihapus; sistem ini tidak lagi dianggap siap sampai diisi kembali.");
    }

    public function check(Request $request, string $system): RedirectResponse
    {
        try {
            $hasil = $this->health->check($system, $request->user()?->id);
        } catch (IntegrationException $e) {
            return back()->withErrors(['system' => $e->getMessage()]);
        }

        return back()->with(
            $hasil->success ? 'status' : 'peringatan',
            $hasil->message,
        );
    }
}
