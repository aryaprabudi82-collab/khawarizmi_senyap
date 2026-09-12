<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Memilih obat saat meresepkan — cacat yang dilaporkan pengguna.
 *
 * YANG DIALAMI PENGGUNA: mengetik nama obat, memilihnya dari daftar,
 * mengisi jumlah dan aturan pakai, lalu ditolak "The obat field is
 * required." pada kotak yang jelas terisi.
 *
 * SEBABNYA. Formulir menyimpan id obat pada input TERSEMBUNYI yang diisi
 * JavaScript dengan mencocokkan teks kotak isian ke label hasil pencarian
 * persis sama persis. Begitu obat dipilih, kotaknya terisi label lengkap
 * ("Parasetamol 500 mg — tablet"); 250 ms kemudian pencarian berjalan
 * lagi memakai label itu sebagai kata kunci, `name ilike '%...— tablet%'`
 * tidak cocok dengan apa pun, peta label-ke-id jadi kosong, dan id yang
 * sudah benar DIHAPUS — beberapa ratus milidetik setelah dipilih, tanpa
 * satu pun tanda di layar.
 *
 * Uji lama tidak bisa melihatnya karena semuanya memanggil
 * PrescriptionService::addItem() dengan id obat langsung. Tidak satu pun
 * pernah menempuh jalan yang ditempuh manusia: mengetik nama.
 *
 * MAKA YANG DIKUNCI DI SINI ADALAH JALUR MANUSIANYA — nama yang diketik
 * harus cukup, dengan atau tanpa JavaScript yang berhasil.
 */
class PrescriptionDrugPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, PharmacySeeder::class,
        ]);

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-resep', 'name' => 'dr. Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());
    }

    /**
     * INTI CACATNYA. Label lengkap adalah teks yang SUNGGUH ADA di kotak
     * isian setelah petugas memilih dari daftar — dan justru teks itu yang
     * dulu tidak bisa diproses.
     */
    #[Test]
    public function label_lengkap_hasil_memilih_dari_daftar_diterima(): void
    {
        $resep = $this->resepBaru();
        $obat = Drug::query()->where('code', 'OBT-002')->firstOrFail();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => $obat->label(),
                'quantity' => 10,
                'dosage_instruction' => '3x1 sesudah makan',
            ])
            ->assertRedirect();

        $this->assertSame(1, $resep->items()->count());
        $this->assertSame($obat->id, $resep->items()->first()->drug_id);
    }

    /**
     * Pencarian atas label lengkap memang tidak menemukan apa pun — itu
     * kenyataan yang membuat cacatnya lahir, dan dikunci di sini supaya
     * perbaikannya tidak dikira kebetulan.
     */
    #[Test]
    public function pencarian_atas_label_lengkap_memang_tidak_menemukan_apa_pun(): void
    {
        $obat = Drug::query()->where('code', 'OBT-002')->firstOrFail();

        $this->assertSame(0, Drug::query()->search($obat->label())->count(),
            'Kalau ini berubah, JavaScript lama mungkin tampak benar lagi — padahal '
            .'ia tetap bergantung pada kecocokan string yang rapuh.');

        // Tapi penyelesaian di server tetap menemukannya.
        $this->assertSame($obat->id, Drug::resolve($obat->label())['obat']?->id);
    }

    #[Test]
    public function nama_sebagian_yang_cocok_satu_obat_langsung_diterima(): void
    {
        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'Parasetamol',
                'quantity' => 5,
                'dosage_instruction' => '3x1',
            ])
            ->assertRedirect();

        $this->assertSame('OBT-002', $resep->items()->first()->drug->code);
    }

    #[Test]
    public function kode_obat_diterima_apa_adanya(): void
    {
        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'obt-002',   // huruf kecil, sengaja
                'quantity' => 5,
                'dosage_instruction' => '3x1',
            ])
            ->assertRedirect();

        $this->assertSame('OBT-002', $resep->items()->first()->drug->code);
    }

    /**
     * Id yang dikirim JavaScript tetap jadi jalur tercepat — perbaikan ini
     * menambah jalan, bukan menutup yang lama.
     */
    #[Test]
    public function id_dari_javascript_tetap_dipakai_kalau_ada(): void
    {
        $resep = $this->resepBaru();
        $obat = Drug::query()->where('code', 'OBT-003')->firstOrFail();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'drug_id' => $obat->id,
                'obat' => 'teks yang tidak cocok dengan apa pun',
                'quantity' => 5,
                'dosage_instruction' => '1x1',
            ])
            ->assertRedirect();

        $this->assertSame($obat->id, $resep->items()->first()->drug_id);
    }

    /**
     * AMBIGU DITOLAK, BUKAN DITEBAK. Menebak yang pertama berarti
     * meresepkan sediaan yang tidak dipilih siapa pun — dan resep adalah
     * tempat terakhir yang boleh menebak.
     */
    #[Test]
    public function nama_yang_cocok_banyak_sediaan_ditolak_dan_menyebut_pilihannya(): void
    {
        Drug::query()->create([
            'code' => 'UJI-P1', 'name' => 'Parasetamol sirup', 'generic_name' => 'Parasetamol',
            'form' => 'sirup', 'strength' => '120 mg/5 ml', 'unit' => 'botol',
            'sell_price' => 15000, 'is_active' => true,
        ]);

        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'Parasetamol',
                'quantity' => 5,
                'dosage_instruction' => '3x1',
            ])
            ->assertRedirect()
            ->assertSessionHas('galat', fn (string $p) => str_contains($p, '2 sediaan')
                && str_contains($p, 'sirup'));

        $this->assertSame(0, $resep->items()->count());
    }

    /**
     * PESAN GALATNYA MENYEBUT APA YANG HARUS DILAKUKAN. Galat yang cuma
     * berbunyi "wajib diisi" pada kotak yang terisi membuat petugas
     * mengulang hal yang sama berkali-kali — persis yang terjadi sebelum
     * perbaikan ini.
     */
    #[Test]
    public function obat_tak_dikenal_ditolak_dengan_petunjuk_bukan_sekadar_wajib_diisi(): void
    {
        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'Obat yang tidak ada di formularium',
                'quantity' => 5,
                'dosage_instruction' => '3x1',
            ])
            ->assertRedirect()
            ->assertSessionHas('galat', fn (string $p) => str_contains($p, 'tidak ditemukan')
                && str_contains($p, 'pilih dari daftar'));
    }

    /** Isian yang sudah diketik tidak hilang saat formulirnya ditolak. */
    #[Test]
    public function isian_dikembalikan_saat_ditolak(): void
    {
        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'Tidak ada',
                'quantity' => 7,
                'dosage_instruction' => '2x1 sebelum makan',
            ])
            ->assertSessionHasInput('quantity', 7)
            ->assertSessionHasInput('dosage_instruction', '2x1 sebelum makan');
    }

    /**
     * Kekuatan tidak diulang pada label. Seeder menulis nama "Parasetamol
     * 500 mg" sekaligus mengisi strength "500 mg", dan label lama
     * menggabungkan keduanya jadi "Parasetamol 500 mg 500 mg — tablet" —
     * petugas yang membacanya wajar menduga ada dua sediaan berbeda.
     */
    #[Test]
    public function label_obat_tidak_mengulang_kekuatannya(): void
    {
        $label = Drug::query()->where('code', 'OBT-002')->firstOrFail()->label();

        $this->assertSame(1, substr_count($label, '500 mg'), "Label: {$label}");
    }

    /**
     * PESAN VALIDASI BERBAHASA INDONESIA. APP_LOCALE sudah `id` sejak
     * awal, tapi tidak ada berkas terjemahan sama sekali — jadi Laravel
     * diam-diam jatuh ke bahasa Inggris, dan pengguna membaca
     * "The obat field is required."
     */
    #[Test]
    public function pesan_validasi_berbahasa_indonesia(): void
    {
        $resep = $this->resepBaru();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'obat' => 'Parasetamol',
                'dosage_instruction' => '3x1',
            ])
            ->assertSessionHasErrors(['quantity' => 'jumlah wajib diisi.']);
    }

    // ------------------------------------------------------------- pembantu

    private function resepBaru(): Prescription
    {
        return app(PrescriptionService::class)->create($this->daftarkan()->id);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Pemilih Obat '.$urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
