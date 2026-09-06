<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pemetaan kode lokal ke kode standar SATUSEHAT (domain L item D) — 12 kode.
 *
 * SATU LAYANAN untuk dua belas jenis pemetaan yang di Khanza jadi dua
 * belas menu: departemen, lokasi, vaksin, obat, keenam tindakan, tarif
 * kamar, radiologi, dan lab. Pekerjaannya sama semua — mencocokkan kode
 * kita dengan kode standar.
 *
 * DUA ATURAN YANG MENJAGA DATA KLINIS TIDAK SALAH TERKIRIM:
 *
 * 1. KODE DAN SISTEMNYA SELALU SEPASANG. SATUSEHAT memakai SNOMED CT
 *    untuk tindakan, LOINC untuk pemeriksaan lab dan radiologi, KFA untuk
 *    obat dan vaksin. Kode tanpa sistemnya akan dikirim sebagai sistem
 *    yang salah dan ditolak dengan pesan yang sulit ditelusuri.
 *
 * 2. YANG BELUM DIPETAKAN TIDAK DIKIRIM, DAN JUMLAHNYA DILAPORKAN. Menebak
 *    kode SNOMED/LOINC/KFA berarti mengirim data klinis pasien ke platform
 *    nasional dengan kode yang salah — dan data itu ikut terbaca fasilitas
 *    kesehatan lain yang merawat pasien yang sama nanti. Salah kode di
 *    laporan internal bisa diperbaiki; salah kode yang sudah tersebar
 *    nasional jauh lebih sulit ditarik kembali.
 */
class CodeMappingService
{
    /** Sistem kode yang lazim dipakai tiap jenis — dipakai memandu, bukan memaksa. */
    public const SISTEM_LAZIM = [
        'obat' => 'kfa',
        'vaksin' => 'kfa',
        'lab' => 'loinc',
        'radiologi' => 'loinc',
        'tindakan-ralan' => 'snomed',
        'tindakan-ranap' => 'snomed',
        'tindakan-radiologi' => 'snomed',
        'tindakan-lab' => 'snomed',
        'tindakan-operasi' => 'snomed',
        'departemen' => 'snomed',
        'lokasi' => 'internal',
        'tarif-kamar' => 'internal',
    ];

    /**
     * Menyiapkan baris pemetaan dari kode-kode yang sudah ada di sistem.
     *
     * Idempoten: yang sudah dipetakan tidak ditimpa, yang baru ditambahkan
     * kosong. Dijalankan lagi setiap ada kode layanan atau obat baru.
     */
    public function seedFrom(string $mappingType, iterable $rows): int
    {
        $this->assertType($mappingType);

        $baru = 0;

        foreach ($rows as $row) {
            $kode = (string) ($row['code'] ?? '');

            if ($kode === '') {
                continue;
            }

            $ada = SatusehatCodeMapping::query()
                ->where('mapping_type', $mappingType)
                ->where('local_code', $kode)
                ->exists();

            if ($ada) {
                continue;
            }

            SatusehatCodeMapping::query()->create([
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
     * Menetapkan kode standar untuk satu kode lokal.
     *
     * @throws IntegrationException
     */
    public function map(
        string $mappingType,
        string $localCode,
        ?string $codeSystem,
        ?string $standardCode,
        ?string $standardDisplay = null,
        ?int $actorId = null,
    ): SatusehatCodeMapping {
        $this->assertType($mappingType);

        $pemetaan = SatusehatCodeMapping::query()
            ->where('mapping_type', $mappingType)
            ->where('local_code', $localCode)
            ->first()
            ?? throw new IntegrationException("Kode lokal '{$localCode}' tidak dikenal pada pemetaan {$mappingType}.");

        $kode = $standardCode !== null ? trim($standardCode) : null;
        $sistem = $codeSystem !== null ? trim($codeSystem) : null;

        if ($kode === '') {
            $kode = null;
        }

        if ($sistem === '') {
            $sistem = null;
        }

        // Sepasang atau tidak sama sekali — lihat aturan 1 pada docblock.
        if (($kode === null) !== ($sistem === null)) {
            throw new IntegrationException(
                'Kode standar dan sistem kodenya harus diisi bersama: kode tanpa sistemnya akan dikirim sebagai sistem yang salah.'
            );
        }

        if ($sistem !== null && ! in_array($sistem, SatusehatCodeMapping::SISTEM, true)) {
            throw new IntegrationException("Sistem kode '{$sistem}' tidak dikenal.");
        }

        $pemetaan->update([
            'code_system' => $sistem,
            'standard_code' => $kode,
            'standard_display' => $standardDisplay,
            'mapped_by' => $actorId,
            'mapped_at' => $kode !== null ? now() : null,
        ]);

        return $pemetaan->refresh();
    }

    /**
     * Kode standar untuk satu kode lokal, atau null kalau belum dipetakan.
     *
     * Pemanggil WAJIB memperlakukan null sebagai "jangan kirim", bukan
     * sebagai "kirim tanpa kode".
     *
     * @return array{system: string, code: string, display: ?string}|null
     */
    public function resolve(string $mappingType, string $localCode): ?array
    {
        $pemetaan = SatusehatCodeMapping::query()
            ->where('mapping_type', $mappingType)
            ->where('local_code', $localCode)
            ->where('is_active', true)
            ->first();

        if ($pemetaan === null || $pemetaan->standard_code === null) {
            return null;
        }

        return [
            'system' => $pemetaan->code_system,
            'code' => $pemetaan->standard_code,
            'display' => $pemetaan->standard_display,
        ];
    }

    public function mappings(?string $mappingType = null, bool $unmappedOnly = false): Collection
    {
        return SatusehatCodeMapping::query()
            ->when($mappingType, fn ($q) => $q->where('mapping_type', $mappingType))
            ->when($unmappedOnly, fn ($q) => $q->whereNull('standard_code'))
            ->orderBy('mapping_type')
            ->orderBy('local_code')
            ->get();
    }

    /**
     * Ringkasan kesiapan tiap jenis pemetaan.
     *
     * Angka "belum dipetakan" inilah yang menentukan berapa banyak data
     * klinis yang TIDAK akan terkirim ke SATUSEHAT — dan itu harus terlihat
     * sebelum ada yang menyimpulkan integrasinya sudah jalan.
     */
    public function readiness(): Collection
    {
        return DB::table('integration.satusehat_code_mappings')
            ->groupBy('mapping_type')
            ->selectRaw('mapping_type,
                         count(*) AS total,
                         count(*) FILTER (WHERE standard_code IS NOT NULL) AS terpetakan,
                         count(*) FILTER (WHERE standard_code IS NULL) AS belum')
            ->orderBy('mapping_type')
            ->get();
    }

    public function unmappedCount(?string $mappingType = null): int
    {
        return SatusehatCodeMapping::query()
            ->when($mappingType, fn ($q) => $q->where('mapping_type', $mappingType))
            ->whereNull('standard_code')
            ->count();
    }

    /**
     * @throws IntegrationException
     */
    private function assertType(string $mappingType): void
    {
        if (! isset(self::SISTEM_LAZIM[$mappingType])) {
            throw new IntegrationException("Jenis pemetaan '{$mappingType}' tidak dikenal.");
        }
    }
}
