<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Services\CatalogException;
use App\Modules\Catalog\Services\FormTemplateService;
use App\Modules\Clinical\Services\FormResponseService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengesahan template & instrumen baku (domain M item F).
 *
 * Yang paling perlu dikunci:
 *
 * 1. TIDAK ADA YANG DISAHKAN OLEH PENGISIAN DATA AWAL — termasuk instrumen
 *    baku. Instrumennya sahih; yang belum terjadi adalah RSP UI
 *    mengadopsinya.
 * 2. TEMPLATE BELUM DISAHKAN TETAP BISA DIPAKAI, tapi statusnya DIBEKUKAN
 *    pada jawabannya — supaya kelak bisa dijawab rekam medis mana yang
 *    dibuat memakai formulir yang belum disahkan.
 * 3. REVISI MENGEMBALIKAN STATUS KE BELUM DISAHKAN.
 */
class FormApprovalTest extends TestCase
{
    use RefreshDatabase;

    private FormTemplateService $templates;
    private FormResponseService $formulir;
    private User $komite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->templates = app(FormTemplateService::class);
        $this->formulir = app(FormResponseService::class);

        $this->komite = User::query()->create([
            'username' => 'uji-komite', 'name' => 'dr. Ketua Komite Medik',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------- instrumen baku

    #[Test]
    public function instrumen_baku_tersedia_setelah_penyemaian(): void
    {
        $kode = [
            'risiko-jatuh-dewasa', 'risiko-jatuh-anak', 'risiko-dekubitus',
            'skor-aldrete', 'skor-bromage', 'skor-steward', 'ews-dewasa',
        ];

        foreach ($kode as $k) {
            $this->assertNotNull($this->templates->active($k), "Template {$k} harus tersedia.");
        }
    }

    /**
     * ATURAN PERTAMA, dan ini yang paling menentukan.
     */
    #[Test]
    public function seluruh_instrumen_baku_masuk_belum_disahkan(): void
    {
        $belum = $this->templates->pendingApproval()->pluck('code');

        $this->assertContains('risiko-jatuh-dewasa', $belum);
        $this->assertContains('skor-aldrete', $belum);
        $this->assertSame(0, FormTemplate::query()->where('is_approved', true)->count());
    }

    #[Test]
    public function tiap_instrumen_baku_menyebut_rujukannya(): void
    {
        $morse = $this->templates->active('risiko-jatuh-dewasa');

        $this->assertStringContainsString('Morse Fall Scale', $morse->note);
        $this->assertStringContainsString('ditinjau komite medik', $morse->note);
    }

    /** Morse: skor 45 ke atas berisiko tinggi. */
    #[Test]
    public function morse_menilai_risiko_jatuh_sesuai_ambangnya(): void
    {
        $f = $this->formulir->open($this->daftarkan()->id, 'risiko-jatuh-dewasa');

        $terisi = $this->formulir->save($f, [
            'riwayat-jatuh' => 'ya',
            'diagnosis-sekunder' => 'ya',
            'alat-bantu' => 'tidak-ada',
            'infus' => 'ya',
            'gaya-berjalan' => 'normal',
            'status-mental' => 'sadar-batas',
        ]);

        $this->assertSame(60, $terisi->score);
        $this->assertSame('tinggi', $terisi->risk_level);
    }

    /** Braden: skor makin RENDAH berarti risiko makin tinggi. */
    #[Test]
    public function braden_menilai_terbalik_dari_morse(): void
    {
        $f = $this->formulir->open($this->daftarkan()->id, 'risiko-dekubitus');

        $terisi = $this->formulir->save($f, [
            'persepsi-sensori' => '1', 'kelembapan' => '1', 'aktivitas' => '1',
            'mobilitas' => '1', 'nutrisi' => '1', 'gesekan' => '1',
        ]);

        $this->assertSame(6, $terisi->score);
        $this->assertSame('sangat-tinggi', $terisi->risk_level);
    }

    /** Bromage adalah SATU skala, bukan penjumlahan beberapa butir. */
    #[Test]
    public function bromage_dinilai_sebagai_satu_skala(): void
    {
        $f = $this->formulir->open($this->daftarkan()->id, 'skor-bromage');

        $terisi = $this->formulir->save($f, ['blokade' => '0']);

        $this->assertSame(0, $terisi->score);
        $this->assertSame('layak', $terisi->risk_level);
    }

    #[Test]
    public function aldrete_menahan_pemindahan_saat_skor_kurang(): void
    {
        $f = $this->formulir->open($this->daftarkan()->id, 'skor-aldrete');

        $terisi = $this->formulir->save($f, [
            'aktivitas' => '1', 'respirasi' => '1', 'sirkulasi' => '1',
            'kesadaran' => '1', 'saturasi' => '1',
        ]);

        $this->assertSame(5, $terisi->score);
        $this->assertSame('belum-layak', $terisi->risk_level);
    }

    /** EWS diisi berulang — instrumen pemantauan, bukan asesmen sekali isi. */
    #[Test]
    public function ews_baku_bersifat_berulang(): void
    {
        $registrasi = $this->daftarkan();

        $pertama = $this->formulir->open($registrasi->id, 'ews-dewasa');
        $kedua = $this->formulir->open($registrasi->id, 'ews-dewasa');

        $this->assertNotSame($pertama->id, $kedua->id);
    }

    // ----------------------------------------------------------- pengesahan

    #[Test]
    public function pengesahan_mencatat_nomor_keputusan_dan_penyetujunya(): void
    {
        $hasil = $this->templates->approve(
            'risiko-jatuh-dewasa',
            'SK Direktur RSP UI No. 123/2027 tentang Instrumen Pengkajian Risiko Jatuh',
            $this->komite->id,
            $this->komite->name,
        );

        $this->assertTrue($hasil->is_approved);
        $this->assertNotNull($hasil->approved_at);
        $this->assertSame('dr. Ketua Komite Medik', $hasil->approved_by_name);
        $this->assertStringContainsString('SK Direktur', $hasil->approval_note);
    }

    /** Pengesahan yang tidak bisa ditelusuri sama saja dengan tidak ada. */
    #[Test]
    public function pengesahan_tanpa_nomor_keputusan_ditolak(): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('tidak bisa ditelusuri');

        $this->templates->approve('risiko-jatuh-dewasa', '   ');
    }

    #[Test]
    public function template_yang_sudah_disahkan_tidak_disahkan_dua_kali(): void
    {
        $this->templates->approve('skor-aldrete', 'SK 001/2027');

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('sudah disahkan');

        $this->templates->approve('skor-aldrete', 'SK 002/2027');
    }

    /**
     * ATURAN KETIGA: versi baru adalah pertanyaan baru.
     */
    #[Test]
    public function revisi_mengembalikan_status_ke_belum_disahkan(): void
    {
        $this->templates->approve('skor-steward', 'SK 003/2027', $this->komite->id, $this->komite->name);

        $this->assertTrue($this->templates->active('skor-steward')->is_approved);

        $v2 = $this->templates->revise('skor-steward', [
            'sections' => [['title' => 'Revisi', 'questions' => [
                ['key' => 'kesadaran', 'label' => 'Kesadaran', 'type' => 'boolean'],
            ]]],
        ]);

        $this->assertSame(2, $v2->version);
        $this->assertFalse($v2->is_approved, 'Pengesahan versi lama tidak boleh terbawa ke versi baru.');
        $this->assertContains('skor-steward', $this->templates->pendingApproval()->pluck('code'));
    }

    // ------------------------------------------------------------ pembekuan

    /**
     * ATURAN KEDUA: belum disahkan tetap boleh dipakai — menghalanginya
     * memindahkan pencatatan ke kertas.
     */
    #[Test]
    public function template_belum_disahkan_tetap_bisa_diisi(): void
    {
        $f = $this->formulir->open($this->daftarkan()->id, 'risiko-jatuh-dewasa');

        $this->assertFalse($f->template_approved);
        $this->assertNotNull($f->id);
    }

    /**
     * Pengesahan hari ini tidak boleh membuat jawaban kemarin tampak
     * seolah dibuat dengan formulir yang sudah sah.
     */
    #[Test]
    public function pengesahan_tidak_mengubah_jawaban_yang_sudah_ada(): void
    {
        $sebelum = $this->formulir->open($this->daftarkan()->id, 'risiko-jatuh-dewasa');

        $this->templates->approve('risiko-jatuh-dewasa', 'SK 004/2027');

        $this->assertFalse($sebelum->refresh()->template_approved);

        $sesudah = $this->formulir->open($this->daftarkan()->id, 'risiko-jatuh-dewasa');

        $this->assertTrue($sesudah->template_approved);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Instrumen ' . $urut, 'sex' => 'P', 'birth_date' => '1955-05-05',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
