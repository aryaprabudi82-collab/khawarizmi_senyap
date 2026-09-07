<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Menegakkan batas antar bounded context.
 *
 * Khanza berakhir dengan 1.182 tabel dalam satu schema bukan karena orang tidak
 * tahu itu buruk, tapi karena tidak ada yang menghentikannya. Niat baik tidak
 * cukup — yang menahan hanya build yang gagal.
 *
 * Aturannya ada di config/contexts.php. Uji ini yang menjaganya tetap ditaati.
 */
class ContextBoundaryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $contexts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexts = require dirname(__DIR__, 2) . '/config/contexts.php';
    }

    #[Test]
    public function setiap_konteks_aktif_punya_schema_dan_modulnya_sendiri(): void
    {
        $schemas = [];

        foreach ($this->contexts['active'] as $name => $ctx) {
            $this->assertArrayHasKey('schema', $ctx, "Konteks '{$name}' tidak punya schema.");
            $this->assertArrayHasKey('module', $ctx, "Konteks '{$name}' tidak punya modul.");

            $this->assertDirectoryExists(
                dirname(__DIR__, 2) . '/app/Modules/' . $ctx['module'],
                "Folder modul untuk konteks '{$name}' tidak ada."
            );

            $this->assertNotContains(
                $ctx['schema'],
                $schemas,
                "Schema '{$ctx['schema']}' dipakai lebih dari satu konteks."
            );

            $schemas[] = $ctx['schema'];
        }
    }

    #[Test]
    public function migrasi_modul_hanya_menyentuh_schema_miliknya_sendiri(): void
    {
        $pelanggaran = [];

        foreach ($this->contexts['active'] as $name => $ctx) {
            $milik = $ctx['schema'];
            $lain = $this->schemaKonteksLain($milik);

            foreach ($this->berkasMigrasi($ctx['module']) as $berkas) {
                $isi = (string) file_get_contents($berkas);

                foreach ($lain as $asing) {
                    // Cocokkan 'schema.tabel' pada string, bukan sekadar kata.
                    if (preg_match("/['\"]{$asing}\./", $isi)) {
                        $pelanggaran[] = sprintf(
                            "%s (konteks %s) menyentuh schema '%s'",
                            basename($berkas), $name, $asing
                        );
                    }
                }
            }
        }

        $this->assertSame([], $pelanggaran, "Migrasi menyeberang batas konteks:\n" . implode("\n", $pelanggaran));
    }

    #[Test]
    public function kode_modul_hanya_menyentuh_schema_sendiri_atau_view_yang_diterbitkan(): void
    {
        $pelanggaran = [];

        foreach ($this->contexts['active'] as $name => $ctx) {
            $diizinkan = $this->viewYangDiterbitkan($ctx['schema']);

            foreach ($this->berkasPhpModul($ctx['module']) as $berkas) {
                $isi = (string) file_get_contents($berkas);

                foreach ($this->schemaKonteksLain($ctx['schema']) as $asing) {
                    if (! preg_match_all("/['\"]({$asing}\.[a-z_]+)['\"]/", $isi, $m)) {
                        continue;
                    }

                    foreach ($m[1] as $referensi) {
                        if (in_array($referensi, $diizinkan, true)) {
                            continue;
                        }

                        $pelanggaran[] = sprintf(
                            "%s (konteks %s) menyentuh '%s' — bukan view yang diterbitkan",
                            basename($berkas), $name, $referensi
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Kode menyeberang batas konteks. Pakai service konteks pemiliknya, "
            . "atau terbitkan view lewat 'publishes' di config/contexts.php:\n"
            . implode("\n", $pelanggaran)
        );
    }

    /**
     * Menutup celah yang ditemukan saat domain M item A dikerjakan.
     *
     * Pemeriksaan di atas memindai literal 'schema.tabel'. Mengimpor
     * Eloquent model milik konteks lain lolos begitu saja — nama tabelnya
     * tersembunyi di dalam model — padahal akibatnya sama: modul jadi
     * bergantung pada bentuk TABEL konteks lain, bukan pada kontraknya,
     * dan perubahan kolom di sana diam-diam merusak modul ini.
     *
     * SATU PENGECUALIAN, DAN CUMA SATU: Platform\Models\User. Ia dipakai
     * sebagai TIPE pelaku ("siapa yang mencatat ini"), bukan untuk mengueri
     * tabel platform — dan mengedarkan pengguna terautentikasi lintas modul
     * memang wajar. Menambah pengecualian baru di sini adalah keputusan
     * desain: kalau sebuah modul perlu MEMBACA data konteks lain, jalannya
     * menerbitkan view lewat 'publishes', bukan memperpanjang daftar ini.
     */
    #[Test]
    public function modul_tidak_mengimpor_model_konteks_lain(): void
    {
        $dikecualikan = ['Platform\\Models\\User'];

        $pelanggaran = [];

        foreach ($this->contexts['active'] as $name => $ctx) {
            $modul = $ctx['module'];

            foreach ($this->berkasPhpModul($modul) as $berkas) {
                // Seeder adalah perkakas pengembangan, bukan kode yang
                // berjalan melayani pasien; ia memang menyiapkan data
                // lintas modul supaya lingkungan uji bisa berdiri.
                if (str_ends_with($berkas, 'Seeder.php')) {
                    continue;
                }

                $isi = (string) file_get_contents($berkas);

                if (! preg_match_all('/^use App\\\\Modules\\\\([A-Za-z]+)\\\\Models\\\\([A-Za-z]+);/m', $isi, $m, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($m as $hit) {
                    if ($hit[1] === $modul) {
                        continue;
                    }

                    $rujukan = $hit[1] . '\\Models\\' . $hit[2];

                    if (in_array($rujukan, $dikecualikan, true)) {
                        continue;
                    }

                    $pelanggaran[] = sprintf(
                        '%s (konteks %s) mengimpor %s',
                        basename($berkas), $name, $rujukan
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Modul mengimpor model konteks lain. Baca lewat view yang diterbitkan "
            . "konteks pemiliknya, bukan lewat model-nya:\n" . implode("\n", $pelanggaran)
        );
    }

    #[Test]
    public function schema_konteks_terencana_tidak_bentrok_dengan_yang_aktif(): void
    {
        $aktif = array_column($this->contexts['active'], 'schema');

        // Selalu ada assertion nyata, terlepas dari isi 'planned' — daftarnya
        // sempat kosong begitu seluruh konteks yang direncanakan sudah
        // digarap, dan pengujian tanpa assertion dianggap PHPUnit "risky".
        $this->assertIsArray($this->contexts['planned']);

        foreach ($this->contexts['planned'] as $name => $ctx) {
            $this->assertNotContains(
                $ctx['schema'],
                $aktif,
                "Konteks terencana '{$name}' memakai schema yang sudah dipakai konteks aktif."
            );
        }
    }

    #[Test]
    public function nama_schema_bukan_kata_kunci_postgresql(): void
    {
        // 'order', 'user', 'table' dan kawan-kawannya memaksa setiap query dikutip.
        $terlarang = ['order', 'user', 'group', 'table', 'select', 'default', 'session'];

        $semua = array_merge(
            array_column($this->contexts['active'], 'schema'),
            array_column($this->contexts['planned'], 'schema')
        );

        foreach ($semua as $schema) {
            $this->assertNotContains(
                $schema,
                $terlarang,
                "Schema '{$schema}' adalah kata kunci PostgreSQL dan akan memaksa pengutipan di mana-mana."
            );
        }
    }

    /** @return list<string> */
    private function schemaKonteksLain(string $milik): array
    {
        $semua = array_merge(
            array_column($this->contexts['active'], 'schema'),
            array_column($this->contexts['planned'], 'schema')
        );

        return array_values(array_diff(array_unique($semua), [$milik]));
    }

    /** @return list<string> */
    private function viewYangDiterbitkan(string $kecuali): array
    {
        $diizinkan = [];

        foreach ($this->contexts['active'] as $ctx) {
            if ($ctx['schema'] === $kecuali) {
                continue;
            }

            foreach (array_keys($ctx['publishes'] ?? []) as $view) {
                $diizinkan[] = $ctx['schema'] . '.' . $view;
            }
        }

        return $diizinkan;
    }

    /** @return list<string> */
    private function berkasMigrasi(string $modul): array
    {
        return $this->berkasPhp(dirname(__DIR__, 2) . "/app/Modules/{$modul}/Database/Migrations");
    }

    /** @return list<string> */
    private function berkasPhpModul(string $modul): array
    {
        $dir = dirname(__DIR__, 2) . "/app/Modules/{$modul}";

        // Migrasi memang harus menyebut schema-nya sendiri; yang diperiksa di
        // sini adalah kode aplikasi.
        return array_values(array_filter(
            $this->berkasPhp($dir),
            fn (string $f): bool => ! str_contains(str_replace('\\', '/', $f), '/Database/Migrations/')
        ));
    }

    /** @return list<string> */
    private function berkasPhp(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $berkas = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $berkas[] = $item->getPathname();
            }
        }

        sort($berkas);

        return $berkas;
    }
}
