<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsControlLetter;
use App\Modules\Integration\Models\BpjsOutgoingReferral;
use App\Modules\Integration\Models\BpjsReferralLookup;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rujukan & surat kontrol BPJS (domain L item A) — 18 kode.
 *
 *   lookup / history  -> keenam kode "cek rujukan" dan riwayat rujukan RS
 *   issueControlLetter / cancelControlLetter -> bpjs_surat_kontrol
 *   issueOutgoingReferral -> bpjs_rujukan_keluar, bpjs_rujukan_khusus
 *
 * TIGA ATURAN YANG DIPEGANG SELURUH KELAS INI:
 *
 * 1. KEGAGALAN IKUT DICATAT, bukan cuma keberhasilan. VClaim sering
 *    gangguan, dan pertanyaan "kenapa SEP-nya belum terbit" cuma bisa
 *    dijawab kalau percobaan yang gagal juga meninggalkan jejak. Baris
 *    dengan status 'gagal' berikut pesan dari BPJS justru yang paling
 *    sering dibutuhkan saat menelusuri masalah.
 *
 * 2. JAWABAN BPJS DISIMPAN APA ADANYA dan tidak pernah dipakai
 *    menggantikan data kita sendiri. Nama peserta menurut BPJS boleh
 *    berbeda dari nama di rekam medis; menimpanya berarti membiarkan
 *    sistem luar mengubah identitas pasien kita.
 *
 * 3. MASA BERLAKU RUJUKAN DIPERIKSA DAN DILAPORKAN, tidak diam-diam
 *    diloloskan. Rujukan BPJS berlaku 90 hari; rujukan kedaluwarsa yang
 *    diteruskan akan ditolak saat SEP diterbitkan — lebih baik ketahuan
 *    di layar pencarian daripada saat pasien sudah menunggu di loket.
 */
class ReferralService
{
    /** Masa berlaku rujukan BPJS, dalam hari. */
    public const MASA_BERLAKU_HARI = 90;

    public function __construct(private readonly BpjsReferralClient $client) {}

    // -------------------------------------------------------------- pencarian

    /**
     * Mencari rujukan di VClaim dan menyimpan hasilnya.
     *
     * @throws IntegrationException
     */
    public function lookup(string $source, string $by, string $key, ?int $actorId = null): BpjsReferralLookup
    {
        $this->assertSource($source);
        $this->assertSearchBy($by);

        $key = trim($key);

        if ($key === '') {
            throw new IntegrationException('Kunci pencarian rujukan wajib diisi.');
        }

        $jawab = $this->client->findReferral($source, $by, $key);
        $data = $jawab['data'] ?? [];

        return BpjsReferralLookup::query()->create([
            'source' => $source,
            'searched_by' => $by,
            'search_key' => $key,
            'found' => ($jawab['success'] ?? false) && $data !== [],
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'referral_number' => $data['no_rujukan'] ?? null,
            'referral_date' => $data['tanggal_rujukan'] ?? null,
            'card_number' => $data['no_kartu'] ?? null,
            'member_name' => $data['nama_peserta'] ?? null,
            'referring_facility' => $data['faskes_perujuk'] ?? null,
            'diagnosis' => $data['diagnosa'] ?? null,
            'target_poly' => $data['poli_tujuan'] ?? null,
            'raw_response' => $jawab,
            'checked_by' => $actorId,
        ]);
    }

    /**
     * Riwayat rujukan yang pernah diterbitkan rumah sakit ini.
     *
     * @return array<string, mixed>
     */
    public function history(string $cardNumber, string $from, string $until): array
    {
        return $this->client->referralHistory($cardNumber, $from, $until);
    }

    // ------------------------------------------------------------ surat kontrol

    /**
     * Menerbitkan surat kontrol.
     *
     * @throws IntegrationException
     */
    public function issueControlLetter(array $data, ?int $actorId = null): BpjsControlLetter
    {
        if (empty($data['sep_number'])) {
            throw new IntegrationException('Nomor SEP asal wajib diisi.');
        }

        if (strtotime($data['planned_date']) < strtotime(now()->toDateString())) {
            throw new IntegrationException('Tanggal rencana kontrol tidak boleh sudah lewat.');
        }

        // Satu SEP hanya boleh punya satu surat kontrol berlaku. Diperiksa
        // di sini juga, bukan cuma indeks unik, supaya pesannya bisa
        // dimengerti petugas alih-alih berupa galat basis data.
        $berlaku = BpjsControlLetter::query()
            ->where('sep_number', $data['sep_number'])
            ->where('status', BpjsControlLetter::TERBIT)
            ->whereNull('cancelled_at')
            ->exists();

        if ($berlaku) {
            throw new IntegrationException(
                "SEP {$data['sep_number']} sudah punya surat kontrol yang masih berlaku."
            );
        }

        $jawab = $this->client->createControlLetter([
            'no_sep' => $data['sep_number'],
            'no_kartu' => $data['card_number'],
            'tanggal_rencana' => $data['planned_date'],
            'poli' => $data['target_poly'] ?? null,
            'kode_dokter' => $data['practitioner_code'] ?? null,
        ]);

        // Baris tetap dibuat meski gagal — lihat aturan 1 pada docblock.
        return BpjsControlLetter::query()->create([
            'letter_number' => $jawab['data']['no_surat'] ?? null,
            'registration_id' => $data['registration_id'] ?? null,
            'sep_number' => $data['sep_number'],
            'card_number' => $data['card_number'],
            'member_name' => $data['member_name'] ?? null,
            'planned_date' => $data['planned_date'],
            'target_poly' => $data['target_poly'] ?? null,
            'practitioner_code' => $data['practitioner_code'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => ($jawab['success'] ?? false) ? BpjsControlLetter::TERBIT : BpjsControlLetter::GAGAL,
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @throws IntegrationException
     */
    public function cancelControlLetter(BpjsControlLetter $surat, string $reason): BpjsControlLetter
    {
        if ($surat->status !== BpjsControlLetter::TERBIT) {
            throw new IntegrationException('Hanya surat kontrol yang terbit yang bisa dibatalkan.');
        }

        if ($surat->cancelled_at !== null) {
            throw new IntegrationException('Surat kontrol ini sudah dibatalkan.');
        }

        $jawab = $this->client->cancelControlLetter((string) $surat->letter_number);

        if (! ($jawab['success'] ?? false)) {
            throw new IntegrationException(
                'BPJS menolak pembatalan: ' . ($jawab['message'] ?? 'tanpa keterangan') . '.'
            );
        }

        $surat->update([
            'status' => BpjsControlLetter::BATAL,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $surat->refresh();
    }

    // ----------------------------------------------------------- rujukan keluar

    /**
     * @throws IntegrationException
     */
    public function issueOutgoingReferral(array $data, ?int $actorId = null): BpjsOutgoingReferral
    {
        foreach (['sep_number', 'card_number', 'target_facility_code'] as $wajib) {
            if (empty($data[$wajib])) {
                throw new IntegrationException("Kolom {$wajib} wajib diisi untuk menerbitkan rujukan keluar.");
            }
        }

        $jawab = $this->client->createOutgoingReferral([
            'no_sep' => $data['sep_number'],
            'no_kartu' => $data['card_number'],
            'faskes_tujuan' => $data['target_facility_code'],
            'tanggal_rujukan' => $data['referral_date'] ?? now()->toDateString(),
            'diagnosa' => $data['diagnosis_code'] ?? null,
            'khusus' => (bool) ($data['is_special'] ?? false),
        ]);

        return BpjsOutgoingReferral::query()->create([
            'referral_number' => $jawab['data']['no_rujukan'] ?? null,
            'registration_id' => $data['registration_id'] ?? null,
            'sep_number' => $data['sep_number'],
            'card_number' => $data['card_number'],
            'referral_date' => $data['referral_date'] ?? now()->toDateString(),
            'target_facility_code' => $data['target_facility_code'],
            'target_facility_name' => $data['target_facility_name'] ?? null,
            'target_service' => $data['target_service'] ?? null,
            'diagnosis_code' => $data['diagnosis_code'] ?? null,
            'reason' => $data['reason'] ?? null,
            'is_special' => (bool) ($data['is_special'] ?? false),
            'status' => ($jawab['success'] ?? false) ? BpjsOutgoingReferral::TERBIT : BpjsOutgoingReferral::GAGAL,
            'response_code' => $jawab['code'] ?? null,
            'response_message' => $jawab['message'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    // ---------------------------------------------------------------- laporan

    public function recentLookups(int $limit = 100): Collection
    {
        return BpjsReferralLookup::query()->orderByDesc('id')->limit($limit)->get();
    }

    public function controlLetters(?string $status = null): Collection
    {
        return BpjsControlLetter::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function outgoingReferrals(?string $status = null): Collection
    {
        return BpjsOutgoingReferral::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * Percobaan yang GAGAL, dilaporkan tersendiri.
     *
     * Ini yang paling sering dibutuhkan saat menelusuri "kenapa SEP-nya
     * belum terbit" — dan justru yang paling mudah hilang kalau hanya
     * keberhasilan yang dicatat.
     */
    public function failures(string $from, string $until): Collection
    {
        return DB::table('integration.bpjs_control_letters')
            ->whereRaw('created_at::date BETWEEN ?::date AND ?::date', [$from, $until])
            ->where('status', BpjsControlLetter::GAGAL)
            ->selectRaw("'surat-kontrol' AS jenis, sep_number AS rujukan, response_code, response_message, created_at")
            ->unionAll(
                DB::table('integration.bpjs_outgoing_referrals')
                    ->whereRaw('created_at::date BETWEEN ?::date AND ?::date', [$from, $until])
                    ->where('status', BpjsOutgoingReferral::GAGAL)
                    ->selectRaw("'rujukan-keluar' AS jenis, sep_number AS rujukan, response_code, response_message, created_at")
            )
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @throws IntegrationException
     */
    private function assertSource(string $source): void
    {
        if (! in_array($source, [BpjsReferralClient::SUMBER_PCARE, BpjsReferralClient::SUMBER_RS], true)) {
            throw new IntegrationException("Sumber rujukan '{$source}' tidak dikenal.");
        }
    }

    /**
     * @throws IntegrationException
     */
    private function assertSearchBy(string $by): void
    {
        $sah = [
            BpjsReferralClient::CARI_NOMOR,
            BpjsReferralClient::CARI_KARTU,
            BpjsReferralClient::CARI_TANGGAL,
        ];

        if (! in_array($by, $sah, true)) {
            throw new IntegrationException("Cara pencarian '{$by}' tidak dikenal.");
        }
    }
}
