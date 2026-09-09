<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Institution;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Models\SettingRevision;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\SettingStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pengaturan aplikasi & identitas institusi (domain U item A).
 *
 * Yang dikunci:
 *
 * 1. KOSONG BUKAN NOL — pengaturan yang belum ditetapkan RSP UI tidak boleh
 *    terbaca sebagai angka nol lalu tertagih sebagai gratis.
 * 2. SETIAP PERUBAHAN PUNYA RIWAYAT berikut nilai lama dan tanggal berlaku.
 * 3. NILAI DIBACA PER TANGGAL — tagihan lampau tidak dihitung ulang dengan
 *    tarif hari ini.
 * 4. IDENTITAS RUMAH SAKIT TIDAK DIKUNCI OLEH NAMANYA SENDIRI.
 */
class SettingTest extends TestCase
{
    use RefreshDatabase;

    private SettingStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SettingStore::class);
    }

    #[Test]
    public function pengaturan_lahir_belum_ditetapkan_dan_kosong_bukan_nol(): void
    {
        /*
         * Besaran embalase adalah keputusan RSP UI. Menebaknya berarti
         * sistem mulai menagih dengan angka yang tidak pernah diputuskan
         * siapa pun — dan nol adalah tebakan yang paling berbahaya karena
         * ia terbaca wajar: pasien tidak protes, dan rumah sakit kehilangan
         * pendapatan tanpa ada yang menyadari.
         */
        $this->assertNull($this->store->get('farmasi:embalase_per_obat'));
        $this->assertNotSame(0.0, $this->store->get('farmasi:embalase_per_obat'));

        $belum = $this->store->belumDitetapkan();

        $this->assertContains('farmasi:embalase_per_obat', $belum);
        $this->assertContains('billing:format_nota', $belum);

        // Yang memang punya jawaban bawaan yang tidak perlu diputuskan
        // RSP UI (zona waktu operasional) sudah terisi.
        $this->assertNotContains('app:timezone', $belum);
    }

    #[Test]
    public function setiap_perubahan_menyimpan_nilai_lama_dan_tanggal_berlaku(): void
    {
        $this->store->set('farmasi:embalase_per_obat', 500, null, 'Kepala Instalasi Farmasi',
            'Penetapan awal sesuai SK direktur.', '2026-01-01');

        $this->store->set('farmasi:embalase_per_obat', 1000, null, 'Kepala Instalasi Farmasi',
            'Penyesuaian biaya kemasan.', '2026-07-01');

        $revisi = SettingRevision::query()
            ->whereIn('setting_id', Setting::query()->where('key', 'farmasi:embalase_per_obat')->pluck('id'))
            ->orderBy('effective_from')->get();

        $this->assertCount(2, $revisi);
        $this->assertNull($revisi[0]->old_value);
        $this->assertSame('500', $revisi[0]->new_value);

        // Nilai lama DISALIN, tidak dibaca ulang dari revisi sebelumnya:
        // rantainya tetap terbaca walau ada revisi yang dihapus atau
        // diimpor belakangan.
        $this->assertSame('500', $revisi[1]->old_value);
        $this->assertSame('1000', $revisi[1]->new_value);
        $this->assertSame('Penyesuaian biaya kemasan.', $revisi[1]->reason);
    }

    #[Test]
    public function nilai_dibaca_menurut_tanggal_bukan_menurut_hari_ini(): void
    {
        $this->store->set('farmasi:embalase_per_obat', 500, berlakuSejak: '2026-01-01');
        $this->store->set('farmasi:embalase_per_obat', 1000, berlakuSejak: '2026-07-01');

        /*
         * Inti seluruh tabel ini. `set_embalase` Khanza cuma menyimpan nilai
         * berjalan, jadi menagih ulang resep Maret akan memakai tarif Juli —
         * kesalahan yang tidak akan terlihat oleh siapa pun yang memeriksa,
         * karena hasilnya tetap berupa angka yang wajar.
         */
        $this->assertSame(500.0, $this->store->valueAt('farmasi:embalase_per_obat', '2026-03-15'));
        $this->assertSame(1000.0, $this->store->valueAt('farmasi:embalase_per_obat', '2026-08-15'));

        // Tepat pada hari berlakunya, nilai baru yang dipakai.
        $this->assertSame(1000.0, $this->store->valueAt('farmasi:embalase_per_obat', '2026-07-01'));

        // SEBELUM pengaturannya pernah ada: null, bukan nilai pertama.
        // Mengarang nilai untuk masa sebelum ia ditetapkan berarti menagih
        // dengan aturan yang belum berlaku.
        $this->assertNull($this->store->valueAt('farmasi:embalase_per_obat', '2025-12-31'));
    }

    #[Test]
    public function boolean_tidak_sama_dengan_belum_ditetapkan(): void
    {
        $this->assertNull($this->store->get('billing:input_parsial'));

        $this->store->set('billing:input_parsial', false);

        // "Tidak" adalah keputusan; "belum ditetapkan" bukan. Menyamakannya
        // membuat pengaturan yang sengaja dimatikan menghilang dari daftar
        // pekerjaan yang belum selesai — atau sebaliknya, terus muncul di
        // sana selamanya.
        $this->assertFalse($this->store->get('billing:input_parsial'));
        $this->assertNotContains('billing:input_parsial', $this->store->belumDitetapkan());
    }

    #[Test]
    public function pilihan_di_luar_daftar_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan pilihan yang sah');

        // Tanpa penjagaan ini yang terjadi bukan galat, melainkan harga yang
        // salah diam-diam: mesin harga tidak mengenali katanya dan jatuh ke
        // cabang bawaannya.
        $this->store->set('farmasi:harga_dasar', 'harga-karangan');
    }

    #[Test]
    public function pengaturan_angka_menolak_isian_bukan_angka(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus berupa angka');

        $this->store->set('farmasi:tuslah_per_obat', 'seribu rupiah');
    }

    #[Test]
    public function pengaturan_yang_tidak_dikenal_ditolak_bukan_dibuat_diam_diam(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenal');

        // Kunci yang salah ketik harus berhenti di sini. Kalau ia dibuat
        // otomatis, pemanggil yang salah ketik akan membaca null selamanya
        // dari kunci yang tidak pernah muncul di layar mana pun.
        $this->store->get('farmasi:embalase_perobat');
    }

    // ------------------------------------------------- identitas institusi

    #[Test]
    public function mengganti_nama_rumah_sakit_tidak_melahirkan_rumah_sakit_kedua(): void
    {
        Institution::query()->create(['id' => Institution::ID, 'name' => 'RS Pendidikan UI']);
        Institution::query()->updateOrCreate(['id' => Institution::ID], ['name' => 'RSP Universitas Indonesia']);

        /*
         * `setting` Khanza memakai `nama_instansi` sebagai PRIMARY KEY, jadi
         * pergantian nama menyisakan dua baris: rumah sakit lama tetap ada
         * bersama seluruh rujukan yang menempel padanya. Nama adalah
         * atribut; identitas tidak.
         */
        $this->assertSame(1, Institution::query()->count());
        $this->assertSame('RSP Universitas Indonesia', Institution::berlaku()->name);
    }

    #[Test]
    public function basis_data_menolak_baris_institusi_kedua(): void
    {
        Institution::query()->create(['id' => Institution::ID, 'name' => 'RSP UI']);

        $this->expectException(QueryException::class);

        // Aturannya ditegakkan CHECK, bukan hanya kebiasaan pemanggil:
        // impor dan perbaikan data manual lewat di bawah kode aplikasi.
        Institution::query()->create(['id' => 2, 'name' => 'RS Bayangan']);
    }

    #[Test]
    public function identitas_kosong_terbaca_kosong_bukan_rumah_sakit_bernama_apa_pun(): void
    {
        // Rumah sakit yang belum mengisi identitasnya harus terbaca belum
        // mengisi — bukan tercetak di kuitansi dengan nama karangan.
        $this->assertNull(Institution::berlaku());
    }

    // ------------------------------------------------------------- layar

    #[Test]
    public function gerbang_pengaturan_terpisah_dari_gerbang_kelola_pengguna(): void
    {
        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        /*
         * Yang mengelola akun tidak mesti orang yang boleh mengubah tarif
         * embalase atau format nota. Di sini keduanya kebetulan dipegang
         * peran yang sama (admin-sistem berkonteks platform), tapi
         * gerbangnya tetap dua supaya peran custom bisa memisahkannya.
         */
        $admin = User::query()->create([
            'username' => 'uji-set', 'name' => 'Admin Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'admin-sistem')->firstOrFail());

        $this->actingAs($admin)->get(route('platform.pengaturan.index'))
            ->assertOk()
            ->assertSee('Identitas Rumah Sakit')
            ->assertSee('belum ditetapkan');

        $perawat = User::query()->create([
            'username' => 'uji-perawat', 'name' => 'Perawat Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());

        $this->actingAs($perawat)->get(route('platform.pengaturan.index'))->assertForbidden();
    }

    #[Test]
    public function perubahan_pengaturan_uang_lewat_layar_wajib_beralasan(): void
    {
        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $admin = User::query()->create([
            'username' => 'uji-set2', 'name' => 'Admin Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'admin-sistem')->firstOrFail());

        $embalase = Setting::query()->where('key', 'farmasi:embalase_per_obat')->firstOrFail();

        $this->actingAs($admin)->post(route('platform.pengaturan.perbarui', $embalase), [
            'value' => '750',
            'effective_from' => '2026-09-01',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post(route('platform.pengaturan.perbarui', $embalase), [
            'value' => '750',
            'effective_from' => '2026-09-01',
            'reason' => 'SK Direktur 12/2026.',
        ])->assertRedirect();

        $this->assertSame(750.0, app(SettingStore::class)->get('farmasi:embalase_per_obat'));
    }
}
