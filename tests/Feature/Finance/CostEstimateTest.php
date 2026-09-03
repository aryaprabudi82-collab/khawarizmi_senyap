<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Services\CostEstimateService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Services\RoomService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CostEstimateTest extends TestCase
{
    use RefreshDatabase;

    private CostEstimateService $estimates;
    private RegistrationService $registrations;
    private RoomService $rooms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        $this->estimates = app(CostEstimateService::class);
        $this->registrations = app(RegistrationService::class);
        $this->rooms = app(RoomService::class);

        $this->rooms->createRoom(['room_number' => '101', 'room_class' => 'kelas-1', 'daily_rate' => 250000]);
        $this->rooms->createRoom(['room_number' => '102', 'room_class' => 'kelas-1', 'daily_rate' => 250000]);
        $this->rooms->createRoom(['room_number' => 'V1', 'room_class' => 'vip', 'daily_rate' => 800000]);
    }

    #[Test]
    public function estimasi_dihitung_dari_tarif_kamar_rata_rata_dikali_lama_rawat(): void
    {
        $registrasi = $this->daftarkanRanap();

        $estimasi = $this->estimates->create($registrasi->id, 'kelas-1', 3, 150000, 'Perkiraan awal');

        $this->assertStringStartsWith('EST' . now()->format('Ymd'), $estimasi->estimate_number);
        $this->assertSame('250000.00', $estimasi->daily_rate);
        $this->assertSame('150000.00', $estimasi->other_charges);
        // 3 x 250000 + 150000 = 900000
        $this->assertSame('900000.00', $estimasi->total_estimate);
    }

    #[Test]
    public function estimasi_hanya_untuk_kunjungan_rawat_inap(): void
    {
        $registrasi = $this->daftarkanRalan();

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('Rawat Inap');

        $this->estimates->create($registrasi->id, 'kelas-1', 3);
    }

    #[Test]
    public function kelas_kamar_tanpa_kamar_aktif_ditolak(): void
    {
        $registrasi = $this->daftarkanRanap();

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('kelas isolasi');

        $this->estimates->create($registrasi->id, 'isolasi', 2);
    }

    #[Test]
    public function lama_rawat_nol_ditolak(): void
    {
        $registrasi = $this->daftarkanRanap();

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('lebih dari nol hari');

        $this->estimates->create($registrasi->id, 'kelas-1', 0);
    }

    #[Test]
    public function tarif_kamar_disalin_saat_estimasi_dibuat_bukan_dirujuk_ulang(): void
    {
        $registrasi = $this->daftarkanRanap();
        $estimasi = $this->estimates->create($registrasi->id, 'vip', 2);
        $this->assertSame('800000.00', $estimasi->daily_rate);

        // Tarif kamar berubah kemudian; estimasi yang sudah dibuat tidak ikut berubah.
        \App\Modules\Inpatient\Models\Room::query()->where('room_class', 'vip')->update(['daily_rate' => 1200000]);

        $this->assertSame('800000.00', $estimasi->refresh()->daily_rate);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkanRanap(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Estimasi Ranap ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: ['care_type' => 'ranap'],
        );
    }

    private function daftarkanRalan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Estimasi Ralan ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
