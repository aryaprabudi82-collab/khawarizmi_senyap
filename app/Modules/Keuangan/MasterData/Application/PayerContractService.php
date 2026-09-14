<?php

namespace App\Modules\Keuangan\MasterData\Application;

use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak penjamin berperiode — Modul A butir 1.8.
 *
 * IDENTITAS PENJAMIN TETAP DI `catalog.payers` dan tidak dipindahkan —
 * ia dipakai pendaftaran dan billing setiap hari. Yang dikelola di sini
 * adalah KONTRAKNYA: masa berlaku, plafon, cost-sharing, pengecualian,
 * dan tenggat pengajuan.
 *
 * BERPERIODE, DAN ITU INTINYA. Tagihan yang terbit Maret dinilai dengan
 * kontrak yang berlaku Maret, bukan kontrak yang berlaku saat laporannya
 * dibuka. Tanpa itu, perpanjangan kontrak tahun ini diam-diam mengubah
 * cara tagihan tahun lalu seharusnya dihitung — dan tidak ada yang bisa
 * menjelaskan selisihnya lagi.
 */
class PayerContractService
{
    public const BASIS_TIDAK_ADA = 'tidak-ada';

    public const BASIS_PERSENTASE = 'persentase';

    public const BASIS_NOMINAL = 'nominal';

    public const BASIS_PERSENTASE_BERBATAS = 'persentase-berbatas';

    /**
     * Mendaftarkan kontrak baru.
     *
     * Kontrak berjalan sebelumnya DITUTUP otomatis sehari sebelum yang
     * baru berlaku — pola yang sama dipakai `TariffService` dan sudah
     * terbukti benar. Membiarkan keduanya terbuka berarti ada dua kontrak
     * berlaku bersamaan dan tidak ada cara menentukan mana yang dipakai.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws KeuanganException
     */
    public function daftarkan(array $data): object
    {
        $payerId = (int) $data['payer_id'];
        $berlakuDari = (string) ($data['valid_from'] ?? now()->toDateString());

        $this->pastikanPenjaminAda($payerId);
        $this->pastikanBasisLengkap($data);

        return DB::transaction(function () use ($data, $payerId, $berlakuDari) {
            $berjalan = $this->kontrakBerjalan($payerId);

            if ($berjalan !== null) {
                if ($berjalan->valid_from >= $berlakuDari) {
                    throw new KeuanganException(
                        "Kontrak berjalan mulai berlaku {$berjalan->valid_from}, sedangkan kontrak baru "
                        ."hendak berlaku {$berlakuDari}. Kontrak baru tidak boleh mundur ke belakang "
                        .'kontrak yang sudah berjalan — tagihan di antara keduanya jadi tidak punya '
                        .'kontrak yang jelas.'
                    );
                }

                DB::table('keuangan_master.payer_contracts')
                    ->where('id', $berjalan->id)
                    ->update([
                        'valid_until' => date('Y-m-d', strtotime($berlakuDari.' -1 day')),
                        'updated_at' => now(),
                    ]);
            }

            $id = DB::table('keuangan_master.payer_contracts')->insertGetId([
                'payer_id' => $payerId,
                'contract_number' => $data['contract_number'],
                'name' => $data['name'],
                'valid_from' => $berlakuDari,
                'valid_until' => $data['valid_until'] ?? null,
                'plafon_per_episode' => $data['plafon_per_episode'] ?? null,
                'plafon_per_tahun' => $data['plafon_per_tahun'] ?? null,
                'cost_sharing_basis' => $data['cost_sharing_basis'] ?? self::BASIS_TIDAK_ADA,
                'cost_sharing_percent' => $data['cost_sharing_percent'] ?? null,
                'cost_sharing_amount' => $data['cost_sharing_amount'] ?? null,
                'cost_sharing_cap' => $data['cost_sharing_cap'] ?? null,
                'batas_hari_pengajuan' => $data['batas_hari_pengajuan'] ?? null,
                'batas_hari_pembayaran' => $data['batas_hari_pembayaran'] ?? null,
                'butuh_penjaminan_awal' => $data['butuh_penjaminan_awal'] ?? false,
                'is_active' => true,
                'note' => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('keuangan_master.payer_contracts')->find($id);
        });
    }

    /**
     * Kontrak yang BERLAKU pada satu tanggal — bukan yang berlaku hari ini.
     *
     * Inilah method yang dipanggil billing saat menilai tagihan, dan
     * tanggal yang dikirimnya adalah tanggal LAYANAN, bukan tanggal
     * penagihan.
     */
    public function berlakuPada(int $payerId, string $tanggal): ?object
    {
        return DB::table('keuangan_master.payer_contracts')
            ->where('payer_id', $payerId)
            ->where('is_active', true)
            ->where('valid_from', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $tanggal))
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Menghitung bagian yang ditanggung PASIEN dari sebuah nilai tagihan.
     *
     * MENGEMBALIKAN NULL BILA KONTRAKNYA TIDAK ADA, bukan nol. Nol berarti
     * "pasien tidak menanggung apa-apa" — pernyataan yang sangat berbeda
     * dari "belum diketahui kontraknya". Menyamakan keduanya membuat
     * pasien tidak ditagih sepeser pun tanpa ada yang memutuskannya.
     *
     * @throws KeuanganException
     */
    public function bagianPasien(int $payerId, string $tanggal, Money $tagihan): ?Money
    {
        $kontrak = $this->berlakuPada($payerId, $tanggal);

        if ($kontrak === null) {
            return null;
        }

        /*
         * Dihitung berskala ALOKASI (4), baru dibulatkan ke skala tagihan
         * di ujung — keputusan KA-1. Membulatkan lebih awal membuat
         * selisih pembulatan menumpuk di setiap baris tagihan.
         */
        $dasar = $tagihan->bulatkanKe(Money::SKALA_ALOKASI);

        $bagian = match ($kontrak->cost_sharing_basis) {
            self::BASIS_TIDAK_ADA => Money::nol(Money::SKALA_ALOKASI),

            self::BASIS_NOMINAL => Money::dari((string) $kontrak->cost_sharing_amount, Money::SKALA_ALOKASI),

            self::BASIS_PERSENTASE => $this->persenDari($dasar, (string) $kontrak->cost_sharing_percent),

            self::BASIS_PERSENTASE_BERBATAS => $this->batasi(
                $this->persenDari($dasar, (string) $kontrak->cost_sharing_percent),
                Money::dari((string) $kontrak->cost_sharing_cap, Money::SKALA_ALOKASI),
            ),

            default => throw new KeuanganException(
                "Basis cost-sharing '{$kontrak->cost_sharing_basis}' tidak dikenal."
            ),
        };

        /*
         * Bagian pasien TIDAK BOLEH melebihi tagihannya sendiri. Kontrak
         * bernominal tetap Rp 50.000 atas tagihan Rp 30.000 akan membuat
         * pasien membayar lebih besar daripada layanannya — dan penjamin
         * membayar negatif.
         */
        if ($bagian->lebihDari($dasar)) {
            $bagian = $dasar;
        }

        return $bagian->sebagaiTagihan();
    }

    /**
     * Apakah sebuah item dikecualikan kontraknya.
     *
     * Diperiksa MESIN saat charge dibentuk. Kalau pengecualian cuma teks
     * bebas di kontrak, satu-satunya yang bisa memeriksanya adalah manusia
     * yang membaca kontrak — dan itu tidak terjadi pada 2.000 pasien per
     * hari.
     */
    public function dikecualikan(int $contractId, ?int $chargeItemId, ?string $golongan): ?object
    {
        return DB::table('keuangan_master.contract_exclusions')
            ->where('contract_id', $contractId)
            ->where(function ($q) use ($chargeItemId, $golongan) {
                if ($chargeItemId !== null) {
                    $q->orWhere('charge_item_id', $chargeItemId);
                }

                if ($golongan !== null) {
                    $q->orWhere('golongan', $golongan);
                }
            })
            ->first();
    }

    /** @throws KeuanganException */
    public function kecualikan(int $contractId, string $alasan, ?int $chargeItemId = null, ?string $golongan = null): void
    {
        if (($chargeItemId === null) === ($golongan === null)) {
            throw new KeuanganException(
                'Pengecualian harus menyebut SATU sasaran: item tertentu atau golongan, tidak '
                .'keduanya dan tidak kosong. Pengecualian yang tidak menunjuk apa pun tidak '
                .'mengecualikan apa pun.'
            );
        }

        DB::table('keuangan_master.contract_exclusions')->insert([
            'contract_id' => $contractId,
            'charge_item_id' => $chargeItemId,
            'golongan' => $golongan,
            'reason' => $alasan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Kontrak yang akan berakhir dalam sekian hari.
     *
     * Kontrak yang habis tanpa ada yang memperpanjang membuat seluruh
     * tagihan penjamin itu kehilangan dasar — dan itu baru ketahuan saat
     * klaimnya ditolak.
     *
     * @return Collection<int, object>
     */
    public function akanBerakhir(int $dalamHari = 30): Collection
    {
        return collect(DB::select(
            'SELECT * FROM keuangan_master.payer_contracts
              WHERE is_active = true
                AND valid_until IS NOT NULL
                AND valid_until BETWEEN CURRENT_DATE AND CURRENT_DATE + ?::int
              ORDER BY valid_until',
            [$dalamHari]
        ));
    }

    private function persenDari(Money $dasar, string $persen): Money
    {
        /*
         * Persen dihitung lewat pembagian bobot, bukan perkalian desimal.
         * `bagi()` menjamin tidak ada sen yang hilang — dan di sini yang
         * dibagi adalah antara "bagian pasien" dan "bagian penjamin",
         * sehingga keduanya SELALU berjumlah tepat sama dengan tagihannya.
         */
        $bobotPasien = (int) round((float) $persen * 100);
        $bobotPenjamin = 10000 - $bobotPasien;

        if ($bobotPasien <= 0) {
            return Money::nol($dasar->scale);
        }

        if ($bobotPenjamin <= 0) {
            return $dasar;
        }

        return $dasar->bagi(['pasien' => $bobotPasien, 'penjamin' => $bobotPenjamin])['pasien'];
    }

    private function batasi(Money $nilai, Money $batas): Money
    {
        return $nilai->lebihDari($batas) ? $batas : $nilai;
    }

    private function kontrakBerjalan(int $payerId): ?object
    {
        return DB::table('keuangan_master.payer_contracts')
            ->where('payer_id', $payerId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->first();
    }

    /** @throws KeuanganException */
    private function pastikanPenjaminAda(int $payerId): void
    {
        $ada = DB::table('catalog.v_payer_summary')->where('id', $payerId)->exists();

        if (! $ada) {
            throw new KeuanganException("Penjamin #{$payerId} tidak ditemukan atau tidak aktif.");
        }
    }

    /** @throws KeuanganException */
    private function pastikanBasisLengkap(array $data): void
    {
        $basis = $data['cost_sharing_basis'] ?? self::BASIS_TIDAK_ADA;

        $wajib = match ($basis) {
            self::BASIS_PERSENTASE => ['cost_sharing_percent'],
            self::BASIS_NOMINAL => ['cost_sharing_amount'],
            self::BASIS_PERSENTASE_BERBATAS => ['cost_sharing_percent', 'cost_sharing_cap'],
            default => [],
        };

        foreach ($wajib as $kolom) {
            if (! isset($data[$kolom]) || $data[$kolom] === null) {
                throw new KeuanganException(
                    "Basis cost-sharing '{$basis}' menuntut '{$kolom}' terisi. Tanpa itu, bagian "
                    .'pasien dihitung sebagai NOL — pasien tidak ditagih apa-apa, tidak ada galat, '
                    .'dan selisihnya baru ketahuan saat rekonsiliasi penjamin.'
                );
            }
        }
    }
}
