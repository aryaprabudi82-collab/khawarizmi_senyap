<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca template formulir milik konteks catalog, lewat kontrak yang
 * diterbitkannya (catalog.v_form_template).
 *
 * KENAPA PEMBACA, BUKAN MODEL MILIK CATALOG. Mengimpor Eloquent model
 * konteks lain terlihat rapi dan lolos pemeriksaan batas konteks — karena
 * pemeriksaannya memindai literal 'schema.tabel', dan nama tabelnya
 * tersembunyi di dalam model. Tapi akibatnya sama saja: clinical jadi
 * bergantung pada bentuk TABEL catalog, bukan pada kontraknya, dan
 * perubahan kolom di catalog diam-diam merusak rekam medis. Pemeriksaannya
 * ikut diperketat supaya jalan pintas ini tidak terbuka lagi.
 *
 * VERSI LAMA IKUT DIBACA, dan itu justru gunanya: formulir yang sudah
 * diisi menunjuk versi tertentu, dan menampilkannya dengan versi terbaru
 * akan mengubah pertanyaan yang sebenarnya dijawab klinisi.
 */
class FormTemplateContext
{
    private const VIEW = 'catalog.v_form_template';

    /** Versi aktif satu kode template. */
    public function active(string $code): ?stdClass
    {
        return DB::table(self::VIEW)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    /** Versi tertentu — dipakai membaca kembali formulir lama. */
    public function version(string $code, int $version): ?stdClass
    {
        return DB::table(self::VIEW)
            ->where('code', $code)
            ->where('version', $version)
            ->first();
    }

    /** Template aktif, boleh disaring kategori dan spesialisasinya. */
    public function actives(?string $category = null, ?string $specialty = null): Collection
    {
        return DB::table(self::VIEW)
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($specialty, fn ($q) => $q->where('specialty', $specialty))
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seluruh pertanyaan satu template, rata tanpa bagiannya.
     *
     * @return array<string, array<string, mixed>>
     */
    public function questions(stdClass $template): array
    {
        $hasil = [];

        foreach ($this->decode($template->sections) as $bagian) {
            foreach ($bagian['questions'] ?? [] as $pertanyaan) {
                if (isset($pertanyaan['key'])) {
                    $hasil[$pertanyaan['key']] = $pertanyaan;
                }
            }
        }

        return $hasil;
    }

    /**
     * Aturan skor template — kosong berarti formulir ini memang tidak
     * dinilai dengan angka, bukan berarti aturannya hilang.
     *
     * @return array<string, mixed>
     */
    public function scoring(stdClass $template): array
    {
        return $this->decode($template->scoring ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $hasil = json_decode($json, true);

        return is_array($hasil) ? $hasil : [];
    }
}
