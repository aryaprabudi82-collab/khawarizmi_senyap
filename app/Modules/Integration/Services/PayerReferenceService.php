<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\PayerCodeMapping;
use App\Modules\Integration\Models\PayerReference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Referensi & pemetaan kode penjamin (domain L item E) — ~60 kode.
 *
 *   refresh / references -> seluruh bpjs_referensi_*, bpjs_cek_* daftar,
 *                           referensi HFIS, apotek, dan inhealth_referensi_*
 *   map / resolve        -> mapping_poli_bpjs, bpjs_mapping_dokterdpjp,
 *                           bpjs_mapping_obat_apotek, inhealth_mapping_*
 *
 * TIGA ATURAN:
 *
 * 1. REFERENSI ADALAH SALINAN, BUKAN MASTER. Daftar poli dan dokter di
 *    sini milik penjamin; master kita tetap di konteks organization.
 *    Umur salinannya dilaporkan supaya referensi basi terlihat basi.
 *
 * 2. PENYEGARAN MENGGANTI SELURUH DAFTAR jenis itu, bukan menambahkan.
 *    Kode yang dihapus penjamin harus ikut hilang dari salinan kita —
 *    kalau cuma ditambahkan, poli yang sudah ditutup BPJS tetap bisa
 *    dipilih petugas dan klaimnya ditolak tanpa sebab yang jelas.
 *
 * 3. YANG BELUM DIPETAKAN MENGEMBALIKAN NULL. Pemanggil wajib
 *    memperlakukannya sebagai "jangan kirim", bukan "kirim tanpa kode" —
 *    aturan yang sama seperti pemetaan SATUSEHAT di item D.
 */
class PayerReferenceService
{
    /**
     * Mengganti seluruh daftar referensi satu jenis.
     *
     * @param  iterable<array{code: string, name: string, parent_code?: ?string, raw?: array}>  $rows
     *
     * @throws IntegrationException
     */
    public function refresh(string $payer, string $referenceType, iterable $rows): int
    {
        $this->assertPayer($payer);

        $baris = [];
        $now = now();

        foreach ($rows as $row) {
            $kode = trim((string) ($row['code'] ?? ''));

            if ($kode === '') {
                continue;
            }

            $baris[] = [
                'payer' => $payer,
                'reference_type' => $referenceType,
                'code' => $kode,
                'name' => (string) ($row['name'] ?? $kode),
                'parent_code' => $row['parent_code'] ?? null,
                'raw' => isset($row['raw']) ? json_encode($row['raw']) : null,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($baris === []) {
            throw new IntegrationException(
                "Daftar referensi {$referenceType} dari {$payer} kosong; penyegaran dibatalkan agar salinan lama tidak hilang."
            );
        }

        return DB::transaction(function () use ($payer, $referenceType, $baris) {
            // Ganti seluruhnya — lihat aturan 2 pada docblock.
            PayerReference::query()
                ->where('payer', $payer)
                ->where('reference_type', $referenceType)
                ->delete();

            foreach (array_chunk($baris, 500) as $potongan) {
                DB::table('integration.payer_references')->insert($potongan);
            }

            return count($baris);
        });
    }

    public function references(string $payer, string $referenceType, ?string $search = null, ?string $parentCode = null): Collection
    {
        return PayerReference::query()
            ->where('payer', $payer)
            ->where('reference_type', $referenceType)
            ->when($parentCode, fn ($q) => $q->where('parent_code', $parentCode))
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(200)
            ->get();
    }

    /**
     * Umur tiap daftar referensi.
     *
     * Referensi yang basi tampak sahih kalau umurnya tidak ditampilkan —
     * dan poli yang sudah ditutup penjamin akan terus bisa dipilih.
     */
    public function freshness(): Collection
    {
        return DB::table('integration.payer_references')
            ->groupBy('payer', 'reference_type')
            // clock_timestamp(), BUKAN now(): now() PostgreSQL mengembalikan
            // waktu MULAI TRANSAKSI, bukan waktu sekarang. Di dalam transaksi
            // panjang ia membeku, dan umur salinan jadi terhitung lebih muda
            // daripada kenyataannya — persis kesalahan yang paling tidak boleh
            // terjadi pada laporan yang gunanya menunjukkan data sudah basi.
            ->selectRaw('payer, reference_type, count(*) AS jumlah,
                         max(fetched_at) AS terakhir,
                         extract(day from clock_timestamp() - max(fetched_at))::int AS umur_hari')
            ->orderBy('payer')
            ->orderBy('reference_type')
            ->get();
    }

    // -------------------------------------------------------------- pemetaan

    /**
     * Menyiapkan baris pemetaan dari kode lokal yang ada. Idempoten.
     */
    public function seedMappings(string $payer, string $mappingType, iterable $rows): int
    {
        $this->assertPayer($payer);

        $baru = 0;

        foreach ($rows as $row) {
            $kode = trim((string) ($row['code'] ?? ''));

            if ($kode === '') {
                continue;
            }

            $ada = PayerCodeMapping::query()
                ->where('payer', $payer)
                ->where('mapping_type', $mappingType)
                ->where('local_code', $kode)
                ->exists();

            if ($ada) {
                continue;
            }

            PayerCodeMapping::query()->create([
                'payer' => $payer,
                'mapping_type' => $mappingType,
                'local_code' => $kode,
                'local_name' => $row['name'] ?? null,
                'is_active' => true,
            ]);

            $baru++;
        }

        return $baru;
    }

    /**
     * @throws IntegrationException
     */
    public function map(string $payer, string $mappingType, string $localCode, ?string $payerCode, ?string $payerName = null, ?int $actorId = null): PayerCodeMapping
    {
        $this->assertPayer($payer);

        $pemetaan = PayerCodeMapping::query()
            ->where('payer', $payer)
            ->where('mapping_type', $mappingType)
            ->where('local_code', $localCode)
            ->first()
            ?? throw new IntegrationException("Kode lokal '{$localCode}' tidak dikenal pada pemetaan {$mappingType} {$payer}.");

        $kode = $payerCode !== null ? trim($payerCode) : null;

        if ($kode === '') {
            $kode = null;
        }

        // Satu kode penjamin tidak boleh dipakai dua kode lokal: klaim
        // untuk poli A akan terkirim sebagai poli B, dan salahnya baru
        // terlihat saat klaim ditolak.
        if ($kode !== null) {
            $bentrok = PayerCodeMapping::query()
                ->where('payer', $payer)
                ->where('mapping_type', $mappingType)
                ->where('payer_code', $kode)
                ->whereKeyNot($pemetaan->id)
                ->exists();

            if ($bentrok) {
                throw new IntegrationException("Kode {$payer} '{$kode}' sudah dipakai kode lokal lain pada jenis {$mappingType}.");
            }
        }

        $pemetaan->update([
            'payer_code' => $kode,
            'payer_name' => $payerName,
            'mapped_by' => $actorId,
            'mapped_at' => $kode !== null ? now() : null,
        ]);

        return $pemetaan->refresh();
    }

    /** Kode penjamin untuk satu kode lokal, atau null kalau belum dipetakan. */
    public function resolve(string $payer, string $mappingType, string $localCode): ?string
    {
        return PayerCodeMapping::query()
            ->where('payer', $payer)
            ->where('mapping_type', $mappingType)
            ->where('local_code', $localCode)
            ->where('is_active', true)
            ->value('payer_code');
    }

    public function mappings(string $payer, ?string $mappingType = null, bool $unmappedOnly = false): Collection
    {
        return PayerCodeMapping::query()
            ->where('payer', $payer)
            ->when($mappingType, fn ($q) => $q->where('mapping_type', $mappingType))
            ->when($unmappedOnly, fn ($q) => $q->whereNull('payer_code'))
            ->orderBy('mapping_type')
            ->orderBy('local_code')
            ->get();
    }

    /** Berapa kode yang belum dipetakan — menentukan berapa banyak klaim yang akan tertahan. */
    public function readiness(): Collection
    {
        return DB::table('integration.payer_code_mappings')
            ->groupBy('payer', 'mapping_type')
            ->selectRaw('payer, mapping_type,
                         count(*) AS total,
                         count(*) FILTER (WHERE payer_code IS NOT NULL) AS terpetakan,
                         count(*) FILTER (WHERE payer_code IS NULL) AS belum')
            ->orderBy('payer')
            ->orderBy('mapping_type')
            ->get();
    }

    /**
     * @throws IntegrationException
     */
    private function assertPayer(string $payer): void
    {
        if (! in_array($payer, PayerReference::SISTEM, true)) {
            throw new IntegrationException("Sistem referensi '{$payer}' tidak dikenal.");
        }
    }
}
