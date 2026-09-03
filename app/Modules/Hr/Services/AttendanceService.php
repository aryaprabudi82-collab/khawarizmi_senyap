<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendanceRecord;
use App\Modules\Hr\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceService
{
    /**
     * Rekap presensi_bulanan: jumlah hari per status, per pegawai, dalam
     * satu bulan. Bukan tabel baru — agregasi langsung dari attendance_
     * records, konsisten dengan reporting.read-model yang dihitung ulang
     * dari data transaksi, bukan disimpan sebagai baris tersendiri.
     */
    public function monthlyRecap(CarbonImmutable $bulan): Collection
    {
        $awal = $bulan->startOfMonth()->toDateString();
        $akhir = $bulan->endOfMonth()->toDateString();

        $rekap = AttendanceRecord::query()
            ->whereBetween('attendance_date', [$awal, $akhir])
            ->selectRaw('employee_id, status, count(*) as jumlah')
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id');

        return Employee::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Employee $pegawai) use ($rekap) {
                $perStatus = collect($rekap->get($pegawai->id, collect()))
                    ->mapWithKeys(fn ($baris) => [$baris->status => $baris->jumlah]);

                return [
                    'pegawai' => $pegawai,
                    'hadir' => $perStatus->get('hadir', 0),
                    'izin' => $perStatus->get('izin', 0),
                    'sakit' => $perStatus->get('sakit', 0),
                    'alpha' => $perStatus->get('alpha', 0),
                    'cuti' => $perStatus->get('cuti', 0),
                ];
            });
    }

    public function checkIn(int $employeeId, ?int $recordedBy = null): AttendanceRecord
    {
        $tanggal = CarbonImmutable::now()->toDateString();

        $existing = AttendanceRecord::query()
            ->where('employee_id', $employeeId)->where('attendance_date', $tanggal)->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw new HrException('Sudah presensi masuk hari ini.');
        }

        if ($existing !== null) {
            $existing->update(['check_in_at' => now(), 'status' => AttendanceRecord::STATUS_HADIR, 'recorded_by' => $recordedBy]);

            return $existing->refresh();
        }

        return AttendanceRecord::query()->create([
            'employee_id' => $employeeId,
            'attendance_date' => $tanggal,
            'check_in_at' => now(),
            'status' => AttendanceRecord::STATUS_HADIR,
            'recorded_by' => $recordedBy,
        ]);
    }

    public function checkOut(int $employeeId): AttendanceRecord
    {
        $tanggal = CarbonImmutable::now()->toDateString();

        $record = AttendanceRecord::query()
            ->where('employee_id', $employeeId)->where('attendance_date', $tanggal)->first();

        if ($record === null || $record->check_in_at === null) {
            throw new HrException('Belum presensi masuk hari ini.');
        }

        if ($record->check_out_at !== null) {
            throw new HrException('Sudah presensi pulang hari ini.');
        }

        $record->update(['check_out_at' => now()]);

        return $record->refresh();
    }

    /** Entri manual oleh petugas HR — untuk izin/sakit/alpha/cuti yang tidak melalui checkin/checkout. */
    public function recordManual(int $employeeId, string $date, string $status, ?int $recordedBy, ?string $note = null): AttendanceRecord
    {
        return AttendanceRecord::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'attendance_date' => $date],
            ['status' => $status, 'recorded_by' => $recordedBy, 'note' => $note],
        );
    }
}
