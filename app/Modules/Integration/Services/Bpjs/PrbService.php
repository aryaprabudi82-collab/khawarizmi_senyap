<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsPharmacyService;
use App\Modules\Integration\Models\PayerReference;
use App\Modules\Integration\Models\PrbEnrollment;
use App\Modules\Integration\Services\IntegrationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Program Rujuk Balik & pelayanan obat apotek BPJS (domain L item G).
 *
 *   candidates               -> bpjs_potensi_prb
 *   offer / enroll / reject  -> bpjs_program_prb
 *   members / expired        -> bpjs_rekap_peserta_prb_apotek
 *   recordService / services -> bpjs_daftar_pelayanan_obat_apotek,
 *                               bpjs_riwayat_pelayanan_obat,
 *                               bpjs_obat_23hari_apotek
 *
 * CALON PESERTA DIHITUNG DARI DATA KITA SENDIRI. Pasien dengan diagnosis
 * kronis yang termasuk daftar PRB dan sudah berkunjung berulang adalah
 * calon; ketiga bahannya ada pada kita — diagnosis di clinical, kunjungan
 * di encounter, daftar diagnosis PRB di referensi penjamin. Menunggu BPJS
 * menyebut siapa calonnya berarti kehilangan kesempatan menawarkan program
 * yang justru meringankan pasien: tidak perlu antre di rumah sakit tiap
 * bulan hanya untuk menebus obat rutin.
 *
 * DIAGNOSIS HARUS TERMASUK DAFTAR PRB BPJS. Daftar itu ditetapkan BPJS dan
 * dibaca dari referensi penjamin; mendaftarkan pasien dengan diagnosis di
 * luar daftar akan ditolak saat verifikasi — sesudah pasien terlanjur
 * diberi tahu bahwa ia ikut program.
 *
 * BATAS HARI OBAT DIPERIKSA di sini, karena BPJS menolak yang melebihinya:
 * 30 hari untuk obat PRB, 23 hari untuk obat kronis di luar PRB. Ditolak
 * saat pencatatan jauh lebih murah daripada ditolak saat klaim — obatnya
 * sudah terlanjur diserahkan ke pasien.
 */
class PrbService
{
    /** Jenis referensi tempat daftar diagnosis PRB BPJS disimpan. */
    public const REFERENSI_DIAGNOSA = 'diagnosa-prb';

    /** Batas hari obat menurut jenis pelayanannya, sesuai ketentuan BPJS. */
    public const BATAS_HARI = [
        BpjsPharmacyService::PRB => 30,
        BpjsPharmacyService::KRONIS => 23,
        BpjsPharmacyService::KEMOTERAPI => 30,
    ];

    private const DIAGNOSIS = 'clinical.v_encounter_diagnosis';
    private const REGISTRASI = 'encounter.v_registration_summary';

    // ---------------------------------------------------------------- calon

    /**
     * Calon peserta PRB.
     *
     * Kepesertaan BPJS-nya dibuktikan SEP yang sudah terbit, bukan ditebak
     * dari nama penjamin: nomor kartunya memang hanya ada di sana, dan PRB
     * tanpa nomor kartu tidak bisa didaftarkan.
     *
     * Yang sudah pernah ditawarkan — diterima maupun ditolak — tidak muncul
     * lagi; daftar ini gunanya menunjukkan siapa yang belum disentuh.
     */
    public function candidates(int $minVisits = 2, int $limit = 100): Collection
    {
        $kodePrb = $this->prbDiagnosisCodes();

        if ($kodePrb->isEmpty()) {
            return collect();
        }

        return DB::table(self::DIAGNOSIS . ' as d')
            ->join(self::REGISTRASI . ' as r', 'r.id', '=', 'd.registration_id')
            ->join('integration.bpjs_sep as s', function ($join) {
                $join->on('s.registration_id', '=', 'd.registration_id')
                    ->where('s.status', '=', 'terbit');
            })
            ->whereIn('d.code', $kodePrb)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('integration.prb_enrollments as e')
                    ->whereColumn('e.patient_id', 'd.patient_id')
                    ->whereColumn('e.diagnosis_code', 'd.code')
                    ->where('e.status', '<>', PrbEnrollment::BATAL);
            })
            ->groupBy('d.patient_id', 'r.patient_mrn', 'r.patient_name', 'd.code', 'd.display')
            ->havingRaw('count(distinct d.registration_id) >= ?', [$minVisits])
            ->selectRaw('d.patient_id, r.patient_mrn, r.patient_name,
                         max(s.no_kartu) AS card_number,
                         d.code AS diagnosis_code, d.display AS diagnosis_display,
                         count(distinct d.registration_id) AS kunjungan,
                         max(d.diagnosed_at) AS terakhir,
                         max(d.registration_id) AS registration_id')
            ->orderByDesc('kunjungan')
            ->limit($limit)
            ->get();
    }

    // ------------------------------------------------------------ pendaftaran

    /**
     * Menawarkan program kepada seorang pasien.
     *
     * @throws IntegrationException
     */
    public function offer(array $data, ?int $actorId = null): PrbEnrollment
    {
        $diagnosis = trim((string) ($data['diagnosis_code'] ?? ''));
        $kartu = trim((string) ($data['card_number'] ?? ''));

        $this->assertPrbDiagnosis($diagnosis);

        if ($kartu === '') {
            throw new IntegrationException('Nomor kartu BPJS wajib diisi; PRB tanpa nomor kartu tidak bisa didaftarkan.');
        }

        $aktif = PrbEnrollment::query()
            ->where('card_number', $kartu)
            ->where('diagnosis_code', $diagnosis)
            ->whereIn('status', PrbEnrollment::AKTIF)
            ->exists();

        if ($aktif) {
            throw new IntegrationException(
                'Peserta ini sudah punya keikutsertaan PRB yang masih berjalan untuk diagnosis yang sama.'
            );
        }

        return PrbEnrollment::query()->create([
            'patient_id' => $data['patient_id'],
            'patient_mrn' => $data['patient_mrn'],
            'patient_name' => $data['patient_name'],
            'card_number' => $kartu,
            'diagnosis_code' => $diagnosis,
            'diagnosis_display' => $data['diagnosis_display'] ?? null,
            'registration_id' => $data['registration_id'] ?? null,
            'sep_number' => $data['sep_number'] ?? null,
            'status' => PrbEnrollment::DITAWARKAN,
            'offered_on' => $data['offered_on'] ?? now()->toDateString(),
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Pasien menerima tawaran; keikutsertaannya didaftarkan.
     *
     * @throws IntegrationException
     */
    public function enroll(PrbEnrollment $prb, array $data): PrbEnrollment
    {
        if ($prb->status !== PrbEnrollment::DITAWARKAN) {
            throw new IntegrationException(
                "Hanya keikutsertaan yang sudah ditawarkan yang bisa didaftarkan; status sekarang '{$prb->status}'."
            );
        }

        // Faskes tingkat pertama dan apotek tujuan menentukan ke mana pasien
        // pulang berobat dan di mana obatnya diambil. PRB tanpa keduanya
        // adalah surat yang tidak bisa dipakai pasien.
        foreach (['fktp_code' => 'Faskes tingkat pertama', 'pharmacy_code' => 'Apotek tujuan'] as $kolom => $sebutan) {
            if (empty($data[$kolom])) {
                throw new IntegrationException("{$sebutan} wajib ditentukan sebelum PRB didaftarkan.");
            }
        }

        $prb->update([
            'fktp_code' => $data['fktp_code'],
            'fktp_name' => $data['fktp_name'] ?? null,
            'pharmacy_code' => $data['pharmacy_code'],
            'pharmacy_name' => $data['pharmacy_name'] ?? null,
            'status' => PrbEnrollment::TERDAFTAR,
            'enrolled_on' => $data['enrolled_on'] ?? now()->toDateString(),
            'valid_until' => $data['valid_until'] ?? now()->addMonths(3)->toDateString(),
            'prb_number' => $data['prb_number'] ?? null,
        ]);

        return $prb->refresh();
    }

    /**
     * Pasien menolak tawaran — dicatat berikut alasannya.
     *
     * @throws IntegrationException
     */
    public function reject(PrbEnrollment $prb, string $reason): PrbEnrollment
    {
        if (! in_array($prb->status, [PrbEnrollment::CALON, PrbEnrollment::DITAWARKAN], true)) {
            throw new IntegrationException(
                "Penolakan hanya berlaku sebelum keikutsertaan berjalan; status sekarang '{$prb->status}'."
            );
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new IntegrationException('Alasan penolakan wajib diisi.');
        }

        $prb->update(['status' => PrbEnrollment::DITOLAK, 'rejection_reason' => $alasan]);

        return $prb->refresh();
    }

    /**
     * Mengakhiri keikutsertaan: selesai (program tuntas) atau batal.
     *
     * @throws IntegrationException
     */
    public function close(PrbEnrollment $prb, string $status, ?string $reason = null): PrbEnrollment
    {
        if (! in_array($status, [PrbEnrollment::SELESAI, PrbEnrollment::BATAL], true)) {
            throw new IntegrationException("Status penutupan '{$status}' tidak dikenal.");
        }

        if (! in_array($prb->status, PrbEnrollment::AKTIF, true)) {
            throw new IntegrationException("Keikutsertaan berstatus '{$prb->status}' sudah tidak berjalan.");
        }

        $prb->update(['status' => $status, 'rejection_reason' => $reason ?: $prb->rejection_reason]);

        return $prb->refresh();
    }

    // --------------------------------------------------------- pelayanan obat

    /**
     * Mencatat pelayanan obat apotek BPJS.
     *
     * @throws IntegrationException
     */
    public function recordService(array $data, ?int $actorId = null): BpjsPharmacyService
    {
        $jenis = $data['service_type'] ?? BpjsPharmacyService::PRB;

        if (! isset(self::BATAS_HARI[$jenis])) {
            throw new IntegrationException("Jenis pelayanan obat '{$jenis}' tidak dikenal.");
        }

        if (empty($data['sep_number'])) {
            throw new IntegrationException('Nomor SEP wajib diisi untuk pelayanan obat BPJS.');
        }

        if (empty($data['card_number'])) {
            throw new IntegrationException('Nomor kartu BPJS wajib diisi untuk pelayanan obat BPJS.');
        }

        $hari = (int) ($data['day_supply'] ?? 0);
        $batas = self::BATAS_HARI[$jenis];

        // Ditolak di sini jauh lebih murah daripada ditolak saat klaim:
        // obatnya sudah terlanjur diserahkan ke pasien.
        if ($hari > $batas) {
            throw new IntegrationException(
                "Obat {$jenis} dibatasi {$batas} hari oleh BPJS; yang diminta {$hari} hari."
            );
        }

        $item = $data['items'] ?? [];

        return BpjsPharmacyService::query()->create([
            'service_number' => $data['service_number'] ?? null,
            'sep_number' => $data['sep_number'],
            'card_number' => $data['card_number'],
            'patient_name' => $data['patient_name'] ?? null,
            'prb_enrollment_id' => $data['prb_enrollment_id'] ?? null,
            'service_type' => $jenis,
            'served_on' => $data['served_on'] ?? now()->toDateString(),
            'day_supply' => $hari ?: null,
            // Nilainya dibekukan dari rincian yang dilaporkan, bukan dihitung
            // ulang belakangan dari master farmasi yang bisa sudah berubah.
            'total_amount' => $data['total_amount'] ?? $this->sumItems($item),
            'items' => $item ?: null,
            'status' => 'terkirim',
            'recorded_by' => $actorId,
        ]);
    }

    // --------------------------------------------------------------- laporan

    public function members(?string $status = null, ?string $search = null, int $limit = 200): Collection
    {
        return PrbEnrollment::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q, $cari) => $q->where(function ($w) use ($cari) {
                $w->where('patient_name', 'ilike', "%{$cari}%")
                    ->orWhere('patient_mrn', 'ilike', "%{$cari}%")
                    ->orWhere('card_number', 'ilike', "%{$cari}%");
            }))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Keikutsertaan yang masa berlakunya sudah lewat.
     *
     * Surat PRB berlaku terbatas. Tanpa daftar ini, pasien baru tahu masa
     * berlakunya habis saat obatnya ditolak di apotek — sesudah menempuh
     * perjalanan ke sana.
     */
    public function expired(): Collection
    {
        return PrbEnrollment::query()
            ->where('status', PrbEnrollment::TERDAFTAR)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->orderBy('valid_until')
            ->get();
    }

    public function services(string $from, string $until, ?string $type = null, int $limit = 200): Collection
    {
        return BpjsPharmacyService::query()
            ->whereBetween('served_on', [$from, $until])
            ->when($type, fn ($q) => $q->where('service_type', $type))
            ->orderByDesc('served_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Riwayat pelayanan obat seorang peserta, lintas kunjungan. */
    public function serviceHistory(string $cardNumber, int $limit = 50): Collection
    {
        return BpjsPharmacyService::query()
            ->where('card_number', $cardNumber)
            ->orderByDesc('served_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Rekap pelayanan obat per jenis. */
    public function serviceRecap(string $from, string $until): Collection
    {
        return DB::table('integration.bpjs_pharmacy_services')
            ->whereBetween('served_on', [$from, $until])
            ->where('status', 'terkirim')
            ->groupBy('service_type')
            ->selectRaw('service_type,
                         count(*) AS pelayanan,
                         count(distinct card_number) AS peserta,
                         coalesce(sum(total_amount), 0) AS nilai,
                         coalesce(round(avg(day_supply)), 0)::int AS rata_hari')
            ->orderBy('service_type')
            ->get();
    }

    /** Rekap keikutsertaan per status — termasuk yang ditolak. */
    public function enrollmentRecap(): Collection
    {
        return DB::table('integration.prb_enrollments')
            ->groupBy('status')
            ->selectRaw('status, count(*) AS jumlah, count(distinct card_number) AS peserta')
            ->orderBy('status')
            ->get();
    }

    // ---------------------------------------------------------------- privat

    /** @return Collection<int, string> */
    private function prbDiagnosisCodes(): Collection
    {
        return PayerReference::query()
            ->where('payer', 'bpjs')
            ->where('reference_type', self::REFERENSI_DIAGNOSA)
            ->pluck('code');
    }

    private function sumItems(array $items): float
    {
        return round(array_sum(array_map(
            fn ($item) => (float) ($item['subtotal'] ?? 0),
            $items
        )), 2);
    }

    /**
     * @throws IntegrationException
     */
    private function assertPrbDiagnosis(string $code): void
    {
        if ($code === '') {
            throw new IntegrationException('Diagnosis kronis wajib diisi untuk PRB.');
        }

        $ada = PayerReference::query()
            ->where('payer', 'bpjs')
            ->where('reference_type', self::REFERENSI_DIAGNOSA)
            ->where('code', $code)
            ->exists();

        if (! $ada) {
            throw new IntegrationException(
                "Diagnosis {$code} tidak termasuk daftar PRB BPJS, sehingga pendaftarannya akan ditolak saat verifikasi. "
                . 'Bila daftarnya memang belum pernah diambil, segarkan dulu referensi diagnosa PRB.'
            );
        }
    }
}
