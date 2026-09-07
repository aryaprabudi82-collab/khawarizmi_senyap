<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\FormTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan template formulir asesmen & skrining (domain M item A).
 *
 * MEREVISI TEMPLATE MEMBUAT VERSI BARU, TIDAK PERNAH MENIMPA. Versi lama
 * tetap ada supaya asesmen yang sudah diisi bisa dibaca kembali persis
 * seperti saat diisi — dengan pertanyaan dan ambang yang berlaku waktu itu.
 * Menimpanya berarti mengubah isi rekam medis orang lain tanpa
 * menyentuhnya.
 *
 * HANYA SATU VERSI AKTIF PER KODE. Dua versi aktif berarti dua petugas
 * mengisi formulir berbeda untuk hal yang sama, dan hasilnya tidak bisa
 * dibandingkan antar pasien maupun antar waktu.
 *
 * TEMPLATE YANG SUDAH DIPAKAI TIDAK BISA DIHAPUS — hanya dinonaktifkan.
 * Menghapusnya membuat rekam medis yang menunjuknya kehilangan pertanyaan
 * yang dijawab, dan yang tersisa cuma jawaban tanpa pertanyaannya.
 */
class FormTemplateService
{
    /**
     * Membuat template baru (versi 1).
     *
     * @throws CatalogException
     */
    public function create(array $data, ?int $actorId = null): FormTemplate
    {
        $kode = trim((string) ($data['code'] ?? ''));

        if ($kode === '') {
            throw new CatalogException('Kode template wajib diisi.');
        }

        if (! in_array($data['category'] ?? '', FormTemplate::KATEGORI, true)) {
            throw new CatalogException("Kategori template '" . ($data['category'] ?? '') . "' tidak dikenal.");
        }

        if (FormTemplate::query()->where('code', $kode)->exists()) {
            throw new CatalogException(
                "Template '{$kode}' sudah ada. Untuk mengubahnya, terbitkan versi baru — jangan buat kode kedua."
            );
        }

        $this->assertSections($data['sections'] ?? []);

        return FormTemplate::query()->create([
            'code' => $kode,
            'version' => 1,
            'name' => $data['name'],
            'category' => $data['category'],
            'specialty' => $data['specialty'] ?? null,
            'age_group' => $data['age_group'] ?? null,
            'sections' => $data['sections'],
            'scoring' => $data['scoring'] ?? null,
            'is_repeatable' => (bool) ($data['is_repeatable'] ?? false),
            'note' => $data['note'] ?? null,
            'is_active' => true,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Menerbitkan versi baru dari template yang sudah ada.
     *
     * @throws CatalogException
     */
    public function revise(string $code, array $data, ?int $actorId = null): FormTemplate
    {
        $aktif = $this->active($code)
            ?? throw new CatalogException("Template '{$code}' belum pernah dibuat.");

        $this->assertSections($data['sections'] ?? $aktif->sections);

        return DB::transaction(function () use ($aktif, $code, $data, $actorId): FormTemplate {
            // Yang lama dinonaktifkan, BUKAN dihapus — asesmen yang sudah
            // diisi tetap bisa dibaca dengan pertanyaan aslinya.
            $aktif->update(['is_active' => false]);

            return FormTemplate::query()->create([
                'code' => $code,
                'version' => $aktif->version + 1,
                'name' => $data['name'] ?? $aktif->name,
                'category' => $aktif->category,
                'specialty' => $data['specialty'] ?? $aktif->specialty,
                'age_group' => $data['age_group'] ?? $aktif->age_group,
                'sections' => $data['sections'] ?? $aktif->sections,
                'scoring' => array_key_exists('scoring', $data) ? $data['scoring'] : $aktif->scoring,
                'is_repeatable' => (bool) ($data['is_repeatable'] ?? $aktif->is_repeatable),
                'note' => $data['note'] ?? $aktif->note,
                // Versi baru adalah pertanyaan baru; pengesahan versi lama
                // TIDAK ikut terbawa. Kalau terbawa, satu revisi diam-diam
                // bisa mengubah isi formulir yang sudah disahkan tanpa ada
                // yang menyetujuinya.
                'is_approved' => false,
                'is_active' => true,
                'created_by' => $actorId,
            ]);
        });
    }

    /**
     * Mengesahkan satu versi template.
     *
     * PENGESAHAN ADALAH PERBUATAN RUMAH SAKIT, bukan sifat bawaan
     * template. Instrumen baku seperti Morse atau Braden memang sahih
     * sebagai instrumen — tapi yang belum terjadi adalah RSP UI
     * MENGADOPSINYA, dan kesetiaan pada instrumen aslinya tidak
     * menggantikan keputusan itu.
     *
     * NOMOR KEPUTUSAN WAJIB DISEBUT. Pengesahan tanpa rujukan berita acara
     * tidak bisa ditelusuri saat ditanya auditor siapa yang menyetujui,
     * dan pengesahan yang tidak bisa ditelusuri sama saja dengan tidak ada.
     *
     * @throws CatalogException
     */
    public function approve(string $code, string $approvalNote, ?int $actorId = null, ?string $actorName = null): FormTemplate
    {
        $aktif = $this->active($code)
            ?? throw new CatalogException("Template '{$code}' tidak punya versi aktif untuk disahkan.");

        $rujukan = trim($approvalNote);

        if ($rujukan === '') {
            throw new CatalogException(
                'Nomor keputusan atau berita acara pengesahan wajib disebut: pengesahan yang tidak bisa '
                . 'ditelusuri sama saja dengan tidak ada.'
            );
        }

        if ($aktif->is_approved) {
            throw new CatalogException("Versi {$aktif->version} template '{$code}' sudah disahkan.");
        }

        $aktif->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $actorId,
            'approved_by_name' => $actorName,
            'approval_note' => $rujukan,
        ]);

        return $aktif->refresh();
    }

    /**
     * Template aktif yang belum disahkan komite medik.
     *
     * Inilah daftar yang menjawab "formulir mana yang dipakai tapi belum
     * pernah disetujui siapa pun" — pertanyaan yang selalu muncul saat
     * akreditasi.
     */
    public function pendingApproval(): Collection
    {
        return FormTemplate::query()
            ->where('is_active', true)
            ->where('is_approved', false)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Menonaktifkan template tanpa menghapusnya.
     *
     * @throws CatalogException
     */
    public function deactivate(string $code): FormTemplate
    {
        $aktif = $this->active($code)
            ?? throw new CatalogException("Template '{$code}' tidak punya versi aktif.");

        $aktif->update(['is_active' => false]);

        return $aktif->refresh();
    }

    /** Versi aktif satu kode template. */
    public function active(string $code): ?FormTemplate
    {
        return FormTemplate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Satu versi tertentu — dipakai membaca kembali asesmen lama dengan
     * pertanyaan yang berlaku saat itu.
     */
    public function version(string $code, int $version): ?FormTemplate
    {
        return FormTemplate::query()
            ->where('code', $code)
            ->where('version', $version)
            ->first();
    }

    /** Riwayat versi satu template, terbaru lebih dulu. */
    public function history(string $code): Collection
    {
        return FormTemplate::query()
            ->where('code', $code)
            ->orderByDesc('version')
            ->get();
    }

    /** Template aktif, boleh disaring kategori dan spesialisasinya. */
    public function actives(?string $category = null, ?string $specialty = null): Collection
    {
        return FormTemplate::query()
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($specialty, fn ($q) => $q->where('specialty', $specialty))
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Isi template diperiksa bentuknya, bukan diterima apa adanya.
     *
     * Template rusak baru ketahuan saat petugas membuka formulirnya di
     * hadapan pasien — dan saat itu tidak ada yang bisa diperbuat selain
     * mencatat di kertas.
     *
     * @throws CatalogException
     */
    private function assertSections(mixed $sections): void
    {
        if (! is_array($sections) || $sections === []) {
            throw new CatalogException('Template wajib punya sekurang-kurangnya satu bagian pertanyaan.');
        }

        $kunci = [];

        foreach ($sections as $i => $bagian) {
            if (empty($bagian['title'])) {
                throw new CatalogException('Setiap bagian template wajib punya judul.');
            }

            $pertanyaan = $bagian['questions'] ?? [];

            if (! is_array($pertanyaan) || $pertanyaan === []) {
                throw new CatalogException("Bagian '{$bagian['title']}' tidak punya pertanyaan.");
            }

            foreach ($pertanyaan as $p) {
                $k = $p['key'] ?? null;

                if (! is_string($k) || trim($k) === '') {
                    throw new CatalogException("Ada pertanyaan tanpa kunci di bagian '{$bagian['title']}'.");
                }

                // Kunci ganda membuat jawaban saling menimpa diam-diam, dan
                // yang hilang adalah jawaban yang benar-benar diisi klinisi.
                if (isset($kunci[$k])) {
                    throw new CatalogException("Kunci pertanyaan '{$k}' dipakai dua kali dalam satu template.");
                }

                $kunci[$k] = true;

                if (empty($p['label'])) {
                    throw new CatalogException("Pertanyaan '{$k}' tidak punya label yang bisa dibaca petugas.");
                }
            }
        }
    }
}
