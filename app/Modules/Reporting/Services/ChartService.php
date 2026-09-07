<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penyaji grafik (domain O item A).
 *
 * SATU LAYANAN UNTUK 113 KODE GRAFIK. Yang membedakan grafik-grafik itu
 * cuma tiga hal: dataset apa, dikelompokkan menurut apa, dan pada satuan
 * waktu apa. Ketiganya jadi parameter, dan katalognya yang menentukan
 * mana yang sah — lihat ChartCatalog.
 *
 * EMPAT ATURAN.
 *
 * 1. NAMA KOLOM TIDAK PERNAH DATANG DARI PEMANGGIL. Yang diterima cuma
 *    kunci sumbu; kolomnya dicari di katalog. Menerima nama kolom dari
 *    luar berarti membiarkan pemanggil memilih kolom mana pun dari view
 *    yang diterbitkan — dan pada laporan yang dibuka lewat peramban,
 *    "pemanggil" bisa siapa saja.
 *
 * 2. SUMBU YANG TIDAK DIKENAL DITOLAK, BUKAN DIABAIKAN. Grafik yang
 *    diam-diam mengabaikan sumbunya akan menampilkan satu batang berisi
 *    seluruh data dan terbaca sebagai temuan.
 *
 * 3. NILAI KOSONG TIDAK DIBUANG, TAPI DIBERI LABEL. Pasien yang
 *    pekerjaannya tidak tercatat tetap kunjungan yang terjadi;
 *    membuangnya membuat jumlah seluruh batang lebih kecil daripada
 *    jumlah kunjungan sebenarnya, dan tidak ada yang tahu selisihnya ke
 *    mana. Labelnya "tidak tercatat" — bukan dikosongkan, bukan
 *    dihilangkan.
 *
 * 4. KUNJUNGAN BATAL DIKECUALIKAN dari hitungan kunjungan, tapi bisa
 *    digrafikkan sendiri lewat sumbu status. Aturan yang sama sudah
 *    berlaku sejak domain J item A: kunjungan batal bukan kunjungan,
 *    tapi jumlahnya sendiri adalah informasi yang dicari.
 */
class ChartService
{
    private const PASIEN = 'identity.v_patient_summary';

    public const TIDAK_TERCATAT = 'tidak tercatat';

    /**
     * Deret grafik: satu dataset, satu sumbu, satu periode.
     *
     * @return array<int, array{label: string, value: int}>
     *
     * @throws ReportingException
     */
    public function breakdown(
        string $dataset,
        string $dimension,
        string $from,
        string $until,
        array $filters = [],
        int $limit = 50,
    ): array {
        [$isi, $sumbu] = $this->resolve($dataset, $dimension);

        /*
         * DIKELOMPOKKAN MENURUT EKSPRESI YANG SAMA DENGAN LABELNYA,
         * bukan menurut kolom mentahnya. Mengelompokkan kolom mentah
         * sementara melabelinya dengan ekspresi yang sudah dinormalkan
         * menghasilkan DUA baris berlabel "tidak tercatat" — satu untuk
         * NULL, satu untuk string berisi spasi — dan pembacanya cuma
         * melihat salah satunya. Ini kekeliruan yang benar-benar terjadi
         * di sini dan tertangkap pengujian.
         */
        $label = "COALESCE(NULLIF(btrim({$sumbu['column']}::text), ''), '".self::TIDAK_TERCATAT."')";

        $baris = $this->query($isi, $from, $until, $filters)
            ->selectRaw("{$label} AS label")
            ->selectRaw('COUNT(*) AS value')
            ->groupBy(DB::raw($label))
            ->orderByDesc('value')
            ->limit($limit)
            ->get();

        return $baris->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value])->all();
    }

    /**
     * Deret grafik menurut waktu.
     *
     * @return array<int, array{period: string, value: int}>
     *
     * @throws ReportingException
     */
    public function overTime(
        string $dataset,
        string $granularity,
        string $from,
        string $until,
        array $filters = [],
    ): array {
        $isi = ChartCatalog::dataset($dataset)
            ?? throw new ReportingException("Dataset '{$dataset}' tidak dikenali.");

        if (! array_key_exists($granularity, ChartCatalog::GRANULARITAS)) {
            throw new ReportingException(
                "Satuan waktu '{$granularity}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(ChartCatalog::GRANULARITAS)).'.'
            );
        }

        $trunc = ChartCatalog::GRANULARITAS[$granularity]['trunc'];
        $kolomTanggal = 'r.'.$isi['date_column'];

        $baris = $this->query($isi, $from, $until, $filters)
            ->selectRaw("date_trunc('{$trunc}', {$kolomTanggal}::timestamp) AS bucket")
            ->selectRaw('COUNT(*) AS value')
            ->groupBy(DB::raw("date_trunc('{$trunc}', {$kolomTanggal}::timestamp)"))
            ->orderBy('bucket')
            ->get();

        return $baris->map(fn ($r) => [
            'period' => $this->formatPeriod($r->bucket, $granularity),
            'value' => (int) $r->value,
        ])->all();
    }

    /**
     * Jumlah seluruhnya pada periode yang sama.
     *
     * Disediakan justru supaya selisihnya terhadap jumlah batang bisa
     * diperiksa: grafik yang batangnya dipotong limit tidak boleh
     * terbaca seolah itu seluruh kejadiannya.
     *
     * @throws ReportingException
     */
    public function total(string $dataset, string $from, string $until, array $filters = []): int
    {
        $isi = ChartCatalog::dataset($dataset)
            ?? throw new ReportingException("Dataset '{$dataset}' tidak dikenali.");

        return (int) $this->query($isi, $from, $until, $filters)->count();
    }

    /**
     * Sumbu yang tersedia untuk sebuah dataset.
     *
     * @return array<string, string>
     *
     * @throws ReportingException
     */
    public function availableDimensions(string $dataset): array
    {
        ChartCatalog::dataset($dataset)
            ?? throw new ReportingException("Dataset '{$dataset}' tidak dikenali.");

        return ChartCatalog::dimensionsFor($dataset);
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function query(array $dataset, string $from, string $until, array $filters)
    {
        $kolomTanggal = 'r.'.$dataset['date_column'];

        $query = DB::table($dataset['source'].' as r')
            ->whereBetween(DB::raw($kolomTanggal.'::date'), [$from, $until]);

        if (isset($dataset['patient_join'])) {
            $query->leftJoin(
                self::PASIEN.' as p',
                'p.id',
                '=',
                'r.'.$dataset['patient_join'],
            );
        }

        // Penyaring pun hanya boleh menyebut sumbu yang dikenal katalog:
        // pintu yang sama, kunci yang sama.
        foreach ($filters as $kunci => $nilai) {
            if ($nilai === null || $nilai === '') {
                continue;
            }

            $sumbu = $dataset['dimensions'][$kunci] ?? null;

            if ($sumbu === null) {
                throw new ReportingException(
                    "Penyaring '{$kunci}' bukan sumbu yang dikenal dataset ini. Pilihannya: "
                    .implode(', ', array_keys($dataset['dimensions'])).'.'
                );
            }

            $query->whereRaw("{$sumbu['column']}::text = ?", [$nilai]);
        }

        return $query;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     *
     * @throws ReportingException
     */
    private function resolve(string $dataset, string $dimension): array
    {
        $isi = ChartCatalog::dataset($dataset)
            ?? throw new ReportingException(
                "Dataset '{$dataset}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(ChartCatalog::datasets())).'.'
            );

        $sumbu = $isi['dimensions'][$dimension] ?? null;

        if ($sumbu === null) {
            throw new ReportingException(
                "Sumbu '{$dimension}' tidak sah untuk dataset '{$dataset}'. Pilihannya: "
                .implode(', ', array_keys($isi['dimensions'])).'. Sumbu yang tidak dikenal DITOLAK, '
                .'bukan diabaikan — grafik yang diam-diam mengabaikan sumbunya akan menampilkan satu '
                .'batang berisi seluruh data dan terbaca sebagai temuan.'
            );
        }

        return [$isi, $sumbu];
    }

    private function formatPeriod(string $bucket, string $granularity): string
    {
        $waktu = Carbon::parse($bucket);

        return match ($granularity) {
            ChartCatalog::TAHUNAN => $waktu->format('Y'),
            ChartCatalog::BULANAN => $waktu->format('Y-m'),
            default => $waktu->toDateString(),
        };
    }
}
