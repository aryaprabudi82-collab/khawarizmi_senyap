<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\IntegrationHealthCheck;
use App\Modules\Integration\Services\Bpjs\BpjsClient;
use App\Modules\Integration\Services\Satusehat\SatusehatClient;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Uji koneksi ke sistem luar.
 *
 * GUNANYA MENJAWAB SATU PERTANYAAN: kredensial yang baru diisi itu benar
 * atau tidak. Tanpa ini, kesalahan ketik pada consumer secret baru
 * ketahuan saat pasien pertama gagal dibuatkan SEP — di loket, dengan
 * antrean di belakangnya.
 *
 * UJINYA MEMAKAI PANGGILAN YANG PALING TIDAK BERAKIBAT. Yang dipakai
 * adalah pembacaan, bukan penulisan: cek eligibilitas dengan nomor kartu
 * contoh, bukan menerbitkan SEP. Uji koneksi yang meninggalkan data di
 * sistem pihak luar bukan uji koneksi, itu transaksi.
 *
 * JAWABAN "DITOLAK" TETAP DIHITUNG TERSAMBUNG. Kalau BPJS menjawab
 * "peserta tidak ditemukan", artinya kredensial kita DITERIMA dan
 * permintaannya sampai — yang gagal cuma datanya. Membedakan keduanya
 * penting: yang pertama berarti kredensial salah, yang kedua berarti
 * kredensial benar.
 */
class IntegrationHealthService
{
    public function __construct(private readonly CredentialStore $credentials) {}

    /**
     * @throws IntegrationException
     */
    public function check(string $system, ?int $actorId = null): IntegrationHealthCheck
    {
        IntegrationRegistry::system($system);

        if (! $this->credentials->isReady($system)) {
            $kurang = implode(', ', $this->credentials->missingFields($system));

            return $this->record($system, false, null, "Belum bisa diuji: kolom wajib belum terisi ({$kurang}).", 0, $actorId);
        }

        $mulai = microtime(true);

        try {
            [$berhasil, $kode, $pesan] = $this->probe($system);
        } catch (Throwable $e) {
            // Galat apa pun dari adapter dicatat sebagai kegagalan, bukan
            // dilempar: uji koneksi yang meledak tidak memberi tahu apa pun
            // kepada orang yang sedang memasang kredensial.
            $berhasil = false;
            $kode = null;
            $pesan = 'Galat saat menghubungi: ' . $e->getMessage();
        }

        $durasi = (int) round((microtime(true) - $mulai) * 1000);

        return $this->record($system, $berhasil, $kode, $pesan, $durasi, $actorId);
    }

    /**
     * @return array{0: bool, 1: ?string, 2: string}
     */
    private function probe(string $system): array
    {
        return match ($system) {
            'bpjs' => $this->probeBpjs(),
            'satusehat' => $this->probeSatusehat(),
            default => [
                false,
                null,
                'Uji koneksi untuk sistem ini belum tersedia; kredensialnya tetap tersimpan dan dipakai adapternya.',
            ],
        };
    }

    /** @return array{0: bool, 1: ?string, 2: string} */
    private function probeBpjs(): array
    {
        // Pembacaan, bukan penulisan — lihat catatan kelas.
        $jawab = app(BpjsClient::class)->checkEligibility('0000000000000', now()->toDateString());

        $kode = (string) ($jawab['code'] ?? '');

        // Jawaban apa pun yang punya kode berarti permintaan kita SAMPAI dan
        // kredensialnya diterima. Yang menandakan kredensial salah adalah
        // penolakan autentikasi (401/403) atau tidak ada jawaban sama sekali.
        $tersambung = $kode !== '' && ! in_array($kode, ['401', '403', '000'], true);

        return [
            $tersambung,
            $kode ?: null,
            $tersambung
                ? 'Tersambung. BPJS menjawab: ' . ($jawab['message'] ?? 'tanpa pesan') . '.'
                : 'Tidak tersambung atau kredensial ditolak: ' . ($jawab['message'] ?? 'tanpa pesan') . '.',
        ];
    }

    /** @return array{0: bool, 1: ?string, 2: string} */
    private function probeSatusehat(): array
    {
        $client = app(SatusehatClient::class);

        // organizationId() memaksa penukaran token kalau adapternya asli —
        // itu justru yang ingin diuji: apakah client id/secret diterima.
        $orgId = $client->organizationId();

        $ada = $orgId !== '';

        return [
            $ada,
            $ada ? '200' : null,
            $ada
                ? 'Tersambung. Organization ID terbaca: ' . $orgId . '.'
                : 'Organization ID kosong; periksa client id, client secret, dan URL autentikasi.',
        ];
    }

    private function record(string $system, bool $berhasil, ?string $kode, string $pesan, int $durasi, ?int $actorId): IntegrationHealthCheck
    {
        return IntegrationHealthCheck::query()->create([
            'system' => $system,
            'success' => $berhasil,
            'response_code' => $kode,
            'message' => mb_substr($pesan, 0, 300),
            'duration_ms' => $durasi,
            'checked_by' => $actorId,
            'checked_at' => now(),
        ]);
    }

    /** Hasil uji terakhir tiap sistem. */
    public function latest(): Collection
    {
        return collect(IntegrationRegistry::keys())
            ->mapWithKeys(fn ($s) => [
                $s => IntegrationHealthCheck::query()
                    ->where('system', $s)
                    ->orderByDesc('checked_at')
                    ->first(),
            ]);
    }

    public function history(string $system, int $limit = 20): Collection
    {
        return IntegrationHealthCheck::query()
            ->where('system', $system)
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }
}
