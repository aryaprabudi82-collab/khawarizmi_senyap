<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\PatientRequest;
use App\Modules\Correspondence\Models\PropertyHandover;
use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\PatientRequestService;
use App\Modules\Correspondence\Services\PropertyHandoverService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Permintaan & pernyataan pasien (domain P item B).
 *
 * Yang dikunci:
 *
 * 1. PENOLAKAN WAJIB BERALASAN, PEMENUHAN TIDAK — permintaan yang dipenuhi
 *    berbukti pada perbuatannya sendiri; yang ditolak tidak meninggalkan
 *    apa pun selain catatan ini.
 * 2. TANGGAL CUTI HANYA UNTUK CUTI, ditegakkan service DAN basis data.
 * 3. SERAH TERIMA PUNYA DUA ARAH, dan sisa titipan DIHITUNG.
 * 4. LABEL WADAH WAJIB UNTUK ANGGOTA TUBUH.
 * 5. PERNYATAAN MEMILIH DPJP BUKAN PENUGASAN DPJP.
 */
class PatientRequestTest extends TestCase
{
    use RefreshDatabase;

    private PatientRequestService $permintaan;

    private PropertyHandoverService $serahTerima;

    private ConsentService $consents;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->permintaan = app(PatientRequestService::class);
        $this->serahTerima = app(PropertyHandoverService::class);
        $this->consents = app(ConsentService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-petugas-hpk', 'name' => 'Petugas Uji HPK',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());
    }

    // ================================================ permintaan pasien

    #[Test]
    public function permintaan_lahir_dalam_keadaan_diminta(): void
    {
        $p = $this->minta(PatientRequest::JENIS_PRIVASI, 'Mohon identitas tidak diberitahukan kepada penjenguk');

        $this->assertMatchesRegularExpression('/^PRM-\d{4}-\d{5}$/', $p->request_number);
        $this->assertTrue($p->belumDijawab());
        $this->assertNull($p->responded_at);
    }

    #[Test]
    public function penolakan_tanpa_alasan_ditolak(): void
    {
        $p = $this->minta(PatientRequest::JENIS_SECOND_OPINION, 'Minta pendapat dokter lain');

        // Permintaan yang ditolak tidak meninggalkan apa pun selain catatan
        // ini — dan justru itulah peristiwa yang paling perlu berjejak.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/harus menyebutkan alasannya/');

        $this->permintaan->decline($p, '   ', 'Ns. Uji');
    }

    #[Test]
    public function pemenuhan_tidak_wajib_berketerangan(): void
    {
        $p = $this->minta(PatientRequest::JENIS_BINROHTAL, 'Mohon didatangkan rohaniwan');

        // Ketaksimetrisan yang disengaja: pemenuhan berbukti pada
        // perbuatannya sendiri — rohaniwan yang datang.
        $hasil = $this->permintaan->fulfil($p, 'Ns. Uji');

        $this->assertSame(PatientRequest::STATUS_DIPENUHI, $hasil->status);
        $this->assertNull($hasil->response_note);
        $this->assertNotNull($hasil->responded_at);
    }

    #[Test]
    public function basis_data_menolak_penolakan_tanpa_alasan_meski_service_dilewati(): void
    {
        $this->expectException(QueryException::class);

        DB::table('correspondence.patient_requests')->insert([
            'request_number' => 'PRM-UJI-LANGSUNG', 'request_type' => 'privasi',
            'patient_name' => 'Budi', 'requested_at' => now(),
            'requester_name' => 'Budi', 'requester_relationship' => 'diri-sendiri',
            'detail' => 'Lewat pintu belakang', 'status' => 'ditolak',
            'response_note' => null, 'responded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function permintaan_tidak_bisa_dijawab_dua_kali(): void
    {
        $p = $this->permintaan->fulfil(
            $this->minta(PatientRequest::JENIS_PRIVASI, 'Tirai penutup'),
            'Ns. Uji'
        );

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/sudah dijawab/');

        $this->permintaan->decline($p, 'Berubah pikiran', 'Ns. Uji');
    }

    #[Test]
    public function permintaan_atas_nama_pasien_harus_menyebut_hubungan_yang_dikenal(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/bukan permintaan pasien/');

        $this->permintaan->request([
            'request_type' => PatientRequest::JENIS_PRIVASI,
            'patient_name' => 'Budi', 'requester_name' => 'Orang Lewat',
            'requester_relationship' => 'tetangga', 'detail' => 'Minta privasi',
        ], $this->petugas->id);
    }

    #[Test]
    public function tanggal_cuti_hanya_berlaku_untuk_cuti_perawatan(): void
    {
        // Permohonan privasi bertanggal mulai-selesai akan terbaca sebagai
        // "privasi berlaku sampai tanggal sekian", padahal kolomnya cuma
        // salah terisi.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/hanya berlaku untuk pengajuan cuti/');

        $this->permintaan->request([
            'request_type' => PatientRequest::JENIS_PRIVASI,
            'patient_name' => 'Budi', 'requester_name' => 'Budi',
            'requester_relationship' => 'diri-sendiri', 'detail' => 'Minta privasi',
            'leave_starts_at' => now()->toDateString(),
            'leave_ends_at' => now()->addDay()->toDateString(),
        ], $this->petugas->id);
    }

    #[Test]
    public function cuti_perawatan_wajib_bertanggal_dan_tidak_boleh_terbalik(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak boleh mendahului/');

        $this->permintaan->request([
            'request_type' => PatientRequest::JENIS_CUTI,
            'patient_name' => 'Budi', 'requester_name' => 'Budi',
            'requester_relationship' => 'diri-sendiri', 'detail' => 'Menghadiri pemakaman',
            'leave_starts_at' => now()->addDays(3)->toDateString(),
            'leave_ends_at' => now()->addDay()->toDateString(),
        ], $this->petugas->id);
    }

    #[Test]
    public function cuti_perawatan_bertanggal_lengkap_diterima(): void
    {
        $p = $this->permintaan->request([
            'request_type' => PatientRequest::JENIS_CUTI,
            'patient_name' => 'Budi', 'requester_name' => 'Budi',
            'requester_relationship' => 'diri-sendiri', 'detail' => 'Menghadiri pemakaman',
            'leave_starts_at' => now()->addDay()->toDateString(),
            'leave_ends_at' => now()->addDays(2)->toDateString(),
        ], $this->petugas->id);

        $this->assertNotNull($p->leave_starts_at);
        $this->assertNotNull($p->leave_ends_at);
    }

    #[Test]
    public function permintaan_yang_belum_dijawab_terdaftar_dari_yang_paling_lama(): void
    {
        $lama = $this->minta(PatientRequest::JENIS_PERLINDUNGAN, 'Merasa terancam oleh pengunjung');
        $lama->update(['requested_at' => now()->subDays(3)]);

        $baru = $this->minta(PatientRequest::JENIS_PRIVASI, 'Minta kamar tidak didaftarkan');

        $dipenuhi = $this->minta(PatientRequest::JENIS_BINROHTAL, 'Minta rohaniwan');
        $this->permintaan->fulfil($dipenuhi, 'Ns. Uji');

        $tertunggak = $this->permintaan->outstanding();

        /*
         * Layar riwayat biasa menampilkan yang terbaru — dan itu justru
         * menyembunyikan permintaan lama yang terlantar. Yang paling lama
         * menunggu harus di atas.
         */
        $this->assertCount(2, $tertunggak);
        $this->assertSame($lama->id, $tertunggak->first()->id);
        $this->assertSame($baru->id, $tertunggak->last()->id);
    }

    #[Test]
    public function lama_menunggu_dihitung_bukan_disimpan(): void
    {
        $p = $this->minta(PatientRequest::JENIS_PERLINDUNGAN, 'Merasa terancam');
        $p->update(['requested_at' => now()->subHours(30)]);

        // Angka yang disimpan akan berhenti bertambah begitu barisnya tidak
        // disentuh lagi — padahal justru yang tidak disentuh yang perlu
        // dilihat.
        $this->assertEqualsWithDelta(30.0, $p->refresh()->jamMenunggu(), 0.1);

        $this->permintaan->fulfil($p, 'Ns. Uji');
        $this->assertNull($p->refresh()->jamMenunggu());
    }

    // ==================================================== serah terima

    #[Test]
    public function titipan_yang_belum_diserahkan_terhitung_masih_dipegang(): void
    {
        $dompet = $this->titip('barang-pasien', 'Dompet kulit cokelat berisi KTP');
        $cincin = $this->titip('barang-pasien', 'Cincin emas kuning');

        $this->serahTerima->hand([
            'patient_name' => 'Budi Santoso', 'patient_id' => 77,
            'kind' => 'barang-pasien', 'description' => 'Dompet kulit cokelat berisi KTP',
            'condition' => 'Utuh, isi sesuai catatan penitipan',
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id, $dompet);

        $sisa = $this->serahTerima->stillHeld(77);

        // Tabel satu arah tidak pernah bisa menjawab pertanyaan ini.
        $this->assertCount(1, $sisa);
        $this->assertSame($cincin->id, $sisa->first()->id);
    }

    #[Test]
    public function satu_titipan_tidak_bisa_diserahkan_dua_kali(): void
    {
        $dompet = $this->titip('barang-pasien', 'Dompet');

        $this->serahTerima->hand([
            'patient_name' => 'Budi Santoso', 'kind' => 'barang-pasien',
            'description' => 'Dompet', 'condition' => 'Utuh',
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id, $dompet);

        // Tanpa aturan ini, dua penyerahan bisa menunjuk titipan yang sama
        // dan sisa titipan jadi negatif.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/sudah diserahkan lewat/');

        $this->serahTerima->hand([
            'patient_name' => 'Budi Santoso', 'kind' => 'barang-pasien',
            'description' => 'Dompet', 'condition' => 'Utuh',
            'counterparty_name' => 'Rina', 'counterparty_relationship' => 'anak',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id, $dompet->refresh());
    }

    #[Test]
    public function jenis_penyerahan_disalin_dari_titipannya_bukan_dari_pemanggil(): void
    {
        $jaringan = $this->titip('anggota-tubuh', 'Jaringan apendiks', 'PA-2026-0001');

        $serah = $this->serahTerima->hand([
            'patient_name' => 'Budi Santoso',
            // Pemanggil menyebut jenis yang KELIRU.
            'kind' => 'barang-pasien',
            'description' => 'Jaringan apendiks', 'condition' => 'Terendam formalin, wadah utuh',
            'container_label' => 'PA-2026-0001',
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id, $jaringan);

        // Dua catatan tentang benda yang sama tidak boleh bercerita berbeda.
        $this->assertSame('anggota-tubuh', $serah->kind);
    }

    #[Test]
    public function serah_terima_anggota_tubuh_wajib_berlabel_wadah(): void
    {
        // Wadah jaringan tanpa label tidak bisa dibedakan dari wadah mana
        // pun, dan begitulah jaringan yang salah sampai ke keluarga yang
        // salah.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/label wadahnya/');

        $this->serahTerima->receive([
            'patient_name' => 'Budi Santoso', 'kind' => 'anggota-tubuh',
            'description' => 'Jaringan apendiks', 'condition' => 'Terendam formalin',
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id);
    }

    #[Test]
    public function barang_pribadi_boleh_tanpa_label_wadah(): void
    {
        // Dompet tidak tertukar dengan cara yang sama seperti wadah jaringan.
        $barang = $this->titip('barang-pasien', 'Dompet');

        $this->assertNull($barang->container_label);
    }

    #[Test]
    public function kondisi_saat_serah_terima_wajib_dicatat(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/Kondisi barang saat serah terima/');

        $this->serahTerima->receive([
            'patient_name' => 'Budi Santoso', 'kind' => 'barang-pasien',
            'description' => 'Dompet', 'condition' => '',
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id);
    }

    #[Test]
    public function basis_data_menolak_anggota_tubuh_tanpa_label_meski_service_dilewati(): void
    {
        $this->expectException(QueryException::class);

        DB::table('correspondence.property_handovers')->insert([
            'handover_number' => 'STB-UJI-LANGSUNG', 'kind' => 'anggota-tubuh',
            'direction' => 'diserahkan', 'patient_name' => 'Budi',
            'description' => 'Jaringan', 'condition' => 'Utuh', 'container_label' => null,
            'counterparty_name' => 'Siti', 'counterparty_relationship' => 'istri',
            'officer_name' => 'Ns. Uji', 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ==================================================== kewenangan

    #[Test]
    public function layar_hak_pasien_untuk_staf_ruangan_dan_dokter_bukan_petugas_tu(): void
    {
        $this->actingAs($this->petugas)->get(route('correspondence.hak-pasien.index'))->assertOk();

        // Permintaan second opinion dan cuti perawatan dijawab DPJP, jadi
        // dokter perlu gerbangnya — bukan cuma staf ruangan yang mencatat.
        $dokter = User::query()->create([
            'username' => 'uji-dokter-hpk', 'name' => 'Dokter HPK',
            'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
        $this->actingAs($dokter)->get(route('correspondence.hak-pasien.index'))->assertOk();

        /*
         * Petugas tata usaha mengurus surat-menyurat kantor. Register hak
         * pasien memuat siapa merasa terancam oleh siapa dan barang pribadi
         * siapa yang dititipkan — bukan bacaan bagi yang tidak merawat.
         */
        $tu = User::query()->create([
            'username' => 'uji-tu-hpk', 'name' => 'Petugas TU',
            'password' => 'password', 'is_active' => true,
        ]);
        $tu->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());
        $this->actingAs($tu)->get(route('correspondence.hak-pasien.index'))->assertForbidden();
    }

    // ============================================== pernyataan pasien

    #[Test]
    public function pernyataan_memilih_dpjp_menyimpan_pilihan_bukan_penugasan(): void
    {
        $pernyataan = $this->consents->issue([
            'consent_type' => 'memilih-dpjp', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Memilih dr. Andi sebagai DPJP',
            'decision' => 'setuju',
            'chosen_practitioner_id' => 42, 'chosen_practitioner_name' => 'dr. Andi',
            'signer_name' => 'Budi Santoso', 'signer_relationship' => 'diri-sendiri',
            'witness_name' => 'Siti',
        ], $this->petugas->id);

        $this->assertSame('dr. Andi', $pernyataan->chosen_practitioner_name);

        /*
         * Penugasan DPJP tercatat di inpatient.dpjp_history dan itu keputusan
         * rumah sakit; yang di sini adalah pilihan pasien. Keduanya bisa
         * berbeda — dokter yang diminta bisa saja tidak tersedia — dan
         * menyamakannya membuat riwayat penugasan menyatakan sesuatu yang
         * tidak pernah terjadi.
         */
        $this->assertSame(0, DB::table('inpatient.dpjp_history')->count());
    }

    #[Test]
    public function dokter_pilihan_tidak_boleh_menempel_pada_jenis_lain(): void
    {
        // Persetujuan tindakan yang menyimpan "dokter pilihan" akan terbaca
        // sebagai penunjukan operator — keputusan yang sama sekali lain.
        $this->expectException(QueryException::class);

        $this->consents->issue([
            'consent_type' => 'tindakan', 'patient_name' => 'Budi',
            'procedure_description' => 'Apendektomi', 'decision' => 'setuju',
            'chosen_practitioner_id' => 42, 'chosen_practitioner_name' => 'dr. Andi',
        ], $this->petugas->id);
    }

    #[Test]
    public function pernyataan_pasien_umum_memakai_blok_penanda_tangan_yang_sama(): void
    {
        $pernyataan = $this->consents->issue([
            'consent_type' => 'pernyataan-pasien-umum', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Menyanggupi seluruh biaya perawatan sebagai pasien umum',
            'decision' => 'setuju',
            'signer_name' => 'Siti Aminah', 'signer_relationship' => 'istri',
            'signer_id_number' => '3271010101800001',
            'delegation_reason' => 'Pasien dirawat dan tidak dapat menandatangani',
        ], $this->petugas->id);

        $this->assertSame('pernyataan-pasien-umum', $pernyataan->consent_type);
        $this->assertFalse($pernyataan->ditandatanganiSendiri());
    }

    // -------------------------------------------------------- fixture

    private function minta(string $jenis, string $detail): PatientRequest
    {
        return $this->permintaan->request([
            'request_type' => $jenis,
            'patient_name' => 'Budi Santoso',
            'requester_name' => 'Budi Santoso',
            'requester_relationship' => 'diri-sendiri',
            'detail' => $detail,
        ], $this->petugas->id);
    }

    private function titip(string $jenis, string $uraian, ?string $label = null): PropertyHandover
    {
        return $this->serahTerima->receive([
            'patient_name' => 'Budi Santoso', 'patient_id' => 77,
            'kind' => $jenis, 'description' => $uraian,
            'condition' => 'Utuh saat diterima',
            'container_label' => $label,
            'counterparty_name' => 'Budi Santoso', 'counterparty_relationship' => 'diri-sendiri',
            'officer_name' => 'Ns. Uji',
        ], $this->petugas->id);
    }
}
