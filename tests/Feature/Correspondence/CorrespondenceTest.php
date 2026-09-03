<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\OutgoingLetter;
use App\Modules\Correspondence\Services\AnnouncementService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\LetterService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    private LetterService $letters;
    private AnnouncementService $announcements;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->letters = app(LetterService::class);
        $this->announcements = app(AnnouncementService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-tu', 'name' => 'Petugas TU Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());
    }

    #[Test]
    public function surat_masuk_tercatat_berformat_sm_tahun_urut_dan_berstatus_diterima(): void
    {
        $surat = $this->catatMasuk();

        $this->assertMatchesRegularExpression('/^SM-\d{4}-\d{5}$/', $surat->letter_number);
        $this->assertSame(IncomingLetter::STATUS_DITERIMA, $surat->status);
    }

    #[Test]
    public function surat_masuk_bisa_didisposisikan_lalu_diarsipkan(): void
    {
        $surat = $this->letters->disposition($this->catatMasuk(), 'Bidang Pelayanan Medis');
        $this->assertSame(IncomingLetter::STATUS_DIDISPOSISIKAN, $surat->status);
        $this->assertSame('Bidang Pelayanan Medis', $surat->forwarded_to);

        $diarsipkan = $this->letters->archiveIncoming($surat);
        $this->assertSame(IncomingLetter::STATUS_DIARSIPKAN, $diarsipkan->status);
    }

    #[Test]
    public function surat_masuk_yang_sudah_diarsipkan_tidak_bisa_diarsipkan_ulang(): void
    {
        $surat = $this->letters->archiveIncoming($this->catatMasuk());

        $this->expectException(CorrespondenceException::class);

        $this->letters->archiveIncoming($surat);
    }

    #[Test]
    public function surat_keluar_dimulai_sebagai_draf_lalu_bisa_dikirim(): void
    {
        $surat = $this->letters->draftOutgoing([
            'recipient' => 'Dinas Kesehatan', 'subject' => 'Laporan Bulanan', 'classification' => 'biasa',
        ], $this->petugas->id);

        $this->assertMatchesRegularExpression('/^SK-\d{4}-\d{5}$/', $surat->letter_number);
        $this->assertSame(OutgoingLetter::STATUS_DRAFT, $surat->status);
        $this->assertNull($surat->sent_at);

        $terkirim = $this->letters->send($surat);
        $this->assertSame(OutgoingLetter::STATUS_TERKIRIM, $terkirim->status);
        $this->assertNotNull($terkirim->sent_at);
    }

    #[Test]
    public function surat_keluar_yang_sudah_terkirim_tidak_bisa_dikirim_ulang(): void
    {
        $surat = $this->letters->send($this->letters->draftOutgoing([
            'recipient' => 'Dinas Kesehatan', 'subject' => 'Laporan', 'classification' => 'biasa',
        ], $this->petugas->id));

        $this->expectException(CorrespondenceException::class);

        $this->letters->send($surat);
    }

    #[Test]
    public function pengumuman_di_luar_rentang_tanggal_tidak_ikut_tayang(): void
    {
        $this->announcements->create([
            'title' => 'Pengumuman Lalu', 'body' => 'Sudah lewat', 'starts_at' => now()->subDays(10)->toDateString(), 'ends_at' => now()->subDays(2)->toDateString(),
        ], $this->petugas->id);
        $tayang = $this->announcements->create([
            'title' => 'Pengumuman Aktif', 'body' => 'Sedang tayang', 'starts_at' => now()->subDay()->toDateString(), 'ends_at' => null,
        ], $this->petugas->id);

        $hasil = \App\Modules\Correspondence\Models\Announcement::query()->currentlyVisible()->get();

        $this->assertCount(1, $hasil);
        $this->assertSame($tayang->id, $hasil->first()->id);
    }

    #[Test]
    public function layar_tata_usaha_hanya_untuk_petugas_tu(): void
    {
        $this->actingAs($this->petugas)->get(route('correspondence.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('correspondence.pengumuman.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-tu', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('correspondence.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function catatMasuk(): IncomingLetter
    {
        return $this->letters->recordIncoming([
            'sender' => 'Dinas Kesehatan Provinsi', 'subject' => 'Undangan Rapat Koordinasi',
            'classification' => 'biasa', 'received_at' => now()->toDateString(),
        ], $this->petugas->id);
    }
}
