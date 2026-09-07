<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\DrugInformationRequest;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pelayanan informasi obat (domain M item I).
 *
 * TIGA ATURAN.
 *
 * 1. PERTANYAAN TIDAK SELALU TENTANG SATU PASIEN. Khanza memasang
 *    no_rawat NOT NULL pada pelayanan_informasi_obat padahal penanyanya
 *    boleh petugas kesehatan yang bertanya soal stabilitas sediaan atau
 *    interaksi dua obat tanpa ada pasien tertentu di hadapannya.
 *    Memaksanya menempel pada satu kunjungan berarti pertanyaan
 *    semacam itu dicatatkan ke kunjungan pasien yang kebetulan ada —
 *    dan sejak itu rekam medis pasien tersebut memuat pertanyaan yang
 *    bukan tentang dirinya.
 *
 * 2. JAWABAN KLINIS WAJIB MENYEBUT RUJUKAN. Jawaban informasi obat
 *    tanpa sumber pustaka adalah pendapat, dan pendapat tidak bisa
 *    ditelusuri ulang saat kemudian diragukan. Pengecualiannya
 *    pertanyaan administratif — harga dan ketersediaan — yang sumbernya
 *    memang sistem kita sendiri, dan menuntut pustaka untuk "obat ini
 *    ada stoknya tidak" hanya melahirkan rujukan yang diarang.
 *
 * 3. LAMA JAWABAN DIHITUNG, TIDAK DISIMPAN. Padanan
 *    penyampaian_jawaban Khanza ada di
 *    DrugInformationRequest::responseBracket(), diturunkan dari waktu
 *    bertanya dan waktu menjawab yang dua-duanya sudah tercatat.
 */
class DrugInformationService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Pertanyaan yang jawabannya bersumber dari sistem kita sendiri,
     * bukan dari pustaka. Lihat aturan 2 pada catatan kelas.
     */
    private const ADMINISTRATIF = ['harga-obat', 'ketersediaan-obat'];

    /**
     * Mencatat pertanyaan.
     *
     * @throws ClinicalException
     */
    public function ask(array $data, ?User $actor = null): DrugInformationRequest
    {
        $penanya = trim($data['asker_name'] ?? '');

        if ($penanya === '') {
            throw new ClinicalException('Nama penanya wajib diisi.');
        }

        $pertanyaan = trim($data['question'] ?? '');

        if ($pertanyaan === '') {
            throw new ClinicalException('Uraian pertanyaan wajib diisi.');
        }

        $jenis = $data['question_kind'] ?? '';

        if (! array_key_exists($jenis, DrugInformationRequest::JENIS)) {
            throw new ClinicalException(
                "Jenis pertanyaan '{$jenis}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(DrugInformationRequest::JENIS)).'.'
            );
        }

        $keterangan = trim($data['question_kind_note'] ?? '');

        if ($jenis === DrugInformationRequest::LAIN_LAIN && $keterangan === '') {
            throw new ClinicalException(
                'Keterangan jenis wajib diisi saat jenis pertanyaannya "lain-lain". Tanpa itu '
                .'"lain-lain" menjadi keranjang yang tidak bisa dibaca kembali saat menyusun laporan.'
            );
        }

        $metode = $data['method'] ?? '';

        if (! array_key_exists($metode, DrugInformationRequest::METODE)) {
            throw new ClinicalException("Metode '{$metode}' tidak dikenali.");
        }

        $sifatPenanya = $data['asker_kind'] ?? '';

        if (! in_array($sifatPenanya, ['pasien', 'keluarga-pasien', 'petugas-kesehatan'], true)) {
            throw new ClinicalException("Status penanya '{$sifatPenanya}' tidak dikenali.");
        }

        $kunjungan = isset($data['registration_id'])
            ? DB::table(self::REGISTRASI)->where('id', $data['registration_id'])->first()
                ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.')
            : null;

        return DrugInformationRequest::query()->create([
            'request_number' => $this->allocateNumber(),
            'registration_id' => $kunjungan?->id,
            'patient_id' => $kunjungan?->patient_id,
            'patient_name' => $kunjungan?->patient_name,
            'asked_at' => $data['asked_at'] ?? now(),
            'method' => $metode,
            'asker_name' => $penanya,
            'asker_kind' => $sifatPenanya,
            'asker_phone' => $data['asker_phone'] ?? null,
            'question_kind' => $jenis,
            'question_kind_note' => $keterangan !== '' ? $keterangan : null,
            'question' => $pertanyaan,
            'status' => DrugInformationRequest::TERBUKA,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Menjawab pertanyaan.
     *
     * @throws ClinicalException
     */
    public function answer(
        DrugInformationRequest $request,
        string $answer,
        array $data = [],
        ?User $actor = null,
    ): DrugInformationRequest {
        if ($request->status === DrugInformationRequest::DIJAWAB) {
            throw new ClinicalException('Pertanyaan ini sudah dijawab.');
        }

        if ($request->status === DrugInformationRequest::DIBATALKAN) {
            throw new ClinicalException('Pertanyaan yang dibatalkan tidak bisa dijawab.');
        }

        $isi = trim($answer);

        if ($isi === '') {
            throw new ClinicalException('Jawaban wajib diisi.');
        }

        $rujukan = trim($data['reference'] ?? '');

        if ($rujukan === '' && ! in_array($request->question_kind, self::ADMINISTRATIF, true)) {
            throw new ClinicalException(
                'Rujukan pustaka wajib disebut untuk pertanyaan klinis. Jawaban informasi obat tanpa '
                .'sumber adalah pendapat, dan pendapat tidak bisa ditelusuri ulang saat diragukan.'
            );
        }

        $metode = $data['answer_method'] ?? $request->method;

        if (! array_key_exists($metode, DrugInformationRequest::METODE)) {
            throw new ClinicalException("Metode penyampaian jawaban '{$metode}' tidak dikenali.");
        }

        $penjawab = trim($data['answered_by_name'] ?? $actor?->name ?? '');

        if ($penjawab === '') {
            throw new ClinicalException(
                'Nama apoteker penjawab wajib disebut: informasi obat yang diberikan adalah tanggung '
                .'jawab yang memberikannya.'
            );
        }

        $request->update([
            'answered_at' => $data['answered_at'] ?? now(),
            'answer_method' => $metode,
            'answer' => $isi,
            'reference' => $rujukan !== '' ? $rujukan : null,
            'answered_by' => $actor?->id,
            'answered_by_name' => $penjawab,
            'status' => DrugInformationRequest::DIJAWAB,
        ]);

        return $request->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(DrugInformationRequest $request, string $reason): DrugInformationRequest
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($request->status === DrugInformationRequest::DIBATALKAN) {
            throw new ClinicalException('Pertanyaan ini sudah dibatalkan.');
        }

        $request->update([
            'status' => DrugInformationRequest::DIBATALKAN,
            'question_kind_note' => trim(
                ($request->question_kind_note ? $request->question_kind_note.' ' : '')."[Dibatalkan: {$alasan}]"
            ),
        ]);

        return $request->refresh();
    }

    // ---------------------------------------------------------------- baca

    /** Pertanyaan yang belum dijawab, yang paling lama menunggu di depan. */
    public function unanswered(): Collection
    {
        return DrugInformationRequest::query()
            ->where('status', DrugInformationRequest::TERBUKA)
            ->orderBy('asked_at')
            ->get();
    }

    /**
     * Rekap kecepatan jawaban sepanjang satu periode.
     *
     * Kategorinya DIHITUNG dari timestamp tiap baris, bukan dibaca dari
     * kolom — itulah alasan kolomnya tidak ada.
     *
     * @return array<string, int>
     */
    public function responseRecap(string $from, string $until): array
    {
        $rekap = ['segera' => 0, 'dalam-24-jam' => 0, 'lebih-dari-24-jam' => 0];

        DrugInformationRequest::query()
            ->where('status', DrugInformationRequest::DIJAWAB)
            ->whereBetween('asked_at', [$from, $until])
            ->get()
            ->each(function ($permintaan) use (&$rekap) {
                $kategori = $permintaan->responseBracket();

                if ($kategori !== null) {
                    $rekap[$kategori]++;
                }
            });

        return $rekap;
    }

    private function allocateNumber(): string
    {
        $prefix = now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO clinical.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = clinical.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'PIO'.$prefix.'-'.str_pad((string) $row->last_number, 4, '0', STR_PAD_LEFT);
    }
}
