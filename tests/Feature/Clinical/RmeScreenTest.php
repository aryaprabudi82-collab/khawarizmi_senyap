<?php

namespace Tests\Feature\Clinical;

use App\Modules\Clinical\Models\Assessment;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Database\Seeders\UserSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layar RME — DITELUSURI LEWAT HTTP, bukan lewat service.
 *
 * MENGAPA BENTUKNYA BEGINI. Bug pemilih obat dulu lolos karena seluruh uji
 * memanggil service langsung — jalur yang tidak pernah ditempuh manusia.
 * Servicenya benar, ujinya hijau, layarnya tetap menolak isian yang benar.
 *
 * Yang hanya bisa gagal lewat HTTP dan dikunci di sini:
 *
 *   - Blade yang merujuk variabel tak terkirim (meledak saat DIRENDER,
 *     bukan saat controller mengembalikannya),
 *   - partial yang namanya salah,
 *   - gerbang hak yang menolak orang yang berhak, atau meloloskan yang tidak,
 *   - panel kanan yang tampil tapi ISINYA kosong padahal datanya ada.
 */
class RmeScreenTest extends TestCase
{
    use RefreshDatabase;

    private int $registrasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        $this->registrasi = $this->buatKunjungan();
    }

    // --------------------------------------------------------------- gerbang

    #[Test]
    public function dokter_bisa_membuka_layar_rme(): void
    {
        $this->actingAs($this->pengguna('dokter1'))
            ->get(route('rme.edit', $this->registrasi))
            ->assertOk()
            ->assertSee('LABORATORY')
            ->assertSee('RADIOLOGY')
            ->assertSee('PRESCRIPTION (E-RESEP)', false)
            ->assertSee('Total Invoice');
    }

    /**
     * Setengah pemeriksaan yang sering terlewat: gerbang yang menolak SEMUA
     * orang juga "punya gerbang". Tanpa uji ini, gerbang yang tidak pernah
     * meloloskan siapa pun akan lolos uji di atas dengan sempurna.
     */
    #[Test]
    public function peran_tanpa_hak_ditolak(): void
    {
        $this->actingAs($this->pengguna('parkir1'))
            ->get(route('rme.edit', $this->registrasi))
            ->assertForbidden();
    }

    #[Test]
    public function tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('rme.edit', $this->registrasi))->assertRedirect(route('masuk'));
    }

    // ------------------------------------------------------------- sub-tab

    /**
     * Ketiga sub-tab terbuka, dan masing-masing memuat asesmen ber-`kind`
     * sendiri pada kunjungan yang sama.
     */
    #[Test]
    public function ketiga_sub_tab_terbuka_dan_terpisah_per_kind(): void
    {
        $dokter = $this->pengguna('dokter1');

        foreach ([Assessment::KIND_SOAP, Assessment::KIND_KEPERAWATAN, Assessment::KIND_LANJUTAN] as $kind) {
            $this->actingAs($dokter)
                ->get(route('rme.edit', ['registrasi' => $this->registrasi, 'jenis' => $kind]))
                ->assertOk();
        }

        $this->assertSame(
            3,
            Assessment::query()->where('registration_id', $this->registrasi)->count(),
            'Tiap sub-tab harus menghasilkan asesmen tersendiri, bukan menimpa yang sama'
        );
    }

    /**
     * Perawat TIDAK melihat tombol yang bukan kewenangannya.
     *
     * Tombolnya tetap tampil tapi NONAKTIF — supaya "tidak punya hak" tidak
     * lagi terbaca sama dengan "tombolnya rusak".
     */
    #[Test]
    public function perawat_melihat_tombol_resep_dalam_keadaan_nonaktif(): void
    {
        $respons = $this->actingAs($this->pengguna('perawat1'))
            ->get(route('rme.edit', $this->registrasi))
            ->assertOk();

        $isi = $respons->getContent();

        $this->assertStringContainsString('Perlu hak akses: resep_obat', $isi,
            'Perawat harus diberi tahu MENGAPA tombol resep tidak bisa ditekan');
    }

    // -------------------------------------------------- panel berisi data nyata

    /**
     * INI INTI PERBAIKANNYA. Sebelum ini, hasil lab dan resep kunjungan
     * berjalan tidak bisa dilihat sama sekali dari layar RME — dokter harus
     * berpindah layar untuk membaca hasil yang ia minta sendiri.
     */
    #[Test]
    public function panel_kanan_menampilkan_resep_yang_sudah_ada(): void
    {
        $this->buatResep();

        $this->actingAs($this->pengguna('dokter1'))
            ->get(route('rme.edit', $this->registrasi))
            ->assertOk()
            ->assertSee('Parasetamol 500 mg')
            ->assertDontSee('Belum ada resep obat');
    }

    #[Test]
    public function total_tagihan_nol_dibedakan_dari_belum_ada_tagihan(): void
    {
        $this->actingAs($this->pengguna('dokter1'))
            ->get(route('rme.edit', $this->registrasi))
            ->assertOk()
            ->assertSee('Belum ada tagihan');
    }

    // ------------------------------------------------------------ jalur tulis

    #[Test]
    public function menyimpan_soap_lewat_formulir(): void
    {
        $asesmen = $this->asesmen(Assessment::KIND_SOAP);

        $this->actingAs($this->pengguna('dokter1'))
            ->post(route('rme.update', $asesmen), [
                'chief_complaint' => 'Demam tiga hari',
                'subjective' => 'Demam naik turun',
                'objective' => 'Suhu 38,5',
                'assessment' => 'Suspek demam dengue',
                'plan' => 'Cek darah lengkap',
            ])
            ->assertRedirect();

        $this->assertSame('Demam tiga hari', $asesmen->refresh()->chief_complaint);
    }

    #[Test]
    public function mencatat_alergi_lewat_formulir(): void
    {
        $asesmen = $this->asesmen(Assessment::KIND_SOAP);

        $this->actingAs($this->pengguna('dokter1'))
            ->post(route('rme.alergi.simpan', $asesmen), [
                'substance' => 'Amoksisilin',
                'category' => 'obat',
                'severity' => 'berat',
                'reaction' => 'Ruam',
            ])
            ->assertRedirect();

        $this->assertSame(1, DB::table('clinical.allergies')
            ->where('patient_id', $asesmen->patient_id)->count());
    }

    // --------------------------------------------------------------- pembantu

    private function buatKunjungan(): int
    {
        $pasien = DB::table('identity.patients')->insertGetId([
            'medical_record_number' => '00000099',
            'name' => 'Pasien Uji RME',
            'sex' => 'L',
            'birth_date' => '1990-05-17',
            'address' => 'Jl. Salemba Raya 6',
            'phone' => '081200000000',
            'registered_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = DB::table('organization.units')->where('is_active', true)->first();
        $penjamin = DB::table('catalog.payers')->first();

        return DB::table('encounter.registrations')->insertGetId([
            'registration_number' => '20260914-00099',
            'patient_id' => $pasien,
            'unit_id' => $unit->id,
            'payer_id' => $penjamin?->id,
            'patient_mrn' => '00000099',
            'patient_name' => 'Pasien Uji RME',
            'unit_name' => $unit->name,
            'payer_name' => $penjamin?->name ?? 'Umum',
            'service_date' => now()->toDateString(),
            'registered_at' => now(),
            'queue_number' => 1,
            'visit_type' => 'baru',
            'care_type' => 'ralan',
            'status' => 'terdaftar',
            'registration_fee' => '50000.00',
            'payment_status' => 'belum-bayar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buatResep(): void
    {
        $obat = DB::table('pharmacy.drugs')->insertGetId([
            'code' => 'OBT-RME-01',
            'name' => 'Parasetamol 500 mg',
            'category' => 'obat',
            'unit' => 'tablet',
            'sell_price' => '1500.00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resep = DB::table('pharmacy.prescriptions')->insertGetId([
            'prescription_number' => 'RSP-RME-01',
            'registration_id' => $this->registrasi,
            'registration_number' => '20260914-00099',
            'patient_id' => DB::table('encounter.registrations')->where('id', $this->registrasi)->value('patient_id'),
            'patient_mrn' => '00000099',
            'patient_name' => 'Pasien Uji RME',
            'kind' => 'rawat-jalan',
            'status' => 'ditulis',
            'prescribed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pharmacy.prescription_items')->insert([
            'prescription_id' => $resep,
            'drug_id' => $obat,
            'drug_name' => 'Parasetamol 500 mg',
            'drug_unit' => 'tablet',
            'quantity' => 10,
            'dosage_instruction' => '3 x 1 tablet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asesmen(string $kind): Assessment
    {
        $this->actingAs($this->pengguna('dokter1'))
            ->get(route('rme.edit', ['registrasi' => $this->registrasi, 'jenis' => $kind]));

        return Assessment::query()
            ->where('registration_id', $this->registrasi)
            ->where('kind', $kind)
            ->firstOrFail();
    }

    private function pengguna(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }
}
