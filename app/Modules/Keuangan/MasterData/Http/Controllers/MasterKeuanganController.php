<?php

namespace App\Modules\Keuangan\MasterData\Http\Controllers;

use App\Modules\Keuangan\MasterData\Application\ChargeItemLinker;
use App\Modules\Keuangan\MasterData\Application\ChargeMasterService;
use App\Modules\Keuangan\MasterData\Application\CostCenterService;
use App\Modules\Keuangan\MasterData\Application\PayerContractService;
use App\Modules\Keuangan\MasterData\Application\TariffSourceRegistry;
use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Services\MasterDataReadiness;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Layar master keuangan — Modul A.
 *
 * MENGAPA LAYAR INI HARUS ADA, dan mengapa ketiadaannya adalah cacat.
 *
 * Seluruh Modul A dibangun tanpa satu pun layar, dan saya sempat
 * mencatatnya sebagai pertanyaan terbuka seolah itu keputusan yang
 * menunggu. Bukan. Tanpa layar, tidak ada seorang pun di RSP UI yang bisa
 * memeriksa apakah kode yang saya tulis benar — dan pemeriksaan itulah
 * satu-satunya cara kesalahan saya tertangkap sebelum uang mengalir
 * lewatnya.
 *
 * LAYAR INI TIDAK MENGHITUNG APA PUN SENDIRI. Seluruh angkanya diminta
 * dari layanan yang sudah ada dan sudah diuji. Menghitung ulang di sini
 * akan melahirkan angka kedua yang bisa berbeda dari sumbernya — dan dua
 * angka berbeda untuk hal yang sama jauh lebih buruk daripada satu angka
 * yang harus dicari.
 *
 * DIGERBANGI `pendapatan_per_akun`, dipegang Petugas Keuangan dan
 * Manajemen RS. Pemetaan item ke akun COA adalah keputusan akuntansi,
 * jadi ia memang milik keuangan — bukan milik administrator sistem yang
 * tidak tahu akun mana yang menampung pendapatan tindakan.
 */
class MasterKeuanganController
{
    public function __construct(
        private readonly ChargeMasterService $cdm,
        private readonly TariffSourceRegistry $registry,
        private readonly ChargeItemLinker $penaut,
        private readonly PayerContractService $kontrak,
        private readonly CostCenterService $pusatBiaya,
        private readonly MasterDataReadiness $kesiapan,
    ) {}

    public function index(Request $request): View
    {
        $tanggal = $request->query('tanggal', now()->toDateString());

        return view('keuangan_master::index', [
            'tanggal' => $tanggal,
            'kesiapan' => $this->kesiapan->readinessItems(),
            'ringkasan' => $this->penaut->ringkasan(),
            'cakupan' => $this->registry->cakupan(),
            'belumTertaut' => $this->registry->belumTertaut(),
            'cakupanPemetaan' => $this->cdm->cakupanPemetaan(),
        ]);
    }

    // ------------------------------------------------------------------ CDM

    public function chargeMaster(Request $request): View
    {
        $tanggal = $request->query('tanggal', now()->toDateString());
        $golongan = $request->query('golongan') ?: null;

        return view('keuangan_master::charge-master', [
            'tanggal' => $tanggal,
            'golongan' => $golongan,
            'item' => $this->cdm->berlakuPada($tanggal, $golongan),
            'belumDipetakan' => $this->cdm->belumDipetakan(),
            'cakupanPemetaan' => $this->cdm->cakupanPemetaan(),
            'golonganTersedia' => $this->golonganTersedia(),
            'akun' => $this->akunBisaDijurnal(),
        ]);
    }

    /**
     * Menautkan sumber tarif yang belum punya kode CDM.
     *
     * TIDAK MENGAKTIFKAN APA PUN, dan pembatasan itu disengaja. Menautkan
     * berarti "barang ini punya kode global"; mengaktifkan berarti "boleh
     * ditagihkan dan akan masuk buku besar". Yang kedua menuntut pemetaan
     * akun, dan itu keputusan akuntansi — bukan akibat menekan tombol.
     */
    public function tautkan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'konteks' => ['nullable', 'string', 'max:20'],
        ], [], ['konteks' => 'konteks sumber']);

        $konteks = $data['konteks'] ?: null;

        if ($konteks !== null && ! in_array($konteks, $this->registry->konteksTerdaftar(), true)) {
            return back()->with('gagal', "Konteks sumber '{$konteks}' tidak dikenal.");
        }

        $hasil = $this->penaut->sapu($konteks);

        if ($hasil['ditaut'] === 0 && $hasil['dilewati'] === 0) {
            return back()->with('info', 'Tidak ada sumber tarif yang belum tertaut.');
        }

        $pesan = "{$hasil['ditaut']} item ditautkan dan berstatus NONAKTIF — "
            .'masing-masing menunggu pemetaan akun sebelum bisa ditagihkan.';

        if ($hasil['dilewati'] > 0) {
            $pesan .= " {$hasil['dilewati']} dilewati: ".implode(' | ', array_slice($hasil['galat'], 0, 3));
        }

        return back()->with('sukses', $pesan);
    }

    public function petakanAkun(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate([
            'revenue_account_id' => ['required', 'integer'],
            'cogs_account_id' => ['nullable', 'integer'],
            'discount_account_id' => ['nullable', 'integer'],
        ], [], [
            'revenue_account_id' => 'akun pendapatan',
            'cogs_account_id' => 'akun beban pokok',
            'discount_account_id' => 'akun potongan',
        ]);

        return $this->jalankan(function () use ($item, $data) {
            $this->cdm->petakanAkun(
                $this->item($item),
                (int) $data['revenue_account_id'],
                isset($data['cogs_account_id']) ? (int) $data['cogs_account_id'] : null,
                isset($data['discount_account_id']) ? (int) $data['discount_account_id'] : null,
            );

            return 'Pemetaan akun disimpan. Item masih NONAKTIF sampai diaktifkan.';
        });
    }

    public function aktifkan(int $item): RedirectResponse
    {
        return $this->jalankan(function () use ($item) {
            $cdm = $this->item($item);

            /*
             * Alasannya DIMINTA LEBIH DULU dari domain, bukan dibiarkan
             * gagal jadi galat basis data. CHECK di basis data akan
             * menolaknya dengan benar, tapi pesannya tidak menolong
             * siapa pun yang sedang mengisi formulir.
             */
            if (! $cdm->siapDiaktifkan()) {
                throw new KeuanganException($cdm->alasanBelumSiap());
            }

            $this->cdm->aktifkan($cdm);

            return "Item {$cdm->code} aktif dan boleh ditagihkan.";
        });
    }

    public function expire(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate([
            'berlaku_sampai' => ['required', 'date'],
        ], [], ['berlaku_sampai' => 'berlaku sampai']);

        return $this->jalankan(function () use ($item, $data) {
            $cdm = $this->item($item);
            $this->cdm->expire($cdm, $data['berlaku_sampai']);

            return "Item {$cdm->code} di-expire per {$data['berlaku_sampai']}. "
                .'Kodenya TIDAK dihapus — tagihan lama tetap menunjuk barang yang benar.';
        });
    }

    // ------------------------------------------------------- kontrak penjamin

    public function kontrakPenjamin(Request $request): View
    {
        $tanggal = $request->query('tanggal', now()->toDateString());

        return view('keuangan_master::kontrak-penjamin', [
            'tanggal' => $tanggal,
            'kontrak' => $this->kontrakBerlaku($tanggal),
            'akanBerakhir' => $this->kontrak->akanBerakhir(30),
            'penjamin' => DB::table('catalog.v_payer_summary')->orderBy('name')->get(),
        ]);
    }

    public function simpanKontrak(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payer_id' => ['required', 'integer'],
            'contract_number' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'valid_from' => ['required', 'date'],
            'cost_sharing_basis' => ['required', 'in:tidak-ada,persentase,nominal,persentase-berbatas'],
            'cost_sharing_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_sharing_amount' => ['nullable', 'numeric', 'min:0'],
            'cost_sharing_cap' => ['nullable', 'numeric', 'min:0'],
            'plafon_per_episode' => ['nullable', 'numeric', 'min:0'],
            'plafon_per_tahun' => ['nullable', 'numeric', 'min:0'],
            'batas_hari_pengajuan' => ['nullable', 'integer', 'min:1', 'max:365'],
            'batas_hari_pembayaran' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [], [
            'payer_id' => 'penjamin',
            'contract_number' => 'nomor kontrak',
            'name' => 'nama kontrak',
            'valid_from' => 'berlaku dari',
            'cost_sharing_basis' => 'basis cost-sharing',
            'cost_sharing_percent' => 'persentase',
            'cost_sharing_amount' => 'nominal',
            'cost_sharing_cap' => 'batas atas',
            'plafon_per_episode' => 'plafon per episode',
            'plafon_per_tahun' => 'plafon per tahun',
            'batas_hari_pengajuan' => 'tenggat pengajuan (hari)',
            'batas_hari_pembayaran' => 'tenggat pembayaran (hari)',
        ]);

        return $this->jalankan(function () use ($data) {
            $kontrak = $this->kontrak->daftarkan($data);

            return "Kontrak {$kontrak->contract_number} berlaku sejak {$kontrak->valid_from}. "
                .'Kontrak yang berjalan sebelumnya otomatis ditutup sehari sebelumnya.';
        });
    }

    // ----------------------------------------------------------- pusat biaya

    public function pusatBiaya(Request $request): View
    {
        $tanggal = $request->query('tanggal', now()->toDateString());

        return view('keuangan_master::pusat-biaya', [
            'tanggal' => $tanggal,
            'pusat' => $this->pusatBiaya->berlakuPada($tanggal),
            'dialokasikan' => $this->pusatBiaya->yangDialokasikan($tanggal),
            'belumTerklasifikasi' => $this->pusatBiaya->unitBelumTerklasifikasi(),
            'jenis' => [
                CostCenterService::REVENUE => 'Pusat pendapatan — penerima alokasi, dilarang punya cost driver',
                CostCenterService::COST => 'Pusat biaya — melayani unit lain (laundry, gizi, IPSRS)',
                CostCenterService::SUPPORT => 'Pusat pendukung — manajemen & administrasi',
                CostCenterService::PROGRAM => 'Pusat program — pendidikan & penelitian (PTN-BH)',
            ],
            'unit' => DB::table('organization.v_unit_summary')->orderBy('name')->get(),
        ]);
    }

    public function simpanPusatBiaya(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'jenis' => ['required', 'in:revenue-center,cost-center,support-center,program-center'],
            'cost_driver' => ['nullable', 'string', 'max:30'],
            'default_program' => ['nullable', 'in:pelayanan,pendidikan,penelitian'],
            'unit_id' => ['nullable', 'integer'],
            'valid_from' => ['required', 'date'],
        ], [], [
            'code' => 'kode', 'name' => 'nama', 'jenis' => 'jenis pusat',
            'cost_driver' => 'cost driver', 'default_program' => 'program',
            'unit_id' => 'unit', 'valid_from' => 'berlaku dari',
        ]);

        return $this->jalankan(function () use ($data) {
            $pusat = $this->pusatBiaya->daftarkan($data);

            return "Pusat {$pusat->code} terdaftar sebagai {$pusat->jenis}.";
        });
    }

    // -------------------------------------------------------------- pembantu

    /**
     * Menjalankan satu tindakan, mengubah KeuanganException jadi pesan
     * yang bisa dibaca.
     *
     * Aturan domain di modul ini sengaja ditulis sebagai kalimat yang
     * menjelaskan AKIBATNYA — "pusat pendapatan tidak boleh punya cost
     * driver karena alokasi yang berputar tidak pernah selesai dihitung".
     * Menelannya jadi "terjadi kesalahan" membuang seluruh gunanya.
     */
    private function jalankan(callable $kerja): RedirectResponse
    {
        try {
            return back()->with('sukses', $kerja());
        } catch (KeuanganException $e) {
            return back()->with('gagal', $e->getMessage())->withInput();
        }
    }

    private function item(int $id): ChargeItem
    {
        return ChargeItem::query()->findOrFail($id);
    }

    /** @return array<string, string> */
    private function golonganTersedia(): array
    {
        return [
            ChargeItem::GOL_TINDAKAN => 'Tindakan',
            ChargeItem::GOL_PENUNJANG => 'Penunjang',
            ChargeItem::GOL_OBAT => 'Obat',
            ChargeItem::GOL_BHP => 'BHP',
            ChargeItem::GOL_ALKES => 'Alkes',
            ChargeItem::GOL_AKOMODASI => 'Akomodasi',
            ChargeItem::GOL_VISITE => 'Visite',
            ChargeItem::GOL_ADMINISTRASI => 'Administrasi',
            ChargeItem::GOL_PAKET => 'Paket',
            ChargeItem::GOL_LAIN => 'Lain',
        ];
    }

    /**
     * Akun yang BOLEH dijurnal — akun ikhtisar sengaja tidak ikut.
     *
     * Akun induk yang menjumlahkan anaknya tidak boleh menerima jurnal:
     * saldonya lalu terhitung dua kali, sekali dari jurnalnya sendiri dan
     * sekali dari penjumlahan anaknya, dan neraca tetap seimbang sehingga
     * tidak ada yang terlihat salah.
     */
    private function akunBisaDijurnal(): \Illuminate\Support\Collection
    {
        return collect(DB::select(
            'SELECT id, code, name, type, klasifikasi
               FROM finance.v_account
              WHERE is_active = true AND is_postable = true
              ORDER BY code'
        ));
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function kontrakBerlaku(string $tanggal): \Illuminate\Support\Collection
    {
        return collect(DB::select(
            'SELECT k.*, p.name AS payer_name, p.code AS payer_code
               FROM keuangan_master.payer_contracts k
               JOIN catalog.v_payer_summary p ON p.id = k.payer_id
              WHERE k.valid_from <= ?::date
                AND (k.valid_until IS NULL OR k.valid_until >= ?::date)
              ORDER BY p.name',
            [$tanggal, $tanggal]
        ));
    }
}
