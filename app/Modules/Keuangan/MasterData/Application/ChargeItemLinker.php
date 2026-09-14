<?php

namespace App\Modules\Keuangan\MasterData\Application;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use Illuminate\Support\Facades\DB;

/**
 * Penaut tabel tarif lama ke CDM — gate Wave 1.
 *
 * MENYAPU, BUKAN MEMINDAHKAN. Tidak satu baris tarif pun disalin, diubah,
 * atau dihapus. Yang dibuat adalah KODE CDM yang menunjuk ke barisnya.
 * Aturan Konsolidasi melarang duplikasi master, dan menyalin tarif ke CDM
 * akan menciptakan sumber kebenaran kedua yang diam-diam menyimpang begitu
 * tarif aslinya diperbarui.
 *
 * ITEM LAHIR NONAKTIF, DAN ITU INTI PENAUTAN INI. Menautkan berarti
 * "barang ini sekarang punya kode global"; mengaktifkan berarti "barang
 * ini boleh ditagihkan dan akan masuk buku besar". Yang kedua tidak boleh
 * terjadi otomatis: ia menuntut pemetaan akun, dan pemetaan akun adalah
 * keputusan akuntansi, bukan hasil sapuan.
 *
 * Maka setelah penautan, seluruh item berstatus NONAKTIF dan `cakupan()`
 * akan melaporkan berapa banyak yang menunggu dipetakan. Itu bukan
 * pekerjaan yang belum selesai — itu daftar keputusan yang memang harus
 * diambil manusia.
 *
 * KODE CDM DIBERI AWALAN KONTEKS. Kode layanan `TDK-EKG` dan kode obat
 * bisa saja sama; tanpa awalan, sapuan kedua akan menabrak kode yang sudah
 * dipakai sapuan pertama dan penautannya berhenti di tengah dengan
 * sebagian sumber tertaut dan sebagian tidak.
 */
class ChargeItemLinker
{
    /**
     * Awalan kode CDM per GOLONGAN, dengan konteks sebagai cadangan.
     *
     * MENGAPA GOLONGAN, BUKAN KONTEKS. Konteks `pharmacy` menaungi obat,
     * BHP, dan alkes sekaligus; awalan per konteks membuat kasa steril
     * berkode `OBT-BHP-001`, yang terbaca seolah ia obat. Kode dipakai
     * orang untuk menyebut barang — di kuitansi, di rekonsiliasi, di
     * pertanyaan pasien — dan kode yang berbohong tentang jenis barangnya
     * akan menyesatkan tiap kali dibaca.
     */
    private const AWALAN_GOLONGAN = [
        ChargeItem::GOL_TINDAKAN => 'TDK',
        ChargeItem::GOL_PENUNJANG => 'PNJ',
        ChargeItem::GOL_OBAT => 'OBT',
        ChargeItem::GOL_BHP => 'BHP',
        ChargeItem::GOL_ALKES => 'ALK',
        ChargeItem::GOL_AKOMODASI => 'AKM',
        ChargeItem::GOL_VISITE => 'VST',
        ChargeItem::GOL_ADMINISTRASI => 'ADM',
        ChargeItem::GOL_PAKET => 'PKT',
    ];

    /** Cadangan untuk golongan `lain`, yang tidak menyebut jenis apa pun. */
    private const AWALAN_KONTEKS = [
        'catalog' => 'SVC',
        'pharmacy' => 'FRM',
        'inpatient' => 'RNP',
        'retail' => 'RTL',
        'parking' => 'PRK',
    ];

    public function __construct(
        private readonly TariffSourceRegistry $registry,
        private readonly ChargeMasterService $cdm,
    ) {}

    /**
     * Menautkan seluruh sumber yang belum punya item CDM.
     *
     * @param  string|null  $hanyaKonteks  batasi ke satu konteks; null berarti semua
     * @return array{ditaut: int, dilewati: int, per_konteks: array<string, int>, galat: array<int, string>}
     */
    public function sapu(?string $hanyaKonteks = null, ?string $berlakuDari = null): array
    {
        $berlakuDari ??= now()->toDateString();

        $ditaut = 0;
        $dilewati = 0;
        $perKonteks = [];
        $galat = [];

        foreach ($this->registry->belumTertaut() as $konteks => $baris) {
            if ($hanyaKonteks !== null && $konteks !== $hanyaKonteks) {
                continue;
            }

            $perKonteks[$konteks] = 0;

            foreach ($baris as $b) {
                $kode = $this->kodeCdm($konteks, $b['golongan'], $b['code']);

                /*
                 * Kode yang sudah ada TIDAK ditimpa dan TIDAK dilewati
                 * diam-diam — ia dilaporkan. Kode CDM yang bentrok berarti
                 * dua baris sumber berbeda menghasilkan kode yang sama, dan
                 * membiarkannya berarti salah satunya tidak akan pernah
                 * bisa ditagihkan tanpa ada yang tahu mana.
                 */
                if (ChargeItem::query()->where('code', $kode)->exists()) {
                    $dilewati++;
                    $galat[] = "Kode '{$kode}' sudah dipakai — sumber {$konteks}#{$b['source_id']} "
                        .'tidak tertaut. Periksa apakah ada dua baris sumber berkode sama.';

                    continue;
                }

                try {
                    $this->cdm->daftarkan([
                        'code' => $kode,
                        'name' => $b['name'],
                        'golongan' => $b['golongan'],
                        'source_context' => $konteks,
                        'source_id' => $b['source_id'],
                        'valid_from' => $berlakuDari,
                    ]);

                    $ditaut++;
                    $perKonteks[$konteks]++;
                } catch (KeuanganException $e) {
                    $dilewati++;
                    $galat[] = "{$konteks}#{$b['source_id']}: ".$e->getMessage();
                }
            }
        }

        return [
            'ditaut' => $ditaut,
            'dilewati' => $dilewati,
            'per_konteks' => array_filter($perKonteks),
            'galat' => $galat,
        ];
    }

    /**
     * Berapa item tertaut yang MASIH menunggu pemetaan akun.
     *
     * Angka ini, bukan jumlah item tertaut, yang menjawab "apakah
     * pendapatan sudah bisa dijurnalkan". Item tertaut tanpa akun tetap
     * tidak bisa diaktifkan — dan itu memang seharusnya.
     *
     * @return array{total:int, belum_dipetakan:int, aktif:int}
     */
    public function ringkasan(): array
    {
        $baris = DB::selectOne("
            SELECT
                COUNT(*)                                                     AS total,
                COUNT(*) FILTER (WHERE revenue_account_id IS NULL)           AS belum_dipetakan,
                COUNT(*) FILTER (WHERE is_active = true)                     AS aktif
              FROM keuangan_master.charge_items
             WHERE valid_until IS NULL
        ");

        return [
            'total' => (int) $baris->total,
            'belum_dipetakan' => (int) $baris->belum_dipetakan,
            'aktif' => (int) $baris->aktif,
        ];
    }

    private function kodeCdm(string $konteks, string $golongan, string $kodeSumber): string
    {
        $awalan = self::AWALAN_GOLONGAN[$golongan]
            ?? self::AWALAN_KONTEKS[$konteks]
            ?? strtoupper(substr($konteks, 0, 3));

        return strtoupper($awalan.'-'.$kodeSumber);
    }
}
