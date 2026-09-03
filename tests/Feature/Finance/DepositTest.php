<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use RefreshDatabase;

    private DepositService $deposits;
    private User $petugasKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        $this->deposits = app(DepositService::class);

        $this->petugasKeuangan = User::query()->create([
            'username' => 'uji-deposit', 'name' => 'Petugas Keuangan Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasKeuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    #[Test]
    public function deposit_diterima_tercatat_dan_bernomor_prefix_dep(): void
    {
        $registrasi = $this->daftarkan();

        $deposit = $this->deposits->receive($registrasi->id, 500000, 'Uang muka ranap', $this->petugasKeuangan);

        $this->assertStringStartsWith('DEP' . now()->format('Ymd'), $deposit->deposit_number);
        $this->assertSame('500000.00', $deposit->amount);
        $this->assertSame(Deposit::STATUS_AKTIF, $deposit->status);
        $this->assertSame($registrasi->patient_id, $deposit->patient_id);
        $this->assertSame($this->petugasKeuangan->id, $deposit->deposited_by);
    }

    #[Test]
    public function deposit_menjurnal_kas_debit_dan_titipan_kredit_seimbang(): void
    {
        $registrasi = $this->daftarkan();
        $deposit = $this->deposits->receive($registrasi->id, 500000, null, $this->petugasKeuangan);

        $entry = JournalEntry::query()->where('reference_type', 'deposit-diterima')->where('reference_id', $deposit->id)->firstOrFail();
        $baris = $entry->lines()->with('account')->get();

        $this->assertCount(2, $baris);
        $this->assertEqualsWithDelta(500000.0, (float) $baris->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(500000.0, (float) $baris->sum('credit'), 0.001);
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'kas' && (float) $b->debit === 500000.0));
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'utang' && (float) $b->credit === 500000.0));
    }

    #[Test]
    public function jumlah_deposit_nol_atau_negatif_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('lebih dari nol');

        $this->deposits->receive($registrasi->id, 0, null, $this->petugasKeuangan);
    }

    #[Test]
    public function kunjungan_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->deposits->receive(999999, 100000, null, $this->petugasKeuangan);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Deposit ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
