<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perawatan partisi bulanan.
 *
 * MENGAPA INI ADA. Empat tabel terbesar sistem ini dipartisi per bulan —
 * billing.charge_lines, clinical.observations, pharmacy.stock_movements,
 * platform.audit_logs — tapi partisinya dibuat SEKALI saat migrasi, sebanyak
 * 24 bulan ke depan, lalu satu partisi DEFAULT sebagai penampung.
 *
 * Pada RSP UI dengan 2.000 pasien sehari, keempatnya tumbuh jutaan baris per
 * tahun. Begitu bulan ke-25 tiba dan tidak ada yang menambah partisi,
 * seluruh baris baru jatuh ke DEFAULT — dan yang terjadi bukan galat,
 * melainkan sistem yang pelan-pelan melambat tanpa ada yang tahu sebabnya:
 * DEFAULT jadi satu tabel raksasa tanpa partisi, dan setiap kueri yang
 * seharusnya menyentuh satu bulan mulai memindai seluruh riwayat.
 *
 * YANG MEMBUATNYA SULIT DIPERBAIKI BELAKANGAN: begitu ada baris September
 * 2028 di DEFAULT, PostgreSQL MENOLAK pembuatan partisi untuk September 2028
 * — ia harus memindai DEFAULT dan menemukan baris yang bentrok. Memperbaiki-
 * nya menuntut melepas DEFAULT, memindahkan barisnya, lalu memasang ulang;
 * seluruhnya sambil mengunci tabel yang paling sibuk di rumah sakit. Karena
 * itu pekerjaannya harus dilakukan SEBELUM barisnya datang, bukan sesudah.
 *
 * DAFTAR TABELNYA DITEMUKAN DARI KATALOG, TIDAK DITULIS TANGAN. Daftar yang
 * ditulis tangan akan benar hari ini dan diam-diam salah pada tabel
 * berpartisi kelima yang ditambahkan orang lain tahun depan — dan
 * kesalahannya baru terasa dua tahun kemudian, persis saat tidak ada yang
 * ingat daftar ini pernah ada.
 */
class PartitionManager
{
    /**
     * Berapa bulan ke depan yang harus selalu tersedia.
     *
     * Tiga bulan, bukan satu: kalau perawatan terjadwal gagal berjalan —
     * server mati semalam, cron dimatikan saat pemeliharaan, kredensial
     * kedaluwarsa — masih ada dua bulan lagi sebelum barisnya jatuh ke
     * DEFAULT. Satu bulan runway berarti satu malam gagal langsung jadi
     * kerusakan yang mahal diperbaiki.
     */
    public const RUNWAY_BULAN = 3;

    /** Ambang peringatan: di bawah ini, pemeriksaan kesehatan berteriak. */
    public const RUNWAY_MINIMAL = 2;

    /**
     * Seluruh tabel berpartisi RANGE per bulan, ditemukan dari katalog.
     *
     * @return list<array{induk: string, schema: string, tabel: string, kolom: string}>
     */
    public function partitionedTables(): array
    {
        $rows = DB::select("
            select n.nspname                            as schema,
                   c.relname                            as tabel,
                   pg_get_partkeydef(c.oid)             as kunci
              from pg_class c
              join pg_namespace n on n.oid = c.relnamespace
             where c.relkind = 'p'
               and n.nspname not in ('pg_catalog', 'information_schema')
             order by n.nspname, c.relname
        ");

        $hasil = [];

        foreach ($rows as $r) {
            // Hanya RANGE berkolom tunggal yang bisa dirawat otomatis di
            // sini. Bentuk lain (LIST, HASH, ekspresi majemuk) sengaja
            // dilewati dan DILAPORKAN, bukan didiamkan — lihat unmanaged().
            if (! preg_match('/^RANGE \(([a-z0-9_]+)\)$/i', trim($r->kunci), $m)) {
                continue;
            }

            $hasil[] = [
                'induk' => $r->schema.'.'.$r->tabel,
                'schema' => $r->schema,
                'tabel' => $r->tabel,
                'kolom' => $m[1],
            ];
        }

        return $hasil;
    }

    /**
     * Tabel berpartisi yang TIDAK bisa dirawat kelas ini.
     *
     * Daftar kejujuran: kalau suatu hari ada yang membuat partisi LIST atau
     * HASH, ia tidak akan ikut terawat — dan itu harus terlihat, bukan
     * tersembunyi di balik laporan yang menyatakan semuanya beres.
     *
     * @return list<string>
     */
    public function unmanaged(): array
    {
        $rows = DB::select("
            select n.nspname || '.' || c.relname as induk, pg_get_partkeydef(c.oid) as kunci
              from pg_class c
              join pg_namespace n on n.oid = c.relnamespace
             where c.relkind = 'p'
               and n.nspname not in ('pg_catalog', 'information_schema')
        ");

        $hasil = [];

        foreach ($rows as $r) {
            if (! preg_match('/^RANGE \([a-z0-9_]+\)$/i', trim($r->kunci))) {
                $hasil[] = $r->induk.' — '.$r->kunci;
            }
        }

        return $hasil;
    }

    /**
     * Bulan terakhir yang sudah punya partisi, untuk satu tabel.
     *
     * Dibaca dari batas partisinya sendiri (relpartbound), bukan dari nama
     * partisinya: nama cuma kesepakatan, batas adalah kenyataan. Partisi
     * yang dinamai keliru tapi berbatas benar tetap terhitung; yang dinamai
     * benar tapi berbatas keliru tidak.
     */
    public function lastCoveredMonth(string $induk): ?Carbon
    {
        $rows = DB::select("
            select pg_get_expr(c.relpartbound, c.oid) as batas
              from pg_inherits i
              join pg_class c on c.oid = i.inhrelid
              join pg_class p on p.oid = i.inhparent
              join pg_namespace n on n.oid = p.relnamespace
             where n.nspname || '.' || p.relname = ?
        ", [$induk]);

        $terakhir = null;

        foreach ($rows as $r) {
            if (! preg_match("/TO \('([^']+)'\)/", (string) $r->batas, $m)) {
                continue;   // DEFAULT tidak punya batas atas
            }

            $batasAtas = Carbon::parse($m[1]);

            if ($terakhir === null || $batasAtas->greaterThan($terakhir)) {
                $terakhir = $batasAtas;
            }
        }

        // Batas ATAS eksklusif, jadi bulan terakhir yang tercakup adalah
        // sebulan sebelumnya.
        return $terakhir?->copy()->subMonth()->startOfMonth();
    }

    /**
     * Berapa bulan penuh lagi tersedia sejak bulan ini.
     *
     * 0 berarti bulan ini adalah yang terakhir tercakup — sudah gawat,
     * bukan "masih aman satu bulan".
     */
    public function runwayMonths(string $induk): int
    {
        $terakhir = $this->lastCoveredMonth($induk);

        if ($terakhir === null) {
            return 0;
        }

        $sekarang = Carbon::now()->startOfMonth();

        return max(0, (int) $sekarang->diffInMonths($terakhir, false));
    }

    /**
     * Memastikan setiap tabel berpartisi punya runway yang cukup.
     *
     * Idempoten: partisi yang sudah ada dilewati, jadi menjalankannya
     * berkali-kali sehari tidak melakukan apa pun kecuali membaca katalog.
     *
     * @return list<string> nama partisi yang dibuat
     */
    public function ensureRunway(?int $bulan = null): array
    {
        $bulan ??= self::RUNWAY_BULAN;
        $dibuat = [];

        foreach ($this->partitionedTables() as $t) {
            $terakhir = $this->lastCoveredMonth($t['induk']);

            // Tabel berpartisi tanpa satu pun partisi rentang: mulai dari
            // bulan ini.
            $mulai = $terakhir === null
                ? Carbon::now()->startOfMonth()
                : $terakhir->copy()->addMonth();

            $target = Carbon::now()->startOfMonth()->addMonths($bulan);

            while ($mulai->lessThanOrEqualTo($target)) {
                $dibuat[] = $this->createMonthPartition($t, $mulai);
                $mulai->addMonth();
            }
        }

        return array_values(array_filter($dibuat));
    }

    /**
     * Membuat satu partisi bulanan.
     *
     * Memakai IF NOT EXISTS supaya dua proses perawatan yang kebetulan
     * berjalan bersamaan tidak saling menggagalkan.
     */
    private function createMonthPartition(array $t, Carbon $bulan): ?string
    {
        $dari = $bulan->copy()->startOfMonth();
        $sampai = $dari->copy()->addMonth();
        $nama = $t['tabel'].'_'.$dari->format('Y_m');
        $penuh = $t['schema'].'.'.$nama;

        $sudahAda = DB::selectOne(
            'select 1 as ada from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = ? and c.relname = ?',
            [$t['schema'], $nama]
        );

        if ($sudahAda !== null) {
            return null;
        }

        try {
            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                $penuh, $t['induk'], $dari->toDateString(), $sampai->toDateString()
            ));
        } catch (\Throwable $e) {
            /*
             * Kegagalan yang paling mungkin, dan yang paling perlu dibaca
             * manusia: sudah ada baris untuk bulan itu di partisi DEFAULT,
             * jadi PostgreSQL menolak. Pesan aslinya menyebut "would be
             * violated by some row" tanpa memberitahu apa yang harus
             * dilakukan — padahal yang harus dilakukan spesifik dan berat.
             */
            throw new RuntimeException(
                'Gagal membuat partisi '.$penuh.'. Kemungkinan besar sudah ada baris '
                .$dari->format('F Y').' yang telanjur masuk ke partisi DEFAULT — dan '
                .'PostgreSQL menolak membuat partisi rentang untuk tanggal yang sudah '
                ."dihuni DEFAULT.\n\n"
                .'Perbaikannya BUKAN mengulang perintah ini: DEFAULT harus dilepas, '
                .'barisnya dipindahkan ke partisi yang benar, lalu dipasang ulang — '
                .'sambil mengunci tabel. Kerjakan di luar jam layanan, dan setelah itu '
                ."pastikan perawatan terjadwal benar-benar berjalan.\n\n"
                .'Galat asli: '.$e->getMessage(),
                0,
                $e
            );
        }

        return $penuh;
    }

    /**
     * Ringkasan kesehatan partisi untuk pemeriksaan terjadwal & layar admin.
     *
     * @return array{sehat: bool, tabel: list<array<string, mixed>>, takTerawat: list<string>}
     */
    public function health(): array
    {
        $tabel = [];
        $sehat = true;

        foreach ($this->partitionedTables() as $t) {
            $runway = $this->runwayMonths($t['induk']);
            $baris = $this->defaultPartitionRows($t);

            $aman = $runway >= self::RUNWAY_MINIMAL && $baris === 0;
            $sehat = $sehat && $aman;

            $tabel[] = [
                'induk' => $t['induk'],
                'kolom' => $t['kolom'],
                'runway_bulan' => $runway,
                'baris_default' => $baris,
                'aman' => $aman,
            ];
        }

        return [
            'sehat' => $sehat && $this->unmanaged() === [],
            'tabel' => $tabel,
            'takTerawat' => $this->unmanaged(),
        ];
    }

    /**
     * Jumlah baris yang sudah telanjur masuk partisi DEFAULT.
     *
     * Bukan nol berarti kerusakan SUDAH terjadi, bukan akan terjadi — dan
     * angkanya menentukan seberapa berat perbaikannya.
     */
    public function defaultPartitionRows(array $t): int
    {
        $nama = $t['schema'].'.'.$t['tabel'].'_default';

        $ada = DB::selectOne(
            'select 1 as ada from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = ? and c.relname = ?',
            [$t['schema'], $t['tabel'].'_default']
        );

        if ($ada === null) {
            return 0;
        }

        return (int) DB::selectOne('select count(*) as c from '.$nama)->c;
    }
}
