<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Services\ManagedPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kapabilitas Khanza yang SENGAJA TIDAK dibangun, dan alasannya.
 *
 * MENGAPA PENOLAKAN PERLU DIUJI. Kode yang tidak dibangun tidak
 * meninggalkan jejak apa pun. Enam bulan lagi, seseorang yang menghitung
 * "kode mana yang belum dilayani" akan menemukan dua kode ini, mengira
 * keduanya pekerjaan yang terlupa, lalu membangunnya — berikut cacat yang
 * justru jadi alasan menolaknya. Uji ini yang membuat penolakannya jadi
 * keputusan tercatat, bukan keheningan.
 *
 * Pola yang sama sudah dipakai untuk kosakata yang sengaja lahir kosong
 * (icra_risk_items domain R, assessment_criteria domain T): penolakan
 * disimpan sebagai data yang diuji, bukan sebagai tidak-adanya sesuatu.
 */
class RefusedCapabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kode domain U yang ditolak berikut alasannya.
     *
     * Alasannya ditulis di sini, bukan di commit message, karena inilah
     * yang akan dibaca orang yang bertanya "kenapa ini belum ada".
     */
    private const DITOLAK = [
        'e_eksekutif' => 'Tabel `e_eksekutif` Khanza adalah tabel MyISAM berisi dua kolom `text` polos: '
            .'`usere` dan `passworde`. Membangunnya berarti menaruh kredensial dalam bentuk '
            .'yang bisa dibaca siapa pun yang bisa membaca tabelnya — termasuk lewat backup, '
            .'lewat replikasi, dan lewat setiap dump yang pernah dikirim ke vendor. Dan ia '
            .'membuka PINTU MASUK KEDUA yang tidak melewati pemeriksaan peran mana pun: '
            .'siapa pun yang tahu sandi itu masuk sebagai "eksekutif" tanpa akun, tanpa jejak '
            .'audit, dan tanpa cara menonaktifkannya selain mengubah satu baris. '
            .'Akses eksekutif dilayani peran `manajemen` yang sudah ada, lewat autentikasi '
            .'yang sama dengan seluruh pengguna lain.',

        'vakum' => 'Menu "Vakum Table" Khanza menjalankan VACUUM/OPTIMIZE atas tabel-tabel besar '
            .'dari dalam aplikasi. PostgreSQL menjalankan autovacuum sendiri dan sudah '
            .'menyetelnya per tabel, jadi tombol ini tidak menyelesaikan masalah yang belum '
            .'terselesaikan. Yang ia tambahkan cuma risikonya: VACUUM FULL mengunci tabel '
            .'sepenuhnya, dan tombol itu ada di layar admin yang bisa ditekan pukul sepuluh '
            .'pagi saat poliklinik penuh. Pemeliharaan basis data adalah pekerjaan '
            .'administrator basis data lewat perkakasnya sendiri, bukan tombol di SIMRS.',
    ];

    #[Test]
    public function kode_yang_ditolak_tidak_menggerbangi_layar_apa_pun(): void
    {
        $gerbang = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $gerbang[] = substr($middleware, 4);
                }
            }
        }

        foreach (array_keys(self::DITOLAK) as $kode) {
            $this->assertNotContains($kode, $gerbang,
                "Kode '{$kode}' ditolak dengan alasan tertulis, tapi ternyata sudah menggerbangi "
                .'sebuah route. Kalau keputusannya berubah, hapus dulu barisnya dari daftar '
                .'DITOLAK berikut alasannya — jangan biarkan keduanya bertentangan.');
        }
    }

    #[Test]
    public function kode_yang_ditolak_tidak_bisa_dicentang_di_layar_peran(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        $terkelola = collect(app(ManagedPermissionCatalog::class)->grouped())
            ->flatMap(fn (array $grup) => $grup['permissions']->pluck('code'))
            ->all();

        /*
         * Kalau kode yang ditolak muncul di layar Kelola Peran, admin akan
         * mencentangnya dan mengira ia memberi akses ke sesuatu. Hak yang
         * tampak diberikan tapi tidak membuka apa pun lebih buruk daripada
         * hak yang tidak ada: yang pertama membuat orang berhenti mencari.
         */
        foreach (array_keys(self::DITOLAK) as $kode) {
            $this->assertNotContains($kode, $terkelola,
                "Kode '{$kode}' ditolak tapi muncul di katalog permission terkelola.");
        }
    }

    #[Test]
    public function kode_yang_ditolak_tetap_ada_di_katalog_berikut_alasannya(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        foreach (self::DITOLAK as $kode => $alasan) {
            /*
             * Kodenya TETAP ada di platform.permissions. Menghapusnya dari
             * katalog akan membuat hitung ulang per kode domain U tidak
             * pernah cocok, dan "tidak ada di katalog" tidak bisa dibedakan
             * dari "belum sempat dimasukkan".
             */
            $this->assertTrue(
                Permission::query()->where('code', $kode)->exists(),
                "Kode '{$kode}' hilang dari katalog. Kode yang ditolak tetap harus terdaftar "
                .'supaya hitung ulang per kode tetap cocok dan penolakannya tetap terlihat.'
            );

            // Alasannya harus benar-benar ada, bukan kalimat kosong.
            $this->assertGreaterThan(200, strlen($alasan),
                "Penolakan kode '{$kode}' harus punya alasan yang bisa diperiksa, bukan sekadar penanda.");
        }
    }
}
