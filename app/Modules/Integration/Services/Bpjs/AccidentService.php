<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\AccidentRecord;
use App\Modules\Integration\Models\AccidentSupplement;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data induk kecelakaan & penjaminan Jasa Raharja (domain L item K) —
 * 3 kode: bpjs_data_induk_kecelakaan, bpjs_klaim_jasa_raharja,
 * bpjs_suplesi_jasaraharja.
 *
 * PENJAMINAN BERJENJANG, DAN URUTANNYA DITETAPKAN PERATURAN. Untuk korban
 * kecelakaan lalu lintas, PT Jasa Raharja menanggung lebih dulu sampai
 * batas santunannya dan BPJS menanggung selisihnya. Rumah sakit tidak
 * memilih urutan itu; yang bisa salah adalah mencatat JENIS kejadiannya,
 * dan salah di situ berarti menagih ke penjamin yang keliru.
 *
 * SATU KEJADIAN, BANYAK KUNJUNGAN. Kunjungan lanjutan atas kecelakaan yang
 * sama diajukan sebagai SUPLESI, bukan sebagai kejadian baru — kalau tidak,
 * satu kecelakaan berlipat jadi banyak dan pelacakan batas santunan ikut
 * kacau.
 *
 * "BELUM DITANYAKAN" BUKAN "TIDAK DIJAMIN". Selama Jasa Raharja belum
 * ditanya, jawabannya null — bukan false. Menyamakan keduanya membuat
 * tagihan seluruh korban kecelakaan langsung dibebankan ke BPJS atau ke
 * pasien, padahal sebagiannya berhak ditanggung Jasa Raharja lebih dulu.
 */
class AccidentService
{
    public function __construct(private readonly BpjsAccidentClient $client) {}

    /**
     * Mencatat kejadian kecelakaan dan mendaftarkannya ke VClaim.
     *
     * @throws IntegrationException
     */
    public function record(array $data, ?int $actorId = null): AccidentRecord
    {
        $jenis = $data['accident_type'] ?? AccidentRecord::KLL;

        if (! in_array($jenis, AccidentRecord::JENIS, true)) {
            throw new IntegrationException("Jenis kejadian '{$jenis}' tidak dikenal.");
        }

        $tanggal = $this->tanggalKejadian($data);

        foreach (['patient_id', 'patient_mrn', 'patient_name'] as $wajib) {
            if (empty($data[$wajib])) {
                throw new IntegrationException('Identitas korban wajib lengkap sebelum kejadian dicatat.');
            }
        }

        // Kecelakaan lalu lintas WAJIB punya lokasi berjenjang: VClaim
        // menolaknya tanpa itu, dan laporan kecelakaan per wilayah mustahil
        // disusun dari teks bebas.
        if (in_array($jenis, AccidentRecord::DIJAMIN_JASA_RAHARJA, true)) {
            foreach (['province_code', 'regency_code', 'district_code'] as $kolom) {
                if (empty($data[$kolom])) {
                    throw new IntegrationException(
                        'Kecelakaan lalu lintas wajib menyertakan provinsi, kabupaten/kota, dan kecamatan kejadian.'
                    );
                }
            }
        }

        $kejadian = AccidentRecord::query()->create([
            'patient_id' => $data['patient_id'],
            'patient_mrn' => $data['patient_mrn'],
            'patient_name' => $data['patient_name'],
            'card_number' => $data['card_number'] ?? null,
            'registration_id' => $data['registration_id'] ?? null,
            'sep_number' => $data['sep_number'] ?? null,
            'accident_type' => $jenis,
            'occurred_on' => $tanggal->toDateString(),
            'occurred_at_time' => $data['occurred_at_time'] ?? null,
            'province_code' => $data['province_code'] ?? null,
            'regency_code' => $data['regency_code'] ?? null,
            'district_code' => $data['district_code'] ?? null,
            'location_note' => $data['location_note'] ?? null,
            'status' => 'dicatat',
            // Diisi eksplisit, bukan diserahkan ke default kolom: objek hasil
            // create() tidak memuat default basis data, sehingga pemanggil
            // membaca null padahal maksudnya "belum ditanyakan sama sekali".
            'jasa_raharja_asked' => false,
            'recorded_by' => $actorId,
        ]);

        $this->client->registerAccident([
            'noKartu' => $kejadian->card_number,
            'tglKejadian' => $kejadian->occurred_on->toDateString(),
            'keterangan' => $kejadian->location_note,
            'kdPropinsi' => $kejadian->province_code,
            'kdKabupaten' => $kejadian->regency_code,
            'kdKecamatan' => $kejadian->district_code,
        ]);

        return $kejadian;
    }

    /**
     * Menanyakan penjaminan Jasa Raharja atas satu kejadian.
     *
     * @throws IntegrationException
     */
    public function askJasaRaharja(AccidentRecord $kejadian): AccidentRecord
    {
        if (! $kejadian->involvesJasaRaharja()) {
            throw new IntegrationException(
                'Jasa Raharja hanya menjamin kecelakaan lalu lintas. Kejadian ini tercatat sebagai '
                . $kejadian->accident_type . '.'
            );
        }

        if (empty($kejadian->card_number)) {
            throw new IntegrationException('Nomor kartu BPJS korban wajib diisi sebelum menanyakan Jasa Raharja.');
        }

        $hasil = $this->client->checkJasaRaharja(
            $kejadian->card_number,
            $kejadian->occurred_on->toDateString()
        );

        // Panggilan yang GAGAL tidak boleh menjadi "tidak dijamin": itu
        // jawaban yang tidak pernah kita terima. Yang dicatat cuma bahwa
        // percobaannya terjadi.
        if (! $hasil['success']) {
            $kejadian->update([
                'jasa_raharja_asked' => true,
                'jasa_raharja_asked_at' => now(),
                'jasa_raharja_response' => $hasil,
            ]);

            return $kejadian->refresh();
        }

        $data = $hasil['data'] ?? [];
        $dijamin = (bool) ($data['dijamin'] ?? false);

        $kejadian->update([
            'jasa_raharja_asked' => true,
            'jasa_raharja_asked_at' => now(),
            'jasa_raharja_covered' => $dijamin,
            'jasa_raharja_ceiling' => $dijamin ? ($data['plafon'] ?? null) : null,
            'jasa_raharja_number' => $dijamin ? ($data['noSuratJaminan'] ?? null) : null,
            'jasa_raharja_valid_until' => $dijamin ? ($data['berlakuSampai'] ?? null) : null,
            'jasa_raharja_response' => $hasil,
            'status' => $dijamin ? 'dijamin' : 'tidak-dijamin',
        ]);

        return $kejadian->refresh();
    }

    /**
     * Mengajukan suplesi untuk kunjungan lanjutan atas kejadian yang sama.
     *
     * @throws IntegrationException
     */
    public function supplement(AccidentRecord $kejadian, array $data, ?int $actorId = null): AccidentSupplement
    {
        if ($kejadian->status === 'batal') {
            throw new IntegrationException('Kejadian yang dibatalkan tidak bisa dijadikan dasar suplesi.');
        }

        if (empty($data['registration_id'])) {
            throw new IntegrationException('Kunjungan lanjutan wajib ditentukan untuk suplesi.');
        }

        $sudahAda = AccidentSupplement::query()
            ->where('registration_id', $data['registration_id'])
            ->exists();

        if ($sudahAda) {
            throw new IntegrationException(
                'Kunjungan ini sudah punya suplesi. Dua suplesi untuk kunjungan yang sama akan ditagihkan dua kali.'
            );
        }

        $tanggal = Carbon::parse($data['service_date'] ?? now());

        // Pelayanan lanjutan tidak mungkin mendahului kecelakaannya.
        if ($tanggal->lt($kejadian->occurred_on)) {
            throw new IntegrationException('Tanggal pelayanan tidak boleh mendahului tanggal kejadian.');
        }

        $hasil = $this->client->createSupplement([
            'noSepAwal' => $kejadian->sep_number,
            'noKartu' => $kejadian->card_number,
            'tglPelayanan' => $tanggal->toDateString(),
            'tglKejadian' => $kejadian->occurred_on->toDateString(),
        ]);

        return AccidentSupplement::query()->create([
            'accident_record_id' => $kejadian->id,
            'registration_id' => $data['registration_id'],
            'sep_number' => $hasil['data']['noSuplesi'] ?? ($data['sep_number'] ?? null),
            'service_date' => $tanggal->toDateString(),
            'status' => $hasil['success'] ? 'diterima' : 'gagal',
            'response_code' => $hasil['code'] ?? null,
            'response_message' => $hasil['message'] ?? null,
            'raw_response' => $hasil,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Kejadian yang belum ditanyakan ke Jasa Raharja.
     *
     * Inilah daftar yang menentukan tagihan siapa yang masih menggantung:
     * selama belum ditanya, tidak ada yang tahu siapa penjamin pertamanya.
     */
    public function pendingJasaRaharja(): Collection
    {
        return AccidentRecord::query()
            ->whereIn('accident_type', AccidentRecord::DIJAMIN_JASA_RAHARJA)
            ->where('jasa_raharja_asked', false)
            ->where('status', '<>', 'batal')
            ->orderBy('occurred_on')
            ->get();
    }

    public function recent(?string $type = null, int $limit = 100): Collection
    {
        return AccidentRecord::query()
            ->when($type, fn ($q) => $q->where('accident_type', $type))
            ->withCount('supplements')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @throws IntegrationException
     */
    private function tanggalKejadian(array $data): Carbon
    {
        if (empty($data['occurred_on'])) {
            throw new IntegrationException('Tanggal kejadian wajib diisi.');
        }

        $tanggal = Carbon::parse($data['occurred_on'])->startOfDay();

        // Salah ketik tahun pada tanggal kejadian adalah kesalahan yang
        // paling sering terjadi dan paling sulit terlihat: SEP tetap terbit,
        // lalu klaimnya ditolak berbulan-bulan kemudian.
        if ($tanggal->isFuture()) {
            throw new IntegrationException('Tanggal kejadian tidak boleh melewati hari ini.');
        }

        $pelayanan = empty($data['service_date']) ? null : Carbon::parse($data['service_date'])->startOfDay();

        if ($pelayanan !== null && $tanggal->gt($pelayanan)) {
            throw new IntegrationException('Tanggal kejadian tidak boleh setelah tanggal pelayanan.');
        }

        return $tanggal;
    }
}
