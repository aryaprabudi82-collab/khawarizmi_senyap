<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penyaji grafik (domain O item A, diperluas item C).
 *
 * SATU LAYANAN UNTUK PULUHAN KODE GRAFIK. Yang membedakan grafik-grafik
 * itu cuma: dataset apa, dikelompokkan menurut apa, pada satuan waktu
 * apa. Ketiganya jadi parameter, dan katalognya yang menentukan mana
 * yang sah — lihat ChartCatalog.
 *
 * ENAM ATURAN.
 *
 * 1. NAMA KOLOM TIDAK PERNAH DATANG DARI PEMANGGIL. Yang diterima cuma
 *    kunci sumbu; kolomnya dicari di katalog. Pada laporan yang dibuka
 *    lewat peramban, "pemanggil" bisa siapa saja.
 *
 * 2. SUMBU YANG TIDAK DIKENAL DITOLAK, BUKAN DIABAIKAN. Grafik yang
 *    diam-diam mengabaikan sumbunya akan menampilkan satu batang berisi
 *    seluruh data dan terbaca sebagai temuan.
 *
 * 3. NILAI KOSONG TIDAK DIBUANG, TAPI DIBERI LABEL. Pasien yang
 *    pekerjaannya tidak tercatat tetap kunjungan yang terjadi;
 *    membuangnya membuat jumlah seluruh batang lebih kecil daripada
 *    jumlah kejadian sebenarnya, dan tidak ada yang tahu selisihnya ke
 *    mana.
 *
 * 4. YANG DIJUMLAHKAN BUKAN SELALU BARIS. Grafik pemakaian air dan
 *    timbulan limbah menanyakan BERAPA BANYAK, bukan berapa kali
 *    dicatat — dan grafik yang menghitung baris akan menampilkan
 *    "jumlah pencatatan" dengan label "pemakaian air", angka yang
 *    tampak masuk akal dan sepenuhnya salah. Dataset menyebut sendiri
 *    kolom yang dijumlahkan; yang tidak menyebut dihitung barisnya.
 *
 * 5. DATASET KONDISI TIDAK DISARING PERIODE. Berapa aset di tiap ruang
 *    adalah keadaan SAAT INI; menyaringnya dengan periode menjawab
 *    pertanyaan yang berbeda — berapa aset yang DIPEROLEH bulan lalu —
 *    dengan judul yang sama. Penyaringan periode pada dataset kondisi
 *    harus diminta sendiri, dan deret waktu atasnya ditolak.
 *
 * 6. KUNJUNGAN BATAL DIKECUALIKAN dari hitungan kunjungan, tapi bisa
 *    digrafikkan sendiri lewat sumbu status — aturan yang sama sejak
 *    domain J item A.
 */
class ChartService
{
    private const PASIEN = 'identity.v_patient_summary';

    public const TIDAK_TERCATAT = 'tidak tercatat';

    /**
     * Deret grafik: satu dataset, satu sumbu, satu periode.
     *
     * @return array<int, array{label: string, value: int|float}>
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
            ->selectRaw($this->measureExpression($isi).' AS value')
            ->groupBy(DB::raw($label))
            ->orderByDesc('value')
            ->limit($limit)
            ->get();

        return $baris->map(fn ($r) => [
            'label' => $r->label,
            'value' => $this->castMeasure($isi, $r->value),
        ])->all();
    }

    /**
     * Deret grafik menurut waktu.
     *
     * @return array<int, array{period: string, value: int|float}>
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
        $isi = $this->datasetOrFail($dataset);

        if (($isi['kind'] ?? ChartCatalog::PERISTIWA) === ChartCatalog::KONDISI) {
            throw new ReportingException(
                "Dataset '{$dataset}' adalah KEADAAN SAAT INI, bukan peristiwa, jadi tidak punya deret "
                .'waktu. Berapa aset di tiap ruang adalah keadaan sekarang; menggrafikkannya menurut '
                .'waktu akan menjawab pertanyaan yang berbeda — berapa aset yang DIPEROLEH tiap bulan — '
                .'dengan judul yang sama.'
            );
        }

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
            ->selectRaw($this->measureExpression($isi).' AS value')
            ->groupBy(DB::raw("date_trunc('{$trunc}', {$kolomTanggal}::timestamp)"))
            ->orderBy('bucket')
            ->get();

        return $baris->map(fn ($r) => [
            'period' => $this->formatPeriod($r->bucket, $granularity),
            'value' => $this->castMeasure($isi, $r->value),
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
    public function total(string $dataset, string $from, string $until, array $filters = []): int|float
    {
        $isi = $this->datasetOrFail($dataset);

        $baris = $this->query($isi, $from, $until, $filters)
            ->selectRaw($this->measureExpression($isi).' AS value')
            ->first();

        return $this->castMeasure($isi, $baris?->value ?? 0);
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
        $this->datasetOrFail($dataset);

        return ChartCatalog::dimensionsFor($dataset);
    }

    /**
     * Dataset ini menjumlahkan sesuatu, bukan menghitung barisnya.
     *
     * @throws ReportingException
     */
    public function isSummed(string $dataset): bool
    {
        return isset($this->datasetOrFail($dataset)['measure']);
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function query(array $dataset, string $from, string $until, array $filters)
    {
        $query = DB::table($dataset['source'].' as r');

        // Dataset KONDISI tidak disaring periode — lihat aturan 5.
        if (($dataset['kind'] ?? ChartCatalog::PERISTIWA) !== ChartCatalog::KONDISI) {
            $kolomTanggal = 'r.'.$dataset['date_column'];
            $query->whereBetween(DB::raw($kolomTanggal.'::date'), [$from, $until]);
        }

        if (isset($dataset['patient_join'])) {
            $query->leftJoin(
                self::PASIEN.' as p',
                'p.id',
                '=',
                'r.'.$dataset['patient_join'],
            );
        }

        foreach ($dataset['joins'] ?? [] as $join) {
            $query->leftJoin(
                $join['table'].' as '.$join['alias'],
                $join['foreign'],
                '=',
                $join['local'],
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
     * @param  array<string, mixed>  $dataset
     */
    private function measureExpression(array $dataset): string
    {
        return isset($dataset['measure'])
            ? "COALESCE(SUM({$dataset['measure']}), 0)"
            : 'COUNT(*)';
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  mixed  $nilai
     */
    /**
     * Hitungan tetap bilangan bulat, penjumlahan tetap pecahan.
     *
     * Mengembalikan pecahan untuk keduanya akan membuat "3 kunjungan"
     * tampil sebagai 3.0 — kecil, tapi angka laporan yang bentuknya
     * berubah tanpa alasan membuat pembacanya ragu apakah ada yang lain
     * ikut berubah.
     *
     * @param  array<string, mixed>  $dataset
     * @param  mixed  $nilai
     */
    private function castMeasure(array $dataset, $nilai): int|float
    {
        return isset($dataset['measure'])
            ? round((float) $nilai, 3)
            : (int) $nilai;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReportingException
     */
    private function datasetOrFail(string $dataset): array
    {
        return ChartCatalog::dataset($dataset)
            ?? throw new ReportingException(
                "Dataset '{$dataset}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(ChartCatalog::datasets())).'.'
            );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     *
     * @throws ReportingException
     */
    private function resolve(string $dataset, string $dimension): array
    {
        $isi = $this->datasetOrFail($dataset);

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
