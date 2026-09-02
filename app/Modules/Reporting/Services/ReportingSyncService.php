<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Models\DailyRevenueSummary;
use App\Modules\Reporting\Models\DailyVisitSummary;
use App\Modules\Reporting\Models\DiagnosisFrequency;
use Carbon\CarbonInterface;

/**
 * Menghitung ulang read model reporting untuk satu tanggal, dari view yang
 * diterbitkan encounter/clinical/billing/catalog. Aman dijalankan berkali-
 * kali untuk tanggal yang sama — tiap kelompok ditimpa (updateOrCreate),
 * bukan ditambah, jadi menjalankannya ulang di tengah hari (kunjungan baru
 * masuk) mengoreksi angkanya, bukan menggandakannya.
 *
 * Dipanggil manual dari layar dashboard (tombol "Sinkronkan Hari Ini") pada
 * Wave 1 ini; produksi semestinya menjadwalkannya lewat Laravel Scheduler
 * (lihat Console/Commands/SyncReportingCommand) supaya rekap H-1 selalu
 * siap tanpa menunggu seseorang membuka dashboard.
 */
class ReportingSyncService
{
    public function __construct(
        private readonly RegistrationContext $registrations,
        private readonly PayerContext $payers,
        private readonly DiagnosisContext $diagnoses,
        private readonly InvoiceContext $invoices,
    ) {}

    /** @return array{kunjungan: int, diagnosis: int, pendapatan: int} Jumlah kelompok yang disinkronkan per bagian. */
    public function syncDay(CarbonInterface $date): array
    {
        return [
            'kunjungan' => $this->syncVisits($date),
            'diagnosis' => $this->syncDiagnoses($date),
            'pendapatan' => $this->syncRevenue($date),
        ];
    }

    public function syncVisits(CarbonInterface $date): int
    {
        $kindsById = $this->payers->kindsById();
        $groups = [];

        foreach ($this->registrations->forDate($date) as $r) {
            $kind = $kindsById[$r->payer_id] ?? 'lainnya';
            $key = $r->unit_id . '|' . $kind;

            $groups[$key] ??= ['unit_id' => $r->unit_id, 'unit_name' => $r->unit_name, 'payer_kind' => $kind, 'count' => 0];
            $groups[$key]['count']++;
        }

        foreach ($groups as $g) {
            DailyVisitSummary::query()->updateOrCreate(
                ['report_date' => $date->toDateString(), 'unit_id' => $g['unit_id'], 'payer_kind' => $g['payer_kind']],
                ['unit_name' => $g['unit_name'], 'visit_count' => $g['count'], 'synced_at' => now()],
            );
        }

        return count($groups);
    }

    public function syncDiagnoses(CarbonInterface $date): int
    {
        $groups = [];

        foreach ($this->diagnoses->forDate($date) as $d) {
            $groups[$d->code] ??= ['code' => $d->code, 'display' => $d->display, 'count' => 0];
            $groups[$d->code]['count']++;
        }

        foreach ($groups as $g) {
            DiagnosisFrequency::query()->updateOrCreate(
                ['report_date' => $date->toDateString(), 'code' => $g['code']],
                ['display' => $g['display'], 'occurrence_count' => $g['count'], 'synced_at' => now()],
            );
        }

        return count($groups);
    }

    public function syncRevenue(CarbonInterface $date): int
    {
        $groups = [];

        foreach ($this->invoices->forDate($date) as $i) {
            $groups[$i->payer_kind] ??= ['payer_kind' => $i->payer_kind, 'total' => 0.0, 'count' => 0];
            $groups[$i->payer_kind]['total'] += (float) $i->total_amount;
            $groups[$i->payer_kind]['count']++;
        }

        foreach ($groups as $g) {
            DailyRevenueSummary::query()->updateOrCreate(
                ['report_date' => $date->toDateString(), 'payer_kind' => $g['payer_kind']],
                ['total_amount' => $g['total'], 'invoice_count' => $g['count'], 'synced_at' => now()],
            );
        }

        return count($groups);
    }
}
