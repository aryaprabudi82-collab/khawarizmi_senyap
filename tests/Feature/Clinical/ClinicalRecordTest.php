<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Clinical\Models\Allergy;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\Observation;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClinicalRecordTest extends TestCase
{
    use RefreshDatabase;

    private ClinicalRecordService $records;
    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
        ]);

        $this->records = app(ClinicalRecordService::class);
        $this->dokter = $this->buatPengguna('dokter');
    }

    #[Test]
    public function asesmen_dibuka_dari_kunjungan_dan_mewarisi_konteksnya(): void
    {
        $registrasi = $this->daftarkan('Sri Wahyuni');

        $asesmen = $this->records->openAssessment($registrasi->id, Assessment::KIND_SOAP, $this->dokter);

        $this->assertSame($registrasi->patient_id, $asesmen->patient_id);
        $this->assertSame($registrasi->patient_name, $asesmen->patient_name);
        $this->assertStringStartsWith('Sri Wahyuni', $asesmen->patient_name);
        $this->assertSame($registrasi->registration_number, $asesmen->registration_number);
        $this->assertSame(Assessment::STATUS_DRAFT, $asesmen->status);
        $this->assertSame(1, $asesmen->version);
    }

    #[Test]
    public function membuka_asesmen_dua_kali_tidak_membuat_catatan_ganda(): void
    {
        $registrasi = $this->daftarkan();

        $a = $this->records->openAssessment($registrasi->id, Assessment::KIND_SOAP);
        $b = $this->records->openAssessment($registrasi->id, Assessment::KIND_SOAP);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Assessment::query()->count());
    }

    #[Test]
    public function kunjungan_yang_dibatalkan_tidak_bisa_dibuatkan_asesmen(): void
    {
        $registrasi = $this->daftarkan();
        app(RegistrationService::class)->cancel($registrasi, 'Pasien pulang sebelum dilayani.');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak ditemukan atau sudah dibatalkan');

        $this->records->openAssessment($registrasi->id, Assessment::KIND_SOAP);
    }

    #[Test]
    public function draf_disunting_di_tempat_tanpa_membuat_versi_baru(): void
    {
        $asesmen = $this->asesmen();

        $this->records->saveAssessment($asesmen, ['subjective' => 'Demam tiga hari.']);
        $this->records->saveAssessment($asesmen->refresh(), ['subjective' => 'Demam empat hari.']);

        $asesmen->refresh();

        $this->assertSame('Demam empat hari.', $asesmen->subjective);
        $this->assertSame(1, $asesmen->version);
        $this->assertSame(0, $asesmen->revisions()->count());
    }

    #[Test]
    public function asesmen_kosong_tidak_bisa_difinalkan(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('kosong');

        $this->records->finalizeAssessment($this->asesmen());
    }

    #[Test]
    public function asesmen_terisi_bisa_difinalkan_dan_terkunci(): void
    {
        $asesmen = $this->asesmen();
        $this->records->saveAssessment($asesmen, ['subjective' => 'Batuk pilek sejak dua hari.']);

        $final = $this->records->finalizeAssessment($asesmen->refresh(), $this->dokter);

        $this->assertSame(Assessment::STATUS_FINAL, $final->status);
        $this->assertNotNull($final->finalized_at);
        $this->assertSame($this->dokter->id, $final->finalized_by);
        $this->assertTrue($final->isLocked());
    }

    #[Test]
    public function catatan_final_tidak_bisa_diubah_tanpa_alasan(): void
    {
        // Inti syarat jejak audit Permenkes 24/2022.
        $asesmen = $this->asesmenFinal();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('hanya bisa diralat dengan menyertakan alasan');

        $this->records->saveAssessment($asesmen, ['subjective' => 'Diubah diam-diam.']);
    }

    #[Test]
    public function ralat_menaikkan_versi_dan_mengarsipkan_isi_lama_utuh(): void
    {
        $asesmen = $this->asesmenFinal('Keluhan versi pertama.');

        $this->records->saveAssessment(
            assessment: $asesmen,
            content: ['subjective' => 'Keluhan versi kedua.'],
            actor: $this->dokter,
            reason: 'Salah ketik pada keluhan pasien.',
        );

        $asesmen->refresh();

        $this->assertSame('Keluhan versi kedua.', $asesmen->subjective);
        $this->assertSame(2, $asesmen->version);
        $this->assertSame(Assessment::STATUS_AMENDED, $asesmen->status);

        $arsip = $asesmen->revisions()->first();

        $this->assertSame(1, $arsip->version);
        $this->assertSame('Keluhan versi pertama.', $arsip->content['subjective']);
        $this->assertSame('Salah ketik pada keluhan pasien.', $arsip->reason);
        $this->assertSame($this->dokter->id, $arsip->revised_by);
    }

    #[Test]
    public function setiap_ralat_berikutnya_menambah_arsip_baru(): void
    {
        $asesmen = $this->asesmenFinal('Versi satu.');

        foreach (['Versi dua.', 'Versi tiga.'] as $i => $isi) {
            $this->records->saveAssessment(
                $asesmen->refresh(),
                ['subjective' => $isi],
                $this->dokter,
                'Ralat ke-' . ($i + 1),
            );
        }

        $asesmen->refresh();

        $this->assertSame(3, $asesmen->version);
        $this->assertSame(2, $asesmen->revisions()->count());
        $this->assertSame([2, 1], $asesmen->revisions()->pluck('version')->all());
    }

    #[Test]
    public function tanda_vital_tercatat_berikut_penanda_di_luar_rentang(): void
    {
        $asesmen = $this->asesmen();

        $jumlah = $this->records->recordObservations($asesmen, [
            'tekanan-darah-sistolik' => 160,
            'tekanan-darah-diastolik' => 95,
            'nadi' => 82,
            'suhu' => 36.8,
            'kode-yang-tidak-dikenal' => 999,
            'berat-badan' => null,
        ], $this->dokter);

        $this->assertSame(4, $jumlah, 'Kode tak dikenal dan nilai kosong harus diabaikan.');

        $observasi = $this->records->latestObservations($asesmen->registration_id);

        $this->assertTrue($observasi['tekanan-darah-sistolik']->is_abnormal);
        $this->assertTrue($observasi['tekanan-darah-diastolik']->is_abnormal);
        $this->assertFalse($observasi['nadi']->is_abnormal);
        $this->assertFalse($observasi['suhu']->is_abnormal);
        $this->assertSame('mmHg', $observasi['tekanan-darah-sistolik']->unit);
    }

    #[Test]
    public function pengukuran_menambah_bukan_menimpa_sehingga_tren_terjaga(): void
    {
        $asesmen = $this->asesmen();

        $this->records->recordObservations($asesmen, ['nadi' => 88]);
        $this->records->recordObservations($asesmen, ['nadi' => 76]);

        $semua = Observation::query()
            ->where('registration_id', $asesmen->registration_id)
            ->where('code', 'nadi')
            ->get();

        $this->assertCount(2, $semua, 'Nilai lama harus tetap tersimpan sebagai riwayat.');
        $this->assertSame('76.00', $this->records->latestObservations($asesmen->registration_id)['nadi']->value_numeric);
    }

    #[Test]
    public function diagnosis_dicatat_dengan_teks_yang_disalin_dari_kamus(): void
    {
        $asesmen = $this->asesmen();

        $d = $this->records->addDiagnosis($asesmen, 'J06.9', 'ISPA akut', Diagnosis::RANK_UTAMA, 'kerja');

        $this->assertSame('J06.9', $d->code);
        $this->assertSame('ISPA akut', $d->display);
        $this->assertSame($asesmen->registration_id, $d->registration_id);
    }

    #[Test]
    public function diagnosis_yang_sama_tidak_dicatat_dua_kali(): void
    {
        $asesmen = $this->asesmen();
        $this->records->addDiagnosis($asesmen, 'I10', 'Hipertensi esensial');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah tercatat');

        $this->records->addDiagnosis($asesmen, 'I10', 'Hipertensi esensial');
    }

    #[Test]
    public function satu_kunjungan_hanya_boleh_punya_satu_diagnosis_utama(): void
    {
        $asesmen = $this->asesmen();
        $this->records->addDiagnosis($asesmen, 'I10', 'Hipertensi', Diagnosis::RANK_UTAMA);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah punya diagnosis utama');

        $this->records->addDiagnosis($asesmen, 'E11.9', 'Diabetes tipe 2', Diagnosis::RANK_UTAMA);
    }

    #[Test]
    public function basis_data_menolak_diagnosis_utama_ganda_walau_kode_lolos(): void
    {
        $asesmen = $this->asesmen();
        $this->records->addDiagnosis($asesmen, 'I10', 'Hipertensi', Diagnosis::RANK_UTAMA);

        $this->expectException(QueryException::class);

        Diagnosis::query()->create([
            'registration_id' => $asesmen->registration_id,
            'patient_id' => $asesmen->patient_id,
            'registration_number' => $asesmen->registration_number,
            'code' => 'K30',
            'display' => 'Dispepsia',
            'rank' => Diagnosis::RANK_UTAMA,
            'certainty' => 'kerja',
            'diagnosed_at' => now(),
        ]);
    }

    #[Test]
    public function alergi_melekat_pada_pasien_dan_terbawa_ke_kunjungan_berikutnya(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Nurul Hidayah', 'sex' => 'P', 'birth_date' => '1985-03-12',
        ]);

        $pertama = $this->daftarkanPasien($pasien->id, 'POL-UMUM');
        $asesmen = $this->records->openAssessment($pertama->id, Assessment::KIND_SOAP);

        $this->records->recordAllergy($pasien->id, 'Amoksisilin', [
            'category' => 'obat', 'severity' => 'berat', 'reaction' => 'Ruam dan sesak',
        ], $pertama->id, $this->dokter);

        // Kunjungan lain, hari yang sama, poli berbeda.
        $kedua = $this->daftarkanPasien($pasien->id, 'POL-PD');
        $asesmenKedua = $this->records->openAssessment($kedua->id, Assessment::KIND_SOAP);

        $alergi = $this->records->allergiesFor($asesmenKedua->patient_id);

        $this->assertCount(1, $alergi);
        $this->assertSame('Amoksisilin', $alergi->first()->substance);
        $this->assertSame('berat', $alergi->first()->severity);
    }

    #[Test]
    public function alergi_yang_sama_tidak_dicatat_dua_kali(): void
    {
        $asesmen = $this->asesmen();
        $this->records->recordAllergy($asesmen->patient_id, 'Penisilin');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah tercatat');

        // Beda kapitalisasi pun tetap dianggap sama.
        $this->records->recordAllergy($asesmen->patient_id, 'penisilin');
    }

    #[Test]
    public function alergi_aktif_terbaca_lewat_view_terbitan_untuk_konteks_farmasi(): void
    {
        $asesmen = $this->asesmen();
        $this->records->recordAllergy($asesmen->patient_id, 'Ibuprofen', ['severity' => 'sedang']);

        $baris = \Illuminate\Support\Facades\DB::table('clinical.v_patient_allergy')
            ->where('patient_id', $asesmen->patient_id)
            ->get();

        $this->assertCount(1, $baris);
        $this->assertSame('Ibuprofen', $baris->first()->substance);
    }

    #[Test]
    public function diagnosis_terbaca_lewat_view_terbitan_untuk_konteks_billing(): void
    {
        $asesmen = $this->asesmen();
        $this->records->addDiagnosis($asesmen, 'J45.9', 'Asma', Diagnosis::RANK_UTAMA, 'definitif');

        $baris = \Illuminate\Support\Facades\DB::table('clinical.v_encounter_diagnosis')
            ->where('registration_id', $asesmen->registration_id)
            ->first();

        $this->assertSame('J45.9', $baris->code);
        $this->assertSame('utama', $baris->rank);
        $this->assertSame('definitif', $baris->certainty);
    }

    #[Test]
    public function skrining_awal_tercatat_untuk_satu_kunjungan(): void
    {
        $registrasi = $this->daftarkan();

        $skrining = $this->records->recordScreening($registrasi->id, [
            'fall_risk_level' => 'sedang',
            'pain_score' => 3,
            'nutrition_at_risk' => false,
            'infectious_symptom' => false,
            'special_needs' => null,
        ], $this->dokter);

        $this->assertSame('sedang', $skrining->fall_risk_level);
        $this->assertSame($registrasi->patient_name, $skrining->patient_name);
        $this->assertSame($this->dokter->id, $skrining->screened_by);
    }

    #[Test]
    public function kunjungan_yang_sama_tidak_bisa_diskrining_dua_kali(): void
    {
        $registrasi = $this->daftarkan();
        $data = ['fall_risk_level' => 'rendah', 'pain_score' => 0, 'nutrition_at_risk' => false, 'infectious_symptom' => false];

        $this->records->recordScreening($registrasi->id, $data, $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->records->recordScreening($registrasi->id, $data, $this->dokter);
    }

    #[Test]
    public function hasflags_menandai_skrining_dengan_temuan_berisiko(): void
    {
        $registrasi = $this->daftarkan();

        $aman = $this->records->recordScreening($registrasi->id, [
            'fall_risk_level' => 'rendah', 'pain_score' => 1, 'nutrition_at_risk' => false, 'infectious_symptom' => false,
        ], $this->dokter);
        $this->assertFalse($aman->hasFlags());

        $registrasiLain = $this->daftarkanPasien(app(PatientRegistry::class)->register(['name' => 'Pasien Risiko', 'sex' => 'P', 'birth_date' => '1990-01-01'])->id, 'POL-UMUM');
        $berisiko = $this->records->recordScreening($registrasiLain->id, [
            'fall_risk_level' => 'tinggi', 'pain_score' => 1, 'nutrition_at_risk' => false, 'infectious_symptom' => false,
        ], $this->dokter);
        $this->assertTrue($berisiko->hasFlags());
    }

    #[Test]
    public function skrining_bisa_dicatat_lewat_http_dan_tampil_di_layar_pemeriksaan(): void
    {
        $registrasi = $this->daftarkan();

        $this->actingAs($this->dokter)
            ->post(route('rme.skrining.simpan', $registrasi->id), [
                'fall_risk_level' => 'tinggi',
                'pain_score' => 7,
                'nutrition_at_risk' => '1',
                'infectious_symptom' => '0',
                'special_needs' => 'Membutuhkan penerjemah bahasa isyarat',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('clinical.screenings', [
            'registration_id' => $registrasi->id, 'fall_risk_level' => 'tinggi', 'pain_score' => 7, 'nutrition_at_risk' => true,
        ]);

        $this->actingAs($this->dokter)
            ->get(route('rme.edit', $registrasi->id))
            ->assertOk()
            ->assertSee('tinggi')
            ->assertSee('Membutuhkan penerjemah bahasa isyarat');
    }

    #[Test]
    public function tindakan_tercatat_dengan_tarif_yang_disalin_dari_katalog(): void
    {
        $registrasi = $this->daftarkan();

        $tindakan = $this->records->recordProcedure($registrasi->id, 'TDK-GANTI-VERBAN', 2, 'Luka operasi.', $this->dokter);

        $this->assertSame('35000.00', $tindakan->unit_price);
        $this->assertSame('70000.00', $tindakan->amount);
        $this->assertSame('Ganti Verban', $tindakan->service_name);
        $this->assertSame($registrasi->patient_name, $tindakan->patient_name);
    }

    #[Test]
    public function tindakan_dengan_kode_layanan_yang_tidak_ada_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->records->recordProcedure($registrasi->id, 'TDK-TIDAK-ADA', 1, null, $this->dokter);
    }

    #[Test]
    public function tindakan_tanpa_tarif_untuk_penjamin_kunjungan_ditolak(): void
    {
        // Tarif tindakan contoh cuma diisi untuk Umum — kunjungan BPJS
        // harus ditolak dengan pesan jelas, bukan diam-diam bertarif 0.
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien BPJS', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'BPJS')->value('id'),
        );

        $this->expectException(ClinicalException::class);
        $this->records->recordProcedure($registrasi->id, 'TDK-GANTI-VERBAN', 1, null, $this->dokter);
    }

    #[Test]
    public function tindakan_bisa_dicatat_lewat_http_dan_tampil_di_layar_pemeriksaan(): void
    {
        $registrasi = $this->daftarkan();

        $this->actingAs($this->dokter)
            ->post(route('rme.tindakan.simpan', $registrasi->id), [
                'service_code' => 'TDK-NEBULIZER', 'quantity' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('clinical.procedures', [
            'registration_id' => $registrasi->id, 'service_code' => 'TDK-NEBULIZER', 'amount' => 50000,
        ]);

        $this->actingAs($this->dokter)
            ->get(route('rme.edit', $registrasi->id))
            ->assertOk()
            ->assertSee('Nebulizer');
    }

    #[Test]
    public function operasi_tercatat_dengan_tarif_lump_sum_dari_katalog(): void
    {
        $registrasi = $this->daftarkan();

        $operasi = $this->records->recordOperation(
            $registrasi->id, 'OPR-APENDEKTOMI', 'dr. Bedah Uji', 'umum', 'OK 1', 'Apendisitis akut.', $this->dokter
        );

        $this->assertSame('5000000.00', $operasi->amount);
        $this->assertSame('Apendektomi', $operasi->service_name);
        $this->assertSame('dr. Bedah Uji', $operasi->surgeon_name);
        $this->assertSame('umum', $operasi->anesthesia_type);
        $this->assertSame($registrasi->patient_name, $operasi->patient_name);
    }

    #[Test]
    public function operasi_dengan_kode_layanan_yang_tidak_ada_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->records->recordOperation($registrasi->id, 'OPR-TIDAK-ADA', 'dr. Bedah Uji', null, null, null, $this->dokter);
    }

    #[Test]
    public function operasi_tanpa_tarif_untuk_penjamin_kunjungan_ditolak(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien Operasi BPJS', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'BPJS')->value('id'),
        );

        $this->expectException(ClinicalException::class);
        $this->records->recordOperation($registrasi->id, 'OPR-APENDEKTOMI', 'dr. Bedah Uji', null, null, null, $this->dokter);
    }

    #[Test]
    public function operasi_bisa_dicatat_lewat_http_dan_tampil_di_layar_pemeriksaan(): void
    {
        $registrasi = $this->daftarkan();

        $this->actingAs($this->dokter)
            ->post(route('rme.operasi.simpan', $registrasi->id), [
                'service_code' => 'OPR-HERNIOTOMI', 'surgeon_name' => 'dr. Bedah Uji', 'anesthesia_type' => 'regional',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('clinical.operations', [
            'registration_id' => $registrasi->id, 'service_code' => 'OPR-HERNIOTOMI', 'amount' => 4500000,
        ]);

        $this->actingAs($this->dokter)
            ->get(route('rme.edit', $registrasi->id))
            ->assertOk()
            ->assertSee('Herniotomi');
    }

    #[Test]
    public function perawat_tidak_bisa_mencatat_operasi(): void
    {
        $registrasi = $this->daftarkan();
        $perawat = $this->buatPengguna('perawat');

        $this->actingAs($perawat)
            ->post(route('rme.operasi.simpan', $registrasi->id), [
                'service_code' => 'OPR-KATARAK', 'surgeon_name' => 'dr. Bedah Uji',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function diagnosis_dihapus_secara_lunak_bukan_dihapus_keras(): void
    {
        $asesmen = $this->asesmen();
        $d = $this->records->addDiagnosis($asesmen, 'R51', 'Nyeri kepala');

        $d->delete();

        $this->assertSoftDeleted('clinical.diagnoses', ['id' => $d->id]);
        $this->assertSame(1, Diagnosis::withTrashed()->count());
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

    private function daftarkan(string $nama = 'Pasien Uji'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut,
            'sex' => 'L',
            'birth_date' => '1990-01-01',
        ]);

        return $this->daftarkanPasien($pasien->id, 'POL-UMUM');
    }

    private function daftarkanPasien(int $patientId, string $kodeUnit): Registration
    {
        return app(RegistrationService::class)->register(
            patientId: $patientId,
            unitId: Unit::query()->where('code', $kodeUnit)->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    private function asesmen(): Assessment
    {
        return $this->records->openAssessment($this->daftarkan()->id, Assessment::KIND_SOAP, $this->dokter);
    }

    private function asesmenFinal(string $keluhan = 'Keluhan awal.'): Assessment
    {
        $asesmen = $this->asesmen();
        $this->records->saveAssessment($asesmen, ['subjective' => $keluhan]);

        return $this->records->finalizeAssessment($asesmen->refresh(), $this->dokter);
    }
}
