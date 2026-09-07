<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsApotekPrescription;
use App\Modules\Integration\Models\BpjsMemberLookup;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;

/**
 * Resep apotek BPJS & resep iterasi (domain L item M) — 3 kode:
 *
 *   findSep   -> bpjs_kunjungan_sep_apotek
 *   send      -> bpjs_daftar_resep_apotek
 *   redeem    -> daftar_permintaan_resep_iterasi_bpjs
 *
 * bpjs_monitoring_klaim_apotek sudah tercakup monitoring klaim lingkup
 * 'apotek' sejak item C — menambah jalur kedua berarti dua angka klaim
 * apotek yang bisa berbeda.
 *
 * RESEP TIDAK BOLEH DIKIRIM TANPA SEP YANG SAH. Apotek Online menolak
 * resep yang SEP-nya tidak dikenal, dan penolakan itu baru terlihat setelah
 * pasien menunggu di apotek. Karena itu SEP dicari lebih dulu, dan
 * pencariannya dicatat di tabel pencarian peserta yang sama dengan item J —
 * perbuatannya memang sama: bertanya kepada BPJS dan menyimpan jawabannya.
 *
 * RESEP ITERASI ADALAH SATU RESEP YANG DITEBUS BERKALI-KALI. Peserta PRB
 * boleh menebus resep yang sama sampai tiga kali tanpa kembali ke dokter.
 * Mencatat tiap penebusan sebagai resep baru akan mengklaim tiga resep
 * padahal dokter menulis satu, dan membuat jatah iterasi bisa terlampaui
 * tanpa ada yang terlihat salah — karena tidak ada baris yang tahu ini
 * penebusan ke berapa.
 *
 * RINCIAN OBAT DIBEKUKAN saat dikirim, seperti seluruh muatan yang sudah
 * sampai ke sistem luar: master farmasi boleh berubah harga setelahnya,
 * yang sudah dilaporkan tidak boleh ikut berubah.
 */
class ApotekPrescriptionService
{
    public function __construct(private readonly BpjsApotekClient $client) {}

    /**
     * Mencari SEP dari sisi apotek, dan mencatat pencariannya.
     *
     * @throws IntegrationException
     */
    public function findSep(string $sepNumber, ?int $actorId = null): BpjsMemberLookup
    {
        $sepNumber = trim($sepNumber);

        if ($sepNumber === '') {
            throw new IntegrationException('Nomor SEP wajib diisi.');
        }

        $hasil = $this->client->findSep($sepNumber);
        $data = $hasil['data'] ?? [];

        return BpjsMemberLookup::query()->create([
            'lookup_type' => 'sep-apotek',
            'search_key' => $sepNumber,
            // Berhasil DAN ada isinya — aturan yang sama seperti item J.
            'found' => ($hasil['success'] ?? false) && $data !== [],
            'card_number' => $data['noKartu'] ?? null,
            'participant_name' => $data['nama'] ?? null,
            'summary' => $this->ringkasSep($data),
            'result_count' => 0,
            'raw_response' => $hasil,
            'error_message' => ($hasil['success'] ?? false) ? null : ($hasil['message'] ?? 'Panggilan Apotek Online gagal.'),
            'checked_by' => $actorId,
            'checked_at' => now(),
        ]);
    }

    /**
     * Mengirim resep ke Apotek Online.
     *
     * @throws IntegrationException
     */
    public function send(array $data, ?int $actorId = null): BpjsApotekPrescription
    {
        $sep = trim((string) ($data['sep_number'] ?? ''));

        if ($sep === '') {
            throw new IntegrationException('Nomor SEP wajib diisi.');
        }

        if (empty($data['card_number'])) {
            throw new IntegrationException('Nomor kartu BPJS wajib diisi.');
        }

        $item = $data['items'] ?? [];

        if ($item === []) {
            throw new IntegrationException('Resep tanpa rincian obat tidak bisa dikirim ke Apotek Online.');
        }

        $iteratif = (bool) ($data['is_iterative'] ?? false);
        $jatah = (int) ($data['iteration_allowed'] ?? 0);

        if (! $iteratif && $jatah > 0) {
            throw new IntegrationException('Jatah iterasi hanya berlaku untuk resep iterasi.');
        }

        if ($jatah > BpjsApotekPrescription::MAKS_ITERASI) {
            throw new IntegrationException(
                'BPJS membatasi iterasi resep sebanyak ' . BpjsApotekPrescription::MAKS_ITERASI . ' kali.'
            );
        }

        $hasil = $this->client->sendPrescription([
            'noSep' => $sep,
            'noKartu' => $data['card_number'],
            'tglResep' => $data['prescribed_on'] ?? now()->toDateString(),
            'iterasi' => $iteratif ? 1 : 0,
            'obat' => $item,
        ]);

        return BpjsApotekPrescription::query()->create([
            'prescription_id' => $data['prescription_id'] ?? null,
            'prescription_number' => $data['prescription_number'] ?? null,
            'sep_number' => $sep,
            'card_number' => $data['card_number'],
            'patient_name' => $data['patient_name'] ?? null,
            'apotek_code' => $data['apotek_code'] ?? null,
            'prescriber_name' => $data['prescriber_name'] ?? null,
            'prescribed_on' => $data['prescribed_on'] ?? now()->toDateString(),
            'bpjs_prescription_number' => $hasil['data']['noApotik'] ?? null,
            'is_iterative' => $iteratif,
            'iteration_allowed' => $iteratif ? $jatah : 0,
            'iteration_index' => 0,
            'parent_id' => null,
            'total_amount' => $data['total_amount'] ?? $this->jumlahkan($item),
            'items' => $item,
            'status' => $hasil['success'] ? BpjsApotekPrescription::TERKIRIM : BpjsApotekPrescription::GAGAL,
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Menebus satu iterasi dari resep induk.
     *
     * @throws IntegrationException
     */
    public function redeem(BpjsApotekPrescription $induk, ?int $actorId = null): BpjsApotekPrescription
    {
        if (! $induk->isParent()) {
            throw new IntegrationException('Penebusan iterasi hanya bisa dilakukan atas resep induk.');
        }

        if (! $induk->is_iterative) {
            throw new IntegrationException('Resep ini bukan resep iterasi; setiap penebusan menuntut resep baru dari dokter.');
        }

        if ($induk->status !== BpjsApotekPrescription::TERKIRIM) {
            throw new IntegrationException(
                "Hanya resep yang terkirim yang bisa ditebus; status sekarang '{$induk->status}'."
            );
        }

        $sisa = $induk->remainingIterations();

        if ($sisa <= 0) {
            throw new IntegrationException(
                'Jatah iterasi resep ini sudah habis. Penebusan berikutnya menuntut resep baru dari dokter.'
            );
        }

        $urutan = $induk->iteration_allowed - $sisa + 1;

        $hasil = $this->client->requestIteration([
            'noApotik' => $induk->bpjs_prescription_number,
            'noSep' => $induk->sep_number,
            'noKartu' => $induk->card_number,
            'iterasi' => $urutan,
            'tglLayan' => now()->toDateString(),
        ]);

        return BpjsApotekPrescription::query()->create([
            'prescription_id' => $induk->prescription_id,
            'prescription_number' => $induk->prescription_number,
            'sep_number' => $induk->sep_number,
            'card_number' => $induk->card_number,
            'patient_name' => $induk->patient_name,
            'apotek_code' => $induk->apotek_code,
            'prescriber_name' => $induk->prescriber_name,
            'prescribed_on' => $induk->prescribed_on->toDateString(),
            'bpjs_prescription_number' => $hasil['data']['noApotik'] ?? null,
            'is_iterative' => true,
            'iteration_allowed' => $induk->iteration_allowed,
            'iteration_index' => $urutan,
            'parent_id' => $induk->id,
            // Obatnya disalin dari resep induk: iterasi memang menebus
            // resep yang SAMA, dan menyusun ulang isinya membuka celah
            // penebusan yang berbeda dari yang diresepkan dokter.
            'total_amount' => $induk->total_amount,
            'items' => $induk->items,
            'status' => $hasil['success'] ? BpjsApotekPrescription::TERKIRIM : BpjsApotekPrescription::GAGAL,
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId,
        ]);
    }

    /** Resep iterasi yang jatahnya masih tersisa. */
    public function pendingIterations(): Collection
    {
        return BpjsApotekPrescription::query()
            ->where('is_iterative', true)
            ->where('iteration_index', 0)
            ->where('status', BpjsApotekPrescription::TERKIRIM)
            ->withCount(['iterations as terpakai' => fn ($q) => $q->where('status', '<>', BpjsApotekPrescription::BATAL)])
            ->get()
            ->filter(fn ($r) => $r->iteration_allowed > $r->terpakai)
            ->values();
    }

    public function prescriptions(string $from, string $until, ?string $status = null, int $limit = 200): Collection
    {
        return BpjsApotekPrescription::query()
            ->whereBetween('prescribed_on', [$from, $until])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('prescribed_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // ---------------------------------------------------------------- privat

    /**
     * @param  array<string, mixed>  $data
     */
    private function ringkasSep(array $data): ?string
    {
        $bagian = array_filter([
            $data['tglSep'] ?? null,
            $data['poli'] ?? null,
            $data['diagnosa'] ?? null,
        ]);

        return $bagian === [] ? null : implode(' — ', $bagian);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function jumlahkan(array $items): float
    {
        return round(array_sum(array_map(
            fn ($item) => (float) ($item['subtotal'] ?? 0),
            $items
        )), 2);
    }
}
