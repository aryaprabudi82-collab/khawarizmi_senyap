<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Pharmacy\Services\StockLedger;
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

class PrescriptionTest extends TestCase
{
    use RefreshDatabase;

    private PrescriptionService $resep;
    private StockLedger $stok;
    private StockLocation $depo;
    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
            PharmacySeeder::class,
        ]);

        $this->resep = app(PrescriptionService::class);
        $this->stok = app(StockLedger::class);
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();
        $this->apoteker = $this->buatPengguna('super-admin');
    }

    // ------------------------------------------------------------ buku besar

    #[Test]
    public function stok_masuk_mencatat_saldo_dan_baris_buku_besar(): void
    {
        $obat = $this->obat('OBT-002');
        $sebelum = $this->stok->availableQuantity($obat->id, $this->depo->id);

        $batch = $this->stok->receive($obat->id, $this->depo->id, 'BARU-01', 100, now()->addYear()->toDateString());

        $this->assertSame('100.00', $batch->quantity_on_hand);
        $this->assertSame($sebelum + 100, $this->stok->availableQuantity($obat->id, $this->depo->id));
        $this->assertTrue($this->stok->reconcile($batch->id)['cocok']);
    }

    #[Test]
    public function pengambilan_mengikuti_fefo_bukan_urutan_masuk(): void
    {
        $obat = $this->obat('OBT-002');

        // Seeder membuat B2026A (kedaluwarsa 8 bulan) dan B2026B (20 bulan).
        $dekat = StockBatch::query()->where('drug_id', $obat->id)->where('batch_number', 'B2026A')->firstOrFail();
        $jauh = StockBatch::query()->where('drug_id', $obat->id)->where('batch_number', 'B2026B')->firstOrFail();

        $terambil = $this->stok->issue($obat->id, $this->depo->id, 50, 'uji');

        $this->assertCount(1, $terambil);
        $this->assertSame($dekat->id, $terambil[0]['batch']->id, 'Batch terdekat kedaluwarsa harus keluar lebih dulu.');
        $this->assertSame('250.00', $dekat->refresh()->quantity_on_hand);
        $this->assertSame('500.00', $jauh->refresh()->quantity_on_hand);
    }

    #[Test]
    public function pengambilan_terbagi_ke_beberapa_batch_bila_perlu(): void
    {
        $obat = $this->obat('OBT-002');

        // Tersedia 300 + 500. Minta 400 sehingga harus lintas batch.
        $terambil = $this->stok->issue($obat->id, $this->depo->id, 400, 'uji');

        $this->assertCount(2, $terambil);
        $this->assertSame(300.0, $terambil[0]['quantity']);
        $this->assertSame(100.0, $terambil[1]['quantity']);
        $this->assertSame(400.0, $this->stok->availableQuantity($obat->id, $this->depo->id));
    }

    #[Test]
    public function pengambilan_melebihi_stok_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $obat = $this->obat('OBT-002');
        $sebelum = $this->stok->availableQuantity($obat->id, $this->depo->id);

        try {
            $this->stok->issue($obat->id, $this->depo->id, $sebelum + 1, 'uji');
            $this->fail('Pengambilan melebihi stok seharusnya ditolak.');
        } catch (PharmacyException $e) {
            $this->assertStringContainsString('Stok tidak cukup', $e->getMessage());
        }

        $this->assertSame($sebelum, $this->stok->availableQuantity($obat->id, $this->depo->id));
    }

    #[Test]
    public function batch_kedaluwarsa_tidak_ikut_dihitung_sebagai_stok_tersedia(): void
    {
        $obat = $this->obat('OBT-005');
        $sebelum = $this->stok->availableQuantity($obat->id, $this->depo->id);

        $this->stok->receive($obat->id, $this->depo->id, 'KADALUARSA', 999, now()->subDay()->toDateString());

        $this->assertSame($sebelum, $this->stok->availableQuantity($obat->id, $this->depo->id));
    }

    #[Test]
    public function basis_data_menolak_saldo_stok_negatif(): void
    {
        $batch = StockBatch::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::statement('UPDATE pharmacy.stock_batches SET quantity_on_hand = -1 WHERE id = ?', [$batch->id]);
    }

    #[Test]
    public function saldo_selalu_bisa_direkonsiliasi_terhadap_buku_besar(): void
    {
        $obat = $this->obat('OBT-003');
        $this->stok->issue($obat->id, $this->depo->id, 120, 'uji');

        foreach (StockBatch::query()->where('drug_id', $obat->id)->get() as $batch) {
            $hasil = $this->stok->reconcile($batch->id);
            $this->assertTrue($hasil['cocok'], "Batch {$batch->batch_number} tidak cocok: " . json_encode($hasil));
        }
    }

    // ---------------------------------------------------------------- alur resep

    #[Test]
    public function resep_ditulis_dari_kunjungan_dan_mewarisi_konteksnya(): void
    {
        $registrasi = $this->daftarkan('Hendra Saputra');
        $resep = $this->resep->create($registrasi->id);

        $this->assertSame($registrasi->patient_id, $resep->patient_id);
        $this->assertSame($registrasi->registration_number, $resep->registration_number);
        $this->assertSame(Prescription::STATUS_DITULIS, $resep->status);
        $this->assertStringStartsWith('R' . now()->format('Ymd'), $resep->prescription_number);
        $this->assertSame(Prescription::KIND_RAWAT_JALAN, $resep->kind);
    }

    #[Test]
    public function resep_pulang_terpisah_dari_resep_rawat_jalan_pada_kunjungan_yang_sama(): void
    {
        $registrasi = $this->daftarkan('Hendra Saputra');

        $ralan = $this->resep->create($registrasi->id, null, Prescription::KIND_RAWAT_JALAN);
        $pulang = $this->resep->create($registrasi->id, null, Prescription::KIND_PULANG);

        $this->assertNotSame($ralan->id, $pulang->id);
        $this->assertSame(Prescription::KIND_PULANG, $pulang->kind);

        // Membuat lagi dengan kind yang sama pada resep yang masih berjalan
        // mengembalikan yang sudah ada, bukan menggandakan.
        $pulangLagi = $this->resep->create($registrasi->id, null, Prescription::KIND_PULANG);
        $this->assertSame($pulang->id, $pulangLagi->id);
    }

    #[Test]
    public function resep_kosong_tidak_bisa_dikirim_ke_apoteker(): void
    {
        $this->expectException(PharmacyException::class);
        $this->expectExceptionMessage('kosong');

        $this->resep->submit($this->resepBaru());
    }

    #[Test]
    public function total_resep_dihitung_ulang_saat_item_berubah(): void
    {
        $resep = $this->resepBaru();

        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1 sesudah makan');
        $this->assertSame('5000.00', $resep->refresh()->total_amount);

        $item = $this->resep->addItem($resep, $this->obat('OBT-003')->id, 30, '1x1 pagi');
        $this->assertSame('41000.00', $resep->refresh()->total_amount);

        $this->resep->removeItem($item);
        $this->assertSame('5000.00', $resep->refresh()->total_amount);
    }

    #[Test]
    public function obat_yang_sama_tidak_bisa_ditulis_dua_kali_dalam_satu_resep(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');

        $this->expectException(PharmacyException::class);
        $this->expectExceptionMessage('sudah ada di resep ini');

        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 5, '2x1');
    }

    #[Test]
    public function resep_tidak_bisa_lompat_dari_ditulis_langsung_ke_diserahkan(): void
    {
        // Telaah apoteker adalah syarat, bukan pelengkap.
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');

        $this->expectException(PharmacyException::class);
        $this->expectExceptionMessage('setelah disetujui apoteker');

        $this->resep->dispense($resep->refresh(), $this->depo->id, $this->apoteker);
    }

    #[Test]
    public function stok_baru_berkurang_saat_diserahkan_bukan_saat_ditulis(): void
    {
        $obat = $this->obat('OBT-002');
        $sebelum = $this->stok->availableQuantity($obat->id, $this->depo->id);

        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $obat->id, 30, '3x1');
        $this->resep->submit($resep->refresh());

        $this->assertSame($sebelum, $this->stok->availableQuantity($obat->id, $this->depo->id));

        $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);
        $this->resep->dispense($resep->refresh(), $this->depo->id, $this->apoteker);

        $this->assertSame($sebelum - 30, $this->stok->availableQuantity($obat->id, $this->depo->id));
    }

    #[Test]
    public function penyerahan_gagal_tidak_memotong_satu_item_pun(): void
    {
        $cukup = $this->obat('OBT-002');
        $kurang = $this->obat('OBT-006');

        $stokCukup = $this->stok->availableQuantity($cukup->id, $this->depo->id);
        $stokKurang = $this->stok->availableQuantity($kurang->id, $this->depo->id);

        $resep = $this->resepDisetujui([
            [$cukup->id, 10],
            [$kurang->id, $stokKurang + 100],
        ]);

        try {
            $this->resep->dispense($resep, $this->depo->id, $this->apoteker);
            $this->fail('Penyerahan dengan stok kurang seharusnya gagal.');
        } catch (PharmacyException $e) {
            $this->assertStringContainsString('Stok tidak cukup', $e->getMessage());
        }

        // Item pertama tidak boleh terlanjur terpotong.
        $this->assertSame($stokCukup, $this->stok->availableQuantity($cukup->id, $this->depo->id));
        $this->assertSame($stokKurang, $this->stok->availableQuantity($kurang->id, $this->depo->id));
        $this->assertSame(Prescription::STATUS_DISETUJUI, $resep->refresh()->status);
    }

    #[Test]
    public function resep_yang_sudah_diserahkan_tidak_bisa_dibatalkan(): void
    {
        $resep = $this->resepDisetujui([[$this->obat('OBT-002')->id, 5]]);
        $this->resep->dispense($resep, $this->depo->id, $this->apoteker);

        $this->expectException(PharmacyException::class);
        $this->expectExceptionMessage('Gunakan retur obat');

        $this->resep->cancel($resep->refresh(), 'salah pasien');
    }

    #[Test]
    public function obat_yang_sudah_diserahkan_terbaca_lewat_view_terbitan_untuk_billing(): void
    {
        $resep = $this->resepDisetujui([[$this->obat('OBT-002')->id, 20]]);
        $this->resep->dispense($resep, $this->depo->id, $this->apoteker);

        $baris = DB::table('pharmacy.v_prescription_charge')
            ->where('prescription_id', $resep->id)
            ->first();

        $this->assertNotNull($baris);
        $this->assertSame('20.00', $baris->dispensed_quantity);
        $this->assertSame('10000.00', $baris->amount);
    }

    // ----------------------------------------------------- penyaringan alergi

    #[Test]
    public function resep_disaring_terhadap_alergi_pasien_yang_tercatat(): void
    {
        // Inilah alasan clinical menerbitkan v_patient_allergy.
        $registrasi = $this->daftarkan('Dewi Anggraini');

        $klinis = app(ClinicalRecordService::class);
        $klinis->recordAllergy($registrasi->patient_id, 'Amoksisilin', [
            'severity' => 'berat', 'reaction' => 'Sesak napas dan ruam',
        ]);

        $resep = $this->resep->create($registrasi->id);
        $this->resep->addItem($resep, $this->obat('OBT-001')->id, 15, '3x1');
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');

        $temuan = $this->resep->screen($resep->refresh());

        $this->assertCount(1, $temuan, 'Hanya amoksisilin yang boleh memicu peringatan.');
        $this->assertSame('Amoksisilin', $temuan[0]['substance']);
        $this->assertSame('berat', $temuan[0]['severity']);
        $this->assertSame('Amoksisilin 500 mg', $temuan[0]['drug_name']);
    }

    #[Test]
    public function pasien_tanpa_alergi_tidak_memicu_peringatan(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-001')->id, 15, '3x1');

        $this->assertSame([], $this->resep->screen($resep->refresh()));
    }

    #[Test]
    public function persetujuan_atas_peringatan_alergi_berat_wajib_disertai_catatan(): void
    {
        $registrasi = $this->daftarkan('Fajar Nugroho');
        app(ClinicalRecordService::class)->recordAllergy($registrasi->patient_id, 'Amoksisilin', ['severity' => 'berat']);

        $resep = $this->resep->create($registrasi->id);
        $this->resep->addItem($resep, $this->obat('OBT-001')->id, 15, '3x1');
        $this->resep->submit($resep->refresh());

        try {
            $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);
            $this->fail('Persetujuan tanpa catatan seharusnya ditolak.');
        } catch (PharmacyException $e) {
            $this->assertStringContainsString('wajib disertai catatan', $e->getMessage());
        }

        // Dengan catatan, apoteker tetap boleh menyetujui secara sadar.
        $disetujui = $this->resep->review(
            $resep->refresh(), 'disetujui', 'Pasien pernah toleransi dosis ini, dipantau ketat.', $this->apoteker
        );

        $this->assertSame(Prescription::STATUS_DISETUJUI, $disetujui->status);
    }

    #[Test]
    public function temuan_telaah_diarsipkan_sehingga_dasar_keputusan_tidak_ikut_berubah(): void
    {
        $registrasi = $this->daftarkan('Lestari Wulandari');
        $klinis = app(ClinicalRecordService::class);
        $klinis->recordAllergy($registrasi->patient_id, 'Ibuprofen', ['severity' => 'sedang']);

        $resep = $this->resep->create($registrasi->id);
        $this->resep->addItem($resep, $this->obat('OBT-007')->id, 10, '3x1');
        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'ditolak', 'Ganti dengan parasetamol.', $this->apoteker);

        // Alergi kemudian dinyatakan tidak aktif.
        DB::table('clinical.allergies')
            ->where('patient_id', $registrasi->patient_id)
            ->update(['status' => 'tidak-aktif']);

        $telaah = $resep->refresh()->reviews()->first();

        $this->assertSame('ditolak', $telaah->outcome);
        $this->assertCount(1, $telaah->findings, 'Temuan saat telaah harus tetap tersimpan.');
        $this->assertSame('Ibuprofen', $telaah->findings[0]['substance']);
    }

    #[Test]
    public function resep_ditolak_bisa_diperbaiki_lalu_dikirim_ulang(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-007')->id, 10, '3x1');
        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'ditolak', 'Dosis terlalu tinggi.', $this->apoteker);

        $resep->refresh();
        $this->assertTrue($resep->isEditable(), 'Resep ditolak harus bisa diperbaiki dokter.');

        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');
        $dikirim = $this->resep->submit($resep->refresh());

        $this->assertSame(Prescription::STATUS_MENUNGGU_TELAAH, $dikirim->status);
    }

    // ------------------------------------------------------------------ bantu

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji-farmasi-' . $kodePeran,
            'name' => 'Apoteker Uji',
            'password' => 'password',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $kodePeran)->firstOrFail());

        return $user->fresh(['roles']);
    }

    private function obat(string $kode): Drug
    {
        return Drug::query()->where('code', $kode)->firstOrFail();
    }

    private function daftarkan(string $nama = 'Pasien Farmasi'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    private function resepBaru(): Prescription
    {
        return $this->resep->create($this->daftarkan()->id);
    }

    /** @param  list<array{0:int,1:float}>  $items */
    private function resepDisetujui(array $items): Prescription
    {
        $resep = $this->resepBaru();

        foreach ($items as [$drugId, $qty]) {
            $this->resep->addItem($resep, $drugId, $qty, '3x1 sesudah makan');
        }

        $this->resep->submit($resep->refresh());

        return $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);
    }
}
