<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Models\Practitioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Layar daftar pegawai dan tenaga kesehatan.
 *
 * TERPISAH DARI MASTER ORGANISASI, dan itu disengaja. Layar master mengelola
 * unit layanan, praktisi, dan jadwal praktik sekaligus — tiga hal yang
 * dikerjakan admin master saat menyiapkan sistem. Layar ini menjawab satu
 * pertanyaan yang jauh lebih sering ditanyakan: "siapa saja yang bekerja di
 * sini, di unit mana, sebagai apa".
 *
 * BERPAGINASI, BUKAN DIMUAT SEKALIGUS. 1.524 baris yang masing-masing membuka
 * dua modal membuat halaman berat dan tidak bisa dicari; daftar sebesar ini
 * dibaca dengan menyaring, bukan digulir.
 */
class StaffDirectoryController
{
    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('cari', ''));
        $kategori = trim((string) $request->query('kategori', ''));
        $status = trim((string) $request->query('status', ''));
        $unit = trim((string) $request->query('unit', ''));

        $query = Practitioner::query()->search($cari);

        if ($kategori !== '' && array_key_exists($kategori, Practitioner::LABEL_KATEGORI)) {
            $query->where('category', $kategori);
        }

        if ($status !== '') {
            $query->where('employment_status', $status);
        }

        if ($unit !== '') {
            $query->where('unit_name', $unit);
        }

        return view('organization::staff.index', [
            'cari' => $cari,
            'kategori' => $kategori,
            'status' => $status,
            'unit' => $unit,
            'pegawai' => $query
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),

            // Pilihan penyaring diambil dari data yang benar-benar ada, bukan
            // dari daftar tetap yang akan basi begitu SDM menambah status baru.
            'daftarKategori' => Practitioner::LABEL_KATEGORI,
            'daftarStatus' => Practitioner::query()
                ->whereNotNull('employment_status')
                ->distinct()
                ->orderBy('employment_status')
                ->pluck('employment_status'),
            'daftarUnit' => Practitioner::query()
                ->whereNotNull('unit_name')
                ->distinct()
                ->orderBy('unit_name')
                ->pluck('unit_name'),

            'ringkasan' => $this->ringkasan(),
        ]);
    }

    /**
     * Menyunting data seorang pegawai.
     *
     * SUNTINGAN BISA TERTIMPA MUAT ULANG, dan itu keputusan yang diambil
     * sadar: berkas SDM adalah sumber kebenaran untuk NIP, jabatan, dan unit
     * kerja, jadi saat ekspor baru dimuat nilai dari berkas yang menang.
     * Layar edit menyatakannya terang-terangan supaya petugas tahu sebelum
     * menyunting — koreksi yang diam-diam hilang jauh lebih buruk daripada
     * koreksi yang sejak awal diketahui sementara.
     *
     * NIP TIDAK BISA DIUBAH DARI SINI. Ia pengenal yang menautkan baris ini
     * ke daftar kepegawaian; mengubahnya berarti memutus tautan itu, dan
     * muat ulang berikutnya akan membuat baris kedua untuk orang yang sama.
     */
    public function update(Request $request, Practitioner $pegawai): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:60'],
            'category' => ['required', Rule::in(array_keys(Practitioner::LABEL_KATEGORI))],
            'support_type' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:120'],
            'unit_name' => ['nullable', 'string', 'max:150'],
            'employment_status' => ['nullable', 'string', 'max:60'],
            'entry_status' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:100'],
            'sip_number' => ['nullable', 'string', 'max:120'],
            'sip_valid_until' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nama',
            'category' => 'kategori',
            'position' => 'jabatan',
            'unit_name' => 'unit kerja',
            'employment_status' => 'status kepegawaian',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        /*
         * support_type hanya berarti bagi kategori penunjang. Dibiarkan
         * terisi pada kategori lain, ia muncul sebagai keterangan yang
         * menyesatkan di bawah label kategori — "dokter / farmasi".
         */
        if ($data['category'] !== Practitioner::PENUNJANG) {
            $data['support_type'] = null;
        }

        $pegawai->fill($data)->save();

        return back()->with('sukses', "Data {$pegawai->name} diperbarui.");
    }

    /**
     * Hitungan per kategori, untuk kartu ringkasan di kepala layar.
     *
     * @return array<string, int>
     */
    private function ringkasan(): array
    {
        $hitung = Practitioner::query()
            ->selectRaw('category, count(*) as jumlah')
            ->groupBy('category')
            ->pluck('jumlah', 'category')
            ->all();

        $hasil = [];

        foreach (array_keys(Practitioner::LABEL_KATEGORI) as $k) {
            $hasil[$k] = (int) ($hitung[$k] ?? 0);
        }

        return $hasil;
    }
}
