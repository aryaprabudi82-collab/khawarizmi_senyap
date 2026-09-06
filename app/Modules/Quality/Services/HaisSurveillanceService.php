<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\DeviceDay;
use App\Modules\Quality\Models\InfectionEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surveilans HAIs (domain J item E) — pencatatan kejadian dan penyebutnya.
 *
 * Konteks quality sudah memegang audit kepatuhan bundle pencegahan sejak
 * domain C, tapi belum pernah mencatat kejadian infeksinya. Kepatuhan
 * bundle 100% dan angka infeksi nol adalah dua pernyataan berbeda.
 *
 * ANGKA HAIs SELALU DILAPORKAN SEBAGAI INSIDEN PER 1000 HARI-ALAT
 * (Permenkes 27/2017), bukan sebagai jumlah kejadian. Jumlah kejadian
 * saja membuat bangsal yang merawat lebih banyak pasien selalu terlihat
 * lebih buruk daripada bangsal kecil — padahal bisa jadi justru lebih
 * aman per pasiennya. Karena itu tiap angka di sini datang berpasangan
 * dengan penyebutnya, dan kalau penyebutnya belum dicatat, rate-nya
 * dilaporkan KOSONG — bukan sama dengan jumlah kejadian.
 */
class HaisSurveillanceService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * Mencatat satu kejadian infeksi.
     *
     * @throws QualityException
     */
    public function recordEvent(array $data): InfectionEvent
    {
        $jenis = $data['infection_type'] ?? '';

        if (! isset(InfectionEvent::JENIS[$jenis])) {
            throw new QualityException("Jenis infeksi '{$jenis}' tidak dikenal.");
        }

        if (isset($data['device']) && $data['device'] !== null
            && ! in_array($data['device'], InfectionEvent::ALAT, true)) {
            throw new QualityException("Alat '{$data['device']}' tidak dikenal.");
        }

        return InfectionEvent::query()->create([
            'event_number' => $this->numbers->allocate('HAIS'),
            'admission_id' => $data['admission_id'] ?? null,
            'patient_id' => $data['patient_id'],
            'patient_mrn' => $data['patient_mrn'],
            'patient_name' => $data['patient_name'],
            'unit_id' => $data['unit_id'] ?? null,
            'unit_name' => $data['unit_name'],
            'infection_type' => $jenis,
            'device' => $data['device'] ?? null,
            'onset_on' => $data['onset_on'],
            'days_after_admission' => $data['days_after_admission'] ?? null,
            'clinical_criteria' => $data['clinical_criteria'],
            'culture_result' => $data['culture_result'] ?? null,
            'corrective_action' => $data['corrective_action'] ?? null,
            'reported_by' => $data['reported_by'] ?? null,
            'reported_by_name' => $data['reported_by_name'] ?? null,
        ]);
    }

    /**
     * Mencatat penyebut satu bangsal untuk satu tanggal.
     *
     * Dibuat idempoten lewat updateOrCreate: perawat PPI yang menghitung
     * ulang hari yang sama harus MENGGANTI angkanya, bukan menambah baris
     * kedua. Penyebut ganda akan menggelembung dan membuat angka HAIs
     * terlihat lebih kecil daripada kenyataannya.
     */
    public function recordDenominator(string $unitName, string $date, array $counts, ?int $actorId = null): DeviceDay
    {
        return DeviceDay::query()->updateOrCreate(
            ['unit_name' => $unitName, 'counted_on' => $date],
            [
                'unit_id' => $counts['unit_id'] ?? null,
                'patient_days' => $counts['patient_days'] ?? 0,
                'ventilator_days' => $counts['ventilator_days'] ?? 0,
                'central_line_days' => $counts['central_line_days'] ?? 0,
                'urinary_catheter_days' => $counts['urinary_catheter_days'] ?? 0,
                'peripheral_line_days' => $counts['peripheral_line_days'] ?? 0,
                'recorded_by' => $actorId,
            ]
        );
    }

    // ------------------------------------------------------------- laporan

    /**
     * Angka HAIs per jenis infeksi: jumlah, penyebut, dan rate per 1000.
     *
     * Rate dikosongkan kalau penyebutnya nol — BUKAN disamakan dengan
     * jumlah kejadian, dan bukan pula nol. Penyebut nol berarti "belum
     * dicatat", bukan "tidak ada hari-alat".
     */
    public function ratesByType(string $from, string $until, ?string $unitName = null): Collection
    {
        $kejadian = $this->eventQuery($from, $until, $unitName)
            ->groupBy('infection_type')
            ->selectRaw('infection_type, count(*) AS jumlah')
            ->pluck('jumlah', 'infection_type');

        $penyebut = $this->denominatorTotals($from, $until, $unitName);

        return collect(InfectionEvent::JENIS)->map(function ($label, $jenis) use ($kejadian, $penyebut) {
            $kolom = InfectionEvent::PENYEBUT[$jenis];
            $hari = (int) ($penyebut[$kolom] ?? 0);
            $jumlah = (int) ($kejadian[$jenis] ?? 0);

            return (object) [
                'jenis' => $jenis,
                'label' => $label,
                'jumlah' => $jumlah,
                'penyebut' => $hari,
                'satuan_penyebut' => $kolom === 'patient_days' ? 'hari-rawat' : 'hari-alat',
                'rate' => $hari > 0 ? round($jumlah / $hari * 1000, 2) : null,
            ];
        })->values();
    }

    /** Angka HAIs per bangsal — pertanyaan hais_perbangsal. */
    public function ratesByUnit(string $from, string $until, ?string $infectionType = null): Collection
    {
        $q = $this->eventQuery($from, $until, null);

        if ($infectionType !== null && $infectionType !== '') {
            $q->where('infection_type', $infectionType);
        }

        $kejadian = $q->groupBy('unit_name')
            ->selectRaw('unit_name, count(*) AS jumlah')
            ->pluck('jumlah', 'unit_name');

        $kolom = $infectionType ? (InfectionEvent::PENYEBUT[$infectionType] ?? 'patient_days') : 'patient_days';

        $penyebut = DB::table('quality.device_days')
            ->whereBetween('counted_on', [$from, $until])
            ->groupBy('unit_name')
            ->selectRaw("unit_name, coalesce(sum({$kolom}), 0) AS hari")
            ->pluck('hari', 'unit_name');

        // Bangsal muncul kalau punya kejadian ATAU punya penyebut tercatat.
        // Bangsal yang mencatat penyebut tapi nol kejadian adalah informasi
        // yang dicari, bukan baris kosong yang boleh dibuang.
        return collect($kejadian->keys()->merge($penyebut->keys())->unique()->sort()->values())
            ->map(function ($unit) use ($kejadian, $penyebut, $kolom) {
                $hari = (int) ($penyebut[$unit] ?? 0);
                $jumlah = (int) ($kejadian[$unit] ?? 0);

                return (object) [
                    'unit_name' => $unit,
                    'jumlah' => $jumlah,
                    'penyebut' => $hari,
                    'satuan_penyebut' => $kolom === 'patient_days' ? 'hari-rawat' : 'hari-alat',
                    'rate' => $hari > 0 ? round($jumlah / $hari * 1000, 2) : null,
                ];
            });
    }

    /** Kejadian harian — pertanyaan harian_HAIs. */
    public function dailyEvents(string $from, string $until, ?string $unitName = null): Collection
    {
        return $this->eventQuery($from, $until, $unitName)
            ->groupBy('onset_on', 'infection_type')
            ->selectRaw('onset_on, infection_type, count(*) AS jumlah')
            ->orderBy('onset_on')
            ->orderBy('infection_type')
            ->get();
    }

    /** Kejadian bulanan — pertanyaan bulanan_HAIs. */
    public function monthlyEvents(string $from, string $until, ?string $unitName = null): Collection
    {
        return $this->eventQuery($from, $until, $unitName)
            ->groupBy(DB::raw("to_char(onset_on, 'YYYY-MM')"), 'infection_type')
            ->selectRaw("to_char(onset_on, 'YYYY-MM') AS bulan, infection_type, count(*) AS jumlah")
            ->orderBy('bulan')
            ->orderBy('infection_type')
            ->get();
    }

    /** Daftar kejadian untuk ditelaah tim PPI. */
    public function events(string $from, string $until, ?string $unitName = null, int $limit = 200): Collection
    {
        return $this->eventQuery($from, $until, $unitName)
            ->orderByDesc('onset_on')
            ->limit($limit)
            ->get();
    }

    /** Berapa hari pada rentang ini yang penyebutnya belum dicatat sama sekali. */
    public function missingDenominatorDays(string $from, string $until): int
    {
        $hari = (int) DB::selectOne('SELECT (?::date - ?::date) + 1 AS n', [$until, $from])->n;

        $tercatat = (int) DB::table('quality.device_days')
            ->whereBetween('counted_on', [$from, $until])
            ->distinct()
            ->count('counted_on');

        return max(0, $hari - $tercatat);
    }

    /** Bangsal yang punya kejadian tapi penyebutnya belum pernah dicatat. */
    public function unitsWithoutDenominator(string $from, string $until): Collection
    {
        return $this->eventQuery($from, $until, null)
            ->whereNotIn('unit_name', DB::table('quality.device_days')
                ->whereBetween('counted_on', [$from, $until])
                ->select('unit_name'))
            ->distinct()
            ->orderBy('unit_name')
            ->pluck('unit_name');
    }

    /** Builder bersama seluruh potongan kejadian. */
    private function eventQuery(string $from, string $until, ?string $unitName)
    {
        $q = DB::table('quality.infection_events')->whereBetween('onset_on', [$from, $until]);

        if ($unitName !== null && $unitName !== '') {
            $q->where('unit_name', $unitName);
        }

        return $q;
    }

    /** @return array<string,int> */
    private function denominatorTotals(string $from, string $until, ?string $unitName): array
    {
        $q = DB::table('quality.device_days')->whereBetween('counted_on', [$from, $until]);

        if ($unitName !== null && $unitName !== '') {
            $q->where('unit_name', $unitName);
        }

        $row = $q->selectRaw('coalesce(sum(patient_days),0) AS patient_days,
                              coalesce(sum(ventilator_days),0) AS ventilator_days,
                              coalesce(sum(central_line_days),0) AS central_line_days,
                              coalesce(sum(urinary_catheter_days),0) AS urinary_catheter_days,
                              coalesce(sum(peripheral_line_days),0) AS peripheral_line_days')
            ->first();

        return (array) $row;
    }
}
