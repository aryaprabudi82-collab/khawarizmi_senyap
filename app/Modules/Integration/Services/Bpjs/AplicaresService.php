<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\AplicaresBedReport;
use App\Modules\Integration\Models\AplicaresRoomMapping;
use App\Modules\Integration\Models\IcareHistoryLookup;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aplicares & iCare BPJS (domain L item B) — 3 kode.
 *
 *   mappings / saveMapping -> aplicare_referensi_kamar
 *   report / reports       -> aplicare_ketersediaan_kamar
 *   memberHistory          -> riwayat_perawatan_icare_bpjs
 *
 * KETERSEDIAAN DIBACA DARI inpatient.v_bed_availability, tidak pernah
 * disimpan ulang. Menyalinnya ke konteks integrasi berarti dua angka
 * ketersediaan yang bisa berbeda — dan yang dikirim ke BPJS justru yang
 * salah, terlihat publik di Mobile JKN.
 *
 * KELAS YANG BELUM DIPETAKAN TIDAK DIKIRIM, DAN JUMLAHNYA DILAPORKAN.
 * Menebak kode kelas BPJS akan melaporkan tempat tidur VIP sebagai kelas 3;
 * mengirimnya dengan kode kosong akan ditolak seluruh laporannya. Yang
 * benar adalah mengirim yang sudah dipetakan dan menyebut terang berapa
 * yang terlewat — angka yang tidak lengkap tapi jujur lebih berguna
 * daripada angka lengkap yang salah, dan lebih berguna lagi daripada
 * tidak mengirim apa pun.
 */
class AplicaresService
{
    private const KETERSEDIAAN = 'inpatient.v_bed_availability';

    public function __construct(private readonly AplicaresClient $client) {}

    // ------------------------------------------------------------- pemetaan

    /**
     * Menyiapkan baris pemetaan untuk tiap kelas kamar yang ada.
     *
     * Idempoten: kelas yang sudah dipetakan tidak ditimpa, kelas baru
     * ditambahkan kosong. Dijalankan lagi setelah ada kelas kamar baru.
     */
    public function syncRoomClasses(): int
    {
        $kelas = DB::table(self::KETERSEDIAAN)->distinct()->pluck('room_class');
        $baru = 0;

        foreach ($kelas as $k) {
            $ada = AplicaresRoomMapping::query()->where('room_class', $k)->exists();

            if (! $ada) {
                AplicaresRoomMapping::query()->create(['room_class' => $k, 'is_reported' => true]);
                $baru++;
            }
        }

        return $baru;
    }

    /**
     * @throws IntegrationException
     */
    public function saveMapping(string $roomClass, ?string $bpjsCode, ?string $bpjsName, bool $reported, ?int $actorId = null): AplicaresRoomMapping
    {
        $pemetaan = AplicaresRoomMapping::query()->where('room_class', $roomClass)->first()
            ?? throw new IntegrationException("Kelas kamar '{$roomClass}' tidak dikenal.");

        $kode = $bpjsCode !== null ? trim($bpjsCode) : null;

        if ($kode === '') {
            $kode = null;
        }

        // Kode kelas BPJS harus unik: dua kelas kamar kita yang dipetakan
        // ke kode BPJS yang sama akan saling menimpa saat dilaporkan.
        if ($kode !== null) {
            $bentrok = AplicaresRoomMapping::query()
                ->where('bpjs_class_code', $kode)
                ->whereKeyNot($pemetaan->id)
                ->exists();

            if ($bentrok) {
                throw new IntegrationException("Kode kelas BPJS '{$kode}' sudah dipakai kelas kamar lain.");
            }
        }

        $pemetaan->update([
            'bpjs_class_code' => $kode,
            'bpjs_class_name' => $bpjsName,
            'is_reported' => $reported,
            'mapped_by' => $actorId,
        ]);

        return $pemetaan->refresh();
    }

    // ---------------------------------------------------------- pelaporan

    /**
     * Mengirim ketersediaan tempat tidur ke BPJS.
     *
     * @throws IntegrationException
     */
    public function report(?int $actorId = null): AplicaresBedReport
    {
        $ketersediaan = $this->currentAvailability();

        if ($ketersediaan->isEmpty()) {
            throw new IntegrationException('Belum ada kamar terdaftar; tidak ada yang bisa dilaporkan.');
        }

        $terpetakan = [];
        $belum = 0;

        foreach ($ketersediaan as $baris) {
            if ($baris->bpjs_class_code === null || ! $baris->is_reported) {
                $belum++;

                continue;
            }

            $terpetakan[] = [
                'kode_kelas' => $baris->bpjs_class_code,
                'tersedia' => (int) $baris->tersedia,
                'terisi' => (int) $baris->terisi,
            ];
        }

        $jawab = $this->client->reportBedAvailability($terpetakan);

        return AplicaresBedReport::query()->create([
            'reported_at' => now(),
            'payload' => $terpetakan,
            'success' => (bool) ($jawab['success'] ?? false),
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'mapped_classes' => count($terpetakan),
            'unmapped_classes' => $belum,
            'reported_by' => $actorId,
        ]);
    }

    /**
     * Ketersediaan terkini, digabung dengan pemetaannya.
     *
     * Dibaca dari kontrak inpatient, bukan dari salinan — inilah yang
     * membuat angka yang dikirim selalu sama dengan yang tampil di layar
     * ketersediaan tempat tidur.
     */
    public function currentAvailability(): Collection
    {
        return DB::table(self::KETERSEDIAAN . ' as b')
            ->leftJoin('integration.aplicares_room_mappings as m', 'm.room_class', '=', 'b.room_class')
            ->groupBy('b.room_class', 'm.bpjs_class_code', 'm.bpjs_class_name', 'm.is_reported')
            ->selectRaw("b.room_class, m.bpjs_class_code, m.bpjs_class_name,
                         coalesce(m.is_reported, true) AS is_reported,
                         sum(b.jumlah) FILTER (WHERE b.status = 'tersedia') AS tersedia,
                         sum(b.jumlah) FILTER (WHERE b.status = 'terisi') AS terisi,
                         sum(b.jumlah) AS total")
            ->orderBy('b.room_class')
            ->get();
    }

    /** Berapa kelas kamar yang belum punya kode BPJS — angka yang harus terlihat. */
    public function unmappedCount(): int
    {
        return $this->currentAvailability()
            ->filter(fn ($b) => $b->bpjs_class_code === null && $b->is_reported)
            ->count();
    }

    public function reports(int $limit = 100): Collection
    {
        return AplicaresBedReport::query()->orderByDesc('id')->limit($limit)->get();
    }

    public function mappings(): Collection
    {
        return AplicaresRoomMapping::query()->orderBy('room_class')->get();
    }

    // ---------------------------------------------------------------- iCare

    /**
     * Riwayat perawatan peserta menurut BPJS.
     *
     * @throws IntegrationException
     */
    public function memberHistory(string $cardNumber, ?int $registrationId = null, ?int $actorId = null): IcareHistoryLookup
    {
        $kartu = trim($cardNumber);

        if ($kartu === '') {
            throw new IntegrationException('Nomor kartu wajib diisi.');
        }

        $jawab = $this->client->memberCareHistory($kartu);

        return IcareHistoryLookup::query()->create([
            'card_number' => $kartu,
            'registration_id' => $registrationId,
            'found' => ($jawab['success'] ?? false) && ($jawab['data'] ?? []) !== [],
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'raw_response' => $jawab,
            'checked_by' => $actorId,
        ]);
    }

    public function historyLookups(int $limit = 100): Collection
    {
        return IcareHistoryLookup::query()->orderByDesc('id')->limit($limit)->get();
    }
}
