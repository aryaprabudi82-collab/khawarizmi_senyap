<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter VClaim untuk rujukan dan surat kontrol (domain L item A).
 *
 * DIPISAH DARI BpjsClient, bukan ditambahkan ke dalamnya. BpjsClient sudah
 * dipakai EligibilityService dan SepService; menambah enam method ke sana
 * memaksa setiap implementasi — termasuk yang palsu — ikut mengetahui
 * seluruh permukaan VClaim meski cuma butuh sebagiannya. Antarmuka yang
 * membesar terus adalah antarmuka yang akhirnya diimplementasikan
 * setengah-setengah dengan method yang melempar "belum didukung".
 *
 * ENAM CARA MENCARI RUJUKAN, SATU BENTUK JAWABAN. Khanza memberi menu
 * terpisah untuk tiap cara pencarian (nomor rujukan PCare, nomor rujukan
 * RS, nomor kartu PCare, nomor kartu RS, tanggal, riwayat) — tapi yang
 * berbeda cuma KUNCI pencariannya; yang dikembalikan rujukan yang sama.
 * Jadi satu method dengan parameter jenis pencarian, bukan enam method.
 */
interface BpjsReferralClient
{
    /** Sumber rujukan: dari faskes tingkat pertama (PCare) atau dari rumah sakit lain. */
    public const SUMBER_PCARE = 'pcare';

    public const SUMBER_RS = 'rs';

    /** Kunci pencarian yang dikenal VClaim. */
    public const CARI_NOMOR = 'nomor';

    public const CARI_KARTU = 'kartu';

    public const CARI_TANGGAL = 'tanggal';

    /**
     * Mencari rujukan di VClaim.
     *
     * @param  string  $source  pcare|rs
     * @param  string  $by      nomor|kartu|tanggal
     * @param  string  $key     nomor rujukan, nomor kartu, atau tanggal
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function findReferral(string $source, string $by, string $key): array;

    /**
     * Riwayat rujukan yang pernah diterbitkan rumah sakit ini.
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function referralHistory(string $noKartu, string $from, string $until): array;

    /**
     * Menerbitkan surat kontrol / SPRI.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createControlLetter(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelControlLetter(string $letterNumber): array;

    /**
     * Menerbitkan rujukan keluar dari rumah sakit ini ke faskes lain.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createOutgoingReferral(array $payload): array;
}
