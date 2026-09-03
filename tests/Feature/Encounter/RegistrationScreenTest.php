<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->petugas = $this->buatPengguna('petugas-daftar');
    }

    #[Test]
    public function tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('registrasi.index'))->assertRedirect(route('masuk'));
        $this->get(route('pasien.index'))->assertRedirect(route('masuk'));
    }

    #[Test]
    public function halaman_masuk_dapat_dibuka(): void
    {
        $this->get(route('masuk'))
            ->assertOk()
            ->assertSee('Masuk ke akun Anda');
    }

    #[Test]
    public function petugas_dapat_masuk_dan_diarahkan_ke_papan_antrean(): void
    {
        $this->post(route('masuk.kirim'), [
            'username' => $this->petugas->username,
            'password' => 'password',
        ])->assertRedirect(route('beranda'));

        $this->assertAuthenticatedAs($this->petugas);
    }

    #[Test]
    public function kata_sandi_salah_ditolak_dengan_pesan_yang_jelas(): void
    {
        $this->from(route('masuk'))
            ->post(route('masuk.kirim'), [
                'username' => $this->petugas->username,
                'password' => 'salah',
            ])
            ->assertRedirect(route('masuk'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    #[Test]
    public function akun_nonaktif_tidak_bisa_masuk(): void
    {
        $this->petugas->update(['is_active' => false]);

        $this->post(route('masuk.kirim'), [
            'username' => $this->petugas->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    #[Test]
    public function masuk_dan_keluar_tercatat_di_jejak_audit(): void
    {
        $this->post(route('masuk.kirim'), [
            'username' => $this->petugas->username,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('platform.audit_logs', [
            'user_id' => $this->petugas->id,
            'action' => 'login',
        ]);

        $this->post(route('keluar'));

        $this->assertDatabaseHas('platform.audit_logs', [
            'user_id' => $this->petugas->id,
            'action' => 'logout',
        ]);
    }

    #[Test]
    public function papan_antrean_menampilkan_pendaftaran_hari_ini(): void
    {
        $this->daftarkan('Ani Lestari');
        $this->daftarkan('Joko Prasetyo');

        $this->actingAs($this->petugas)
            ->get(route('registrasi.index'))
            ->assertOk()
            ->assertSee('Papan Antrean')
            ->assertSee('Ani Lestari')
            ->assertSee('Joko Prasetyo')
            ->assertSee('Poliklinik Umum');
    }

    #[Test]
    public function papan_antrean_dapat_disaring_per_unit(): void
    {
        $poliAnak = Unit::query()->where('code', 'POL-ANAK')->firstOrFail();

        $this->daftarkan('Ani Lestari');
        $this->daftarkan('Bayi Sehat', $poliAnak);

        $this->actingAs($this->petugas)
            ->get(route('registrasi.index', ['unit_id' => $poliAnak->id]))
            ->assertOk()
            ->assertSee('Bayi Sehat')
            ->assertDontSee('Ani Lestari');
    }

    #[Test]
    public function formulir_pendaftaran_menampilkan_hasil_pencarian_pasien(): void
    {
        $pasien = $this->buatPasien('Siti Aminah');

        $this->actingAs($this->petugas)
            ->get(route('registrasi.create', ['cari' => 'Siti']))
            ->assertOk()
            ->assertSee('Siti Aminah')
            ->assertSee($pasien->medical_record_number);
    }

    #[Test]
    public function pencarian_tanpa_hasil_menawarkan_pendaftaran_pasien_baru(): void
    {
        $this->actingAs($this->petugas)
            ->get(route('registrasi.create', ['cari' => 'Nama Yang Tidak Ada']))
            ->assertOk()
            ->assertSee('Daftarkan sebagai pasien baru');
    }

    #[Test]
    public function petugas_dapat_menyimpan_registrasi_lewat_formulir(): void
    {
        $pasien = $this->buatPasien('Rudi Hartono');
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $penjamin = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $this->actingAs($this->petugas)
            ->post(route('registrasi.store'), [
                'pasien_id' => $pasien->id,
                'unit_id' => $unit->id,
                'penjamin_id' => $penjamin->id,
                'tanggal' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.registrations', [
            'patient_id' => $pasien->id,
            'unit_id' => $unit->id,
            'queue_number' => 1,
            'visit_type' => 'baru',
        ]);
    }

    #[Test]
    public function kesalahan_aturan_bisnis_dikembalikan_sebagai_pesan_bukan_error_500(): void
    {
        $pasien = $this->buatPasien('Rudi Hartono');
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $penjamin = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $muatan = [
            'pasien_id' => $pasien->id,
            'unit_id' => $unit->id,
            'penjamin_id' => $penjamin->id,
            'tanggal' => now()->toDateString(),
        ];

        $this->actingAs($this->petugas)->post(route('registrasi.store'), $muatan);

        // Pendaftaran kedua di unit dan hari yang sama.
        $this->actingAs($this->petugas)
            ->post(route('registrasi.store'), $muatan)
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertSame(1, Registration::query()->count());
    }

    #[Test]
    public function isian_wajib_divalidasi(): void
    {
        $this->actingAs($this->petugas)
            ->post(route('registrasi.store'), [])
            ->assertSessionHasErrors(['pasien_id', 'unit_id', 'penjamin_id', 'tanggal']);
    }

    #[Test]
    public function petugas_dapat_membatalkan_registrasi_dengan_alasan(): void
    {
        $registrasi = $this->daftarkan('Ani Lestari');

        $this->actingAs($this->petugas)
            ->post(route('registrasi.batal', $registrasi), ['alasan' => 'Pasien pulang sebelum dilayani.'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(Registration::STATUS_BATAL, $registrasi->fresh()->status);
    }

    #[Test]
    public function pembatalan_tanpa_alasan_ditolak(): void
    {
        $registrasi = $this->daftarkan('Ani Lestari');

        $this->actingAs($this->petugas)
            ->post(route('registrasi.batal', $registrasi), ['alasan' => ''])
            ->assertSessionHasErrors('alasan');

        $this->assertSame(Registration::STATUS_TERDAFTAR, $registrasi->fresh()->status);
    }

    #[Test]
    public function pasien_baru_dapat_disimpan_dari_formulir(): void
    {
        $this->actingAs($this->petugas)
            ->post(route('pasien.store'), [
                'name' => 'Wahyu Setiawan',
                'sex' => 'L',
                'birth_date' => '1988-04-17',
                'birth_place' => 'Depok',
                'nik' => '3276041704880002',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('identity.patients', [
            'name' => 'Wahyu Setiawan',
            'nik' => '3276041704880002',
        ]);
    }

    #[Test]
    public function petugas_daftar_bisa_mendaftarkan_pasien_untuk_rawat_inap(): void
    {
        $pasien = $this->buatPasien('Rudi Hartono');
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $penjamin = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $this->actingAs($this->petugas)
            ->post(route('registrasi.store'), [
                'pasien_id' => $pasien->id,
                'unit_id' => $unit->id,
                'penjamin_id' => $penjamin->id,
                'tanggal' => now()->toDateString(),
                'jenis_rawat' => 'ranap',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.registrations', [
            'patient_id' => $pasien->id,
            'care_type' => 'ranap',
        ]);
    }

    #[Test]
    public function peran_dengan_registrasi_tapi_tanpa_permintaan_ranap_tidak_bisa_pilih_ranap(): void
    {
        // Setiap peran bawaan yang punya 'registrasi' saat ini juga otomatis
        // punya 'permintaan_ranap' lewat wholesale context encounter — jadi
        // gerbang lapis-kedua di RegistrationController (bukan cuma tautan
        // formulir yang disembunyikan) diuji lewat peran custom buatan tes ini,
        // yang sengaja hanya diberi 'registrasi' saja.
        $peranTerbatas = Role::query()->create(['code' => 'uji-registrasi-saja', 'name' => 'Uji Registrasi Saja', 'is_system' => false]);
        $peranTerbatas->syncPermissionCodes(['registrasi']);

        $petugasTerbatas = $this->buatPengguna('uji-registrasi-saja');

        $pasien = $this->buatPasien('Rudi Hartono');
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $penjamin = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $this->actingAs($petugasTerbatas)
            ->post(route('registrasi.store'), [
                'pasien_id' => $pasien->id,
                'unit_id' => $unit->id,
                'penjamin_id' => $penjamin->id,
                'tanggal' => now()->toDateString(),
                'jenis_rawat' => 'ranap',
            ])
            ->assertSessionHasErrors('jenis_rawat');

        $this->assertDatabaseMissing('encounter.registrations', ['patient_id' => $pasien->id]);

        // Tapi tetap bisa mendaftarkan rawat jalan biasa.
        $this->actingAs($petugasTerbatas)
            ->post(route('registrasi.store'), [
                'pasien_id' => $pasien->id,
                'unit_id' => $unit->id,
                'penjamin_id' => $penjamin->id,
                'tanggal' => now()->toDateString(),
                'jenis_rawat' => 'ralan',
            ])
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function peran_tanpa_kapabilitas_registrasi_ditolak(): void
    {
        $adminMaster = $this->buatPengguna('admin-master');

        $this->actingAs($adminMaster)
            ->get(route('registrasi.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji-' . $kodePeran,
            'name' => 'Pengguna ' . $kodePeran,
            'password' => 'password',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $kodePeran)->firstOrFail());

        return $user->fresh(['roles']);
    }

    private function buatPasien(string $nama): Patient
    {
        return app(PatientRegistry::class)->register([
            'name' => $nama,
            'sex' => 'L',
            'birth_date' => '1990-01-01',
        ]);
    }

    private function daftarkan(string $nama, ?Unit $unit = null): Registration
    {
        return app(\App\Modules\Encounter\Services\RegistrationService::class)->register(
            patientId: $this->buatPasien($nama)->id,
            unitId: ($unit ?? Unit::query()->where('code', 'POL-UMUM')->firstOrFail())->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: null,
        );
    }
}
