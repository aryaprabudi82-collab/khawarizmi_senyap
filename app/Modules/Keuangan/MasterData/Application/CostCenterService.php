<?php

namespace App\Modules\Keuangan\MasterData\Application;

use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pusat biaya & pusat pendapatan — Modul A butir 1.10.
 *
 * EMPAT JENIS, DAN PEMBEDAANNYA MENENTUKAN ARAH ALOKASI BIAYA:
 *
 *   revenue-center  menghasilkan pendapatan; PENERIMA alokasi
 *   cost-center     melayani unit lain; biayanya DIALOKASIKAN
 *   support-center  manajemen; DIALOKASIKAN dengan driver berbeda sifat
 *   program-center  pendidikan & penelitian; DIALOKASIKAN, dan sengaja
 *                   dipisah karena RSP UI berstatus PTN-BH
 *
 * MENGAPA PROGRAM-CENTER DIPISAH. Kalau biaya pendidikan dan penelitian
 * digabung ke support center, ia akan tersebar ke tarif pelayanan lewat
 * alokasi biasa — dan itu berarti pasien ikut membiayai pendidikan tanpa
 * ada yang memutuskannya. Dana pendidikan PTN-BH harus bisa
 * dipertanggungjawabkan terpisah.
 */
class CostCenterService
{
    public const REVENUE = 'revenue-center';

    public const COST = 'cost-center';

    public const SUPPORT = 'support-center';

    public const PROGRAM = 'program-center';

    /** Jenis yang biayanya dialokasikan ke unit lain. */
    public const DIALOKASIKAN = [self::COST, self::SUPPORT, self::PROGRAM];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws KeuanganException
     */
    public function daftarkan(array $data): object
    {
        $kode = strtoupper(trim((string) ($data['code'] ?? '')));
        $jenis = (string) $data['jenis'];

        if ($kode === '') {
            throw new KeuanganException('Kode pusat biaya wajib diisi.');
        }

        if (DB::table('keuangan_master.cost_centers')->where('code', $kode)->exists()) {
            throw new KeuanganException("Kode pusat biaya '{$kode}' sudah dipakai.");
        }

        $driver = $data['cost_driver'] ?? null;

        /*
         * Dua pemeriksaan ini juga ditegakkan CHECK di basis data. Yang di
         * sini ada supaya pesannya MENJELASKAN — galat SQL mentah tidak
         * menolong petugas yang sedang mengisi formulir.
         */
        if ($jenis === self::REVENUE && $driver !== null) {
            throw new KeuanganException(
                'Pusat pendapatan tidak boleh punya cost driver: ia PENERIMA alokasi, bukan '
                .'pemberi. Memberinya driver menyiratkan biayanya dialokasikan lagi ke tempat '
                .'lain — dan alokasi yang berputar tidak pernah selesai dihitung.'
            );
        }

        if (in_array($jenis, self::DIALOKASIKAN, true) && $driver === null) {
            throw new KeuanganException(
                "Pusat berjenis '{$jenis}' wajib punya cost driver — itu dasar pembagian biayanya "
                .'ke unit penerima. Tanpa driver, biayanya tertinggal di sini dan laporan unit cost '
                .'menunjukkan layanan yang jauh lebih murah daripada kenyataannya.'
            );
        }

        if (isset($data['unit_id'])) {
            $this->pastikanUnitAda((int) $data['unit_id']);
        }

        $id = DB::table('keuangan_master.cost_centers')->insertGetId([
            'code' => $kode,
            'name' => $data['name'],
            'jenis' => $jenis,
            'unit_id' => $data['unit_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'cost_driver' => $driver,
            'default_program' => $data['default_program'] ?? null,
            'is_active' => true,
            'valid_from' => $data['valid_from'] ?? now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('keuangan_master.cost_centers')->find($id);
    }

    /**
     * Pusat biaya yang berlaku pada satu tanggal.
     *
     * Berperiode, bukan sekadar aktif/nonaktif: sebuah poli bisa berpindah
     * dari pusat biaya jadi pusat pendapatan saat layanannya mulai
     * ditagihkan, dan laporan tahun lalu harus tetap memakai klasifikasi
     * yang berlaku waktu itu.
     *
     * @return Collection<int, object>
     */
    public function berlakuPada(string $tanggal, ?string $jenis = null): Collection
    {
        return collect(DB::select(
            'SELECT * FROM keuangan_master.cost_centers
              WHERE is_active = true
                AND valid_from <= ?::date
                AND (valid_until IS NULL OR valid_until >= ?::date)
                AND (?::text IS NULL OR jenis = ?::text)
              ORDER BY code',
            [$tanggal, $tanggal, $jenis, $jenis]
        ));
    }

    /**
     * Pusat biaya yang biayanya harus dialokasikan — dasar ABC Wave 7.
     *
     * @return Collection<int, object>
     */
    public function yangDialokasikan(string $tanggal): Collection
    {
        return $this->berlakuPada($tanggal)
            ->filter(fn ($c) => in_array($c->jenis, self::DIALOKASIKAN, true))
            ->values();
    }

    /**
     * Mengubah klasifikasi: yang lama di-EXPIRE, yang baru dibuat.
     *
     * Tidak menimpa, dan itu intinya. Laporan tahun lalu harus tetap
     * memakai klasifikasi yang berlaku waktu itu — menimpanya membuat
     * biaya yang dulu dialokasikan tampak seolah tidak pernah
     * dialokasikan.
     *
     * @throws KeuanganException
     */
    public function ubahKlasifikasi(string $kode, string $jenisBaru, string $berlakuDari, ?string $driverBaru = null): object
    {
        $lama = DB::table('keuangan_master.cost_centers')
            ->where('code', $kode)
            ->whereNull('valid_until')
            ->first();

        if ($lama === null) {
            throw new KeuanganException("Pusat biaya '{$kode}' tidak ditemukan atau sudah di-expire.");
        }

        if ($berlakuDari <= $lama->valid_from) {
            throw new KeuanganException(
                "Klasifikasi baru berlaku {$berlakuDari}, tidak boleh mendahului atau menyamai "
                ."klasifikasi berjalan yang mulai {$lama->valid_from} — periode di antaranya jadi "
                .'tidak punya klasifikasi yang jelas.'
            );
        }

        return DB::transaction(function () use ($lama, $kode, $jenisBaru, $berlakuDari, $driverBaru) {
            DB::table('keuangan_master.cost_centers')
                ->where('id', $lama->id)
                ->update([
                    'valid_until' => date('Y-m-d', strtotime($berlakuDari.' -1 day')),
                    'updated_at' => now(),
                ]);

            /*
             * Kode DIBERI AKHIRAN periode supaya tidak bentrok dengan
             * UNIQUE, sementara kode aslinya tetap terbaca. Alternatifnya
             * melonggarkan UNIQUE jadi (code, valid_from) — tapi itu
             * membuat "cari pusat biaya berkode X" mengembalikan banyak
             * baris di seluruh sistem, dan tiap pemanggil harus ingat
             * menyaring periodenya.
             */
            return $this->daftarkan([
                'code' => $kode.'-'.date('Ym', strtotime($berlakuDari)),
                'name' => $lama->name,
                'jenis' => $jenisBaru,
                'unit_id' => $lama->unit_id,
                'parent_id' => $lama->parent_id,
                'cost_driver' => $driverBaru,
                'default_program' => $lama->default_program,
                'valid_from' => $berlakuDari,
            ]);
        });
    }

    /**
     * Unit organisasi yang BELUM punya pusat biaya.
     *
     * Unit tanpa pusat biaya berarti biayanya tidak masuk perhitungan
     * unit cost mana pun — dan laporan margin per layanan jadi terlalu
     * bagus tanpa ada yang terlihat salah.
     *
     * @return Collection<int, object>
     */
    public function unitBelumTerklasifikasi(): Collection
    {
        return collect(DB::select(
            'SELECT u.id, u.name
               FROM organization.v_unit_summary u
              WHERE NOT EXISTS (
                    SELECT 1 FROM keuangan_master.cost_centers c
                     WHERE c.unit_id = u.id AND c.valid_until IS NULL AND c.is_active = true
              )
              ORDER BY u.name'
        ));
    }

    /** @throws KeuanganException */
    private function pastikanUnitAda(int $unitId): void
    {
        $ada = DB::table('organization.v_unit_summary')->where('id', $unitId)->exists();

        if (! $ada) {
            throw new KeuanganException("Unit #{$unitId} tidak ditemukan atau tidak aktif.");
        }
    }
}
