<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsMemberLookup;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;

/**
 * Pencarian & riwayat peserta BPJS (domain L item J) — 4 kode:
 *
 *   byNik            -> bpjs_cek_nik
 *   skdp             -> bpjs_cek_skdp
 *   serviceHistory   -> bpjs_histori_pelayanan
 *   fingerprint      -> bpjs_daftar_finger_print
 *
 * KEEMPATNYA SATU PERBUATAN: bertanya kepada BPJS tentang seorang peserta
 * dan menyimpan jawabannya. Karena itu satu tabel dan satu jalur
 * pencatatan, sama seperti enam "cek rujukan" di item A.
 *
 * KEGAGALAN IKUT DICATAT. Pertanyaan "kenapa peserta ini tidak ketemu"
 * hanya bisa dijawab kalau percobaan yang gagal meninggalkan jejak —
 * termasuk membedakan "VClaim sedang gangguan" dari "peserta memang tidak
 * terdaftar", dua keadaan yang menuntut tindakan berbeda dari petugas.
 *
 * JAWABAN BPJS TIDAK PERNAH MENIMPA DATA PASIEN KITA. Nama dan NIK
 * menurut BPJS disimpan sebagai salinan; kalau berbeda dari rekam medis,
 * perbedaannya dilaporkan untuk ditindaklanjuti manusia. Membiarkan sistem
 * luar mengubah identitas pasien adalah cara paling halus untuk merusak
 * rekam medis.
 *
 * HISTORI PELAYANAN TIDAK DILEBUR KE RIWAYAT KUNJUNGAN KITA — isinya
 * pelayanan di fasilitas lain, dan meleburnya membuat rekam medis kita
 * seolah memuat pelayanan yang tidak pernah kita berikan.
 */
class MemberLookupService
{
    public function __construct(private readonly BpjsMemberClient $client) {}

    /**
     * Mencari peserta menurut NIK kependudukan.
     *
     * @throws IntegrationException
     */
    public function byNik(string $nik, ?string $serviceDate = null, ?int $actorId = null): BpjsMemberLookup
    {
        $nik = trim($nik);

        if ($nik === '') {
            throw new IntegrationException('NIK wajib diisi.');
        }

        $tanggal = $serviceDate ?? now()->toDateString();
        $hasil = $this->client->findByNik($nik, $tanggal);
        $data = $hasil['data'] ?? [];

        return $this->catat(BpjsMemberLookup::NIK, $nik, $hasil, [
            'card_number' => $data['noKartu'] ?? null,
            'participant_name' => $data['nama'] ?? null,
            'summary' => $this->ringkasPeserta($data),
        ], $actorId);
    }

    /**
     * Surat Keterangan Dalam Perawatan.
     *
     * @throws IntegrationException
     */
    public function skdp(string $letterNumber, string $cardNumber, ?int $actorId = null): BpjsMemberLookup
    {
        $letterNumber = trim($letterNumber);
        $cardNumber = trim($cardNumber);

        if ($letterNumber === '' || $cardNumber === '') {
            throw new IntegrationException('Nomor surat dan nomor kartu wajib diisi untuk mencari SKDP.');
        }

        $hasil = $this->client->findSkdp($letterNumber, $cardNumber);
        $data = $hasil['data'] ?? [];

        $ringkas = array_filter([
            $data['poliTujuan'] ?? null,
            $data['namaDokter'] ?? null,
            isset($data['tglRencanaKontrol']) ? 'rencana kontrol ' . $data['tglRencanaKontrol'] : null,
        ]);

        return $this->catat(BpjsMemberLookup::SKDP, $letterNumber, $hasil, [
            'card_number' => $cardNumber,
            'participant_name' => $data['nama'] ?? null,
            'summary' => $ringkas === [] ? null : implode(' — ', $ringkas),
        ], $actorId);
    }

    /**
     * Riwayat pelayanan peserta di seluruh fasilitas.
     *
     * @throws IntegrationException
     */
    public function serviceHistory(string $cardNumber, string $from, string $until, ?int $actorId = null): BpjsMemberLookup
    {
        $cardNumber = trim($cardNumber);

        if ($cardNumber === '') {
            throw new IntegrationException('Nomor kartu wajib diisi.');
        }

        if ($until < $from) {
            throw new IntegrationException('Tanggal akhir tidak boleh mendahului tanggal mulai.');
        }

        $hasil = $this->client->serviceHistory($cardNumber, $from, $until);
        $baris = $hasil['data']['histori'] ?? [];

        return $this->catat(BpjsMemberLookup::HISTORI, $cardNumber, $hasil, [
            'card_number' => $cardNumber,
            'period_from' => $from,
            'period_until' => $until,
            'result_count' => count($baris),
            'summary' => count($baris) . ' pelayanan menurut BPJS',
        ], $actorId, ['rows' => $baris]);
    }

    /**
     * Status pendaftaran sidik jari peserta.
     *
     * YANG DICATAT STATUSNYA, BUKAN SIDIK JARINYA. Sistem ini tidak punya
     * perangkat pemindai dan tidak menyimpan data biometrik apa pun; yang
     * ditanyakan hanya apakah peserta perlu diarahkan mendaftar lebih dulu.
     *
     * @throws IntegrationException
     */
    public function fingerprint(string $cardNumber, ?string $serviceDate = null, ?int $actorId = null): BpjsMemberLookup
    {
        $cardNumber = trim($cardNumber);

        if ($cardNumber === '') {
            throw new IntegrationException('Nomor kartu wajib diisi.');
        }

        $tanggal = $serviceDate ?? now()->toDateString();
        $hasil = $this->client->fingerprintStatus($cardNumber, $tanggal);
        $data = $hasil['data'] ?? [];

        $terdaftar = $data['terdaftar'] ?? null;

        return $this->catat(BpjsMemberLookup::FINGERPRINT, $cardNumber, $hasil, [
            'card_number' => $cardNumber,
            'summary' => match ($terdaftar) {
                true => 'Sudah terdaftar sidik jari' . (isset($data['tglDaftar']) ? ' sejak ' . $data['tglDaftar'] : ''),
                false => 'BELUM terdaftar sidik jari — arahkan peserta mendaftar di BPJS',
                default => null,
            },
        ], $actorId);
    }

    /**
     * Apakah peserta ini sudah terdaftar sidik jari, menurut pencarian
     * terakhir yang berhasil.
     *
     * Mengembalikan null kalau belum pernah dicari — dan null itu BUKAN
     * "belum terdaftar". Menyamakan keduanya akan membuat petugas
     * mengarahkan peserta mendaftar ulang tanpa sebab.
     */
    public function fingerprintRegistered(string $cardNumber): ?bool
    {
        $terakhir = BpjsMemberLookup::query()
            ->where('lookup_type', BpjsMemberLookup::FINGERPRINT)
            ->where('card_number', $cardNumber)
            ->where('found', true)
            ->orderByDesc('checked_at')
            ->first();

        if ($terakhir === null) {
            return null;
        }

        $nilai = $terakhir->raw_response['data']['terdaftar'] ?? null;

        return is_bool($nilai) ? $nilai : null;
    }

    /** Pencarian terakhir, untuk ditampilkan di layar. */
    public function recent(?string $type = null, int $limit = 50): Collection
    {
        return BpjsMemberLookup::query()
            ->when($type, fn ($q) => $q->where('lookup_type', $type))
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // ---------------------------------------------------------------- privat

    /**
     * @param  array{success: bool, code: string, message: string, data: array<string, mixed>}  $hasil
     * @param  array<string, mixed>  $kolom
     * @param  array<string, mixed>  $tambahan
     */
    private function catat(string $jenis, string $kunci, array $hasil, array $kolom, ?int $actorId, array $tambahan = []): BpjsMemberLookup
    {
        // "Ditemukan" menuntut DUA hal: panggilannya berhasil DAN ada isinya.
        // VClaim menjawab 200 dengan response kosong untuk peserta yang tidak
        // ada — dianggap ditemukan, layar akan menampilkan baris kosong yang
        // tampak seperti data.
        $adaIsi = ($hasil['data'] ?? []) !== [];
        $ditemukan = ($hasil['success'] ?? false) && $adaIsi;

        return BpjsMemberLookup::query()->create(array_merge([
            'lookup_type' => $jenis,
            'search_key' => $kunci,
            'found' => $ditemukan,
            'result_count' => 0,
            'raw_response' => array_merge($hasil, $tambahan),
            // Gagal memanggil dan berhasil memanggil tapi peserta tidak ada
            // adalah dua keadaan berbeda, dan pesannya dibedakan.
            'error_message' => ($hasil['success'] ?? false) ? null : ($hasil['message'] ?? 'Panggilan VClaim gagal.'),
            'checked_by' => $actorId,
            'checked_at' => now(),
        ], $kolom));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ringkasPeserta(array $data): ?string
    {
        $bagian = array_filter([
            $data['jenisPeserta'] ?? null,
            isset($data['kelasRawat']) ? 'kelas ' . $data['kelasRawat'] : null,
            $data['statusPeserta'] ?? null,
        ]);

        return $bagian === [] ? null : implode(' — ', $bagian);
    }
}
