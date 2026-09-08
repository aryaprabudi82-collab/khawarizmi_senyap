<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\LetterClassification;
use App\Modules\Correspondence\Models\LetterDisposition;
use App\Modules\Correspondence\Models\LetterLocation;
use App\Modules\Correspondence\Models\OutgoingLetter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LetterService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly LetterNumberAllocator $letterNumbers,
    ) {}

    public function recordIncoming(array $data, int $recordedBy): IncomingLetter
    {
        $this->assertPenyimpananSah($data);
        $this->assertTenggatBalasSah($data);

        return IncomingLetter::query()->create($data + [
            'letter_number' => $this->numbers->allocate('SM'),
            'status' => IncomingLetter::STATUS_DITERIMA,

            /*
             * Ditulis tegas, tidak diserahkan ke nilai bawaan kolom: model
             * hasil create() TIDAK memuat nilai bawaan basis data, jadi
             * pemeriksaan berikutnya membaca null dan mengira surat ini belum
             * ditandai apa-apa. Uji "surat yang tidak perlu dibalas tidak
             * bisa ditandai dibalas" menangkap persis itu — penjaganya lolos
             * bukan karena aturannya keliru, melainkan karena atributnya
             * kosong.
             */
            'reply_status' => IncomingLetter::BALAS_TIDAK_PERLU,
            'recorded_by' => $recordedBy,
        ]);
    }

    /**
     * Disposisi berturut-turut, bukan satu tujuan.
     *
     * Satu surat masuk lazim didisposisikan berjenjang: direktur ke kepala
     * bidang, kepala bidang ke pelaksana. Kolom tunggal `forwarded_to`
     * hanya bisa menyimpan yang terakhir, dan menghapus jejak siapa
     * meneruskan kepada siapa.
     */
    public function dispose(IncomingLetter $letter, array $data, ?int $disposedBy = null): LetterDisposition
    {
        if ($letter->status === IncomingLetter::STATUS_DIARSIPKAN) {
            throw new CorrespondenceException('Surat yang sudah diarsipkan tidak bisa didisposisikan lagi.');
        }

        if (blank($data['instruction'] ?? null)) {
            throw new CorrespondenceException(
                'Disposisi harus menyebutkan apa yang diminta dikerjakan — disposisi tanpa isi '.
                'hanya memindahkan kertas.'
            );
        }

        return DB::transaction(function () use ($letter, $data, $disposedBy) {
            $urut = (int) $letter->dispositions()->max('sequence') + 1;

            $disposisi = $letter->dispositions()->create($data + [
                'sequence' => $urut,
                'disposed_by' => $disposedBy,
            ]);

            // Kolom lama tetap diperbarui supaya layar dan laporan yang sudah
            // ada tidak mendadak kosong; ia kini cache disposisi TERAKHIR,
            // bukan satu-satunya catatan.
            $letter->update([
                'status' => IncomingLetter::STATUS_DIDISPOSISIKAN,
                'forwarded_to' => $data['to_name'] ?? $letter->forwarded_to,
            ]);

            return $disposisi;
        });
    }

    public function completeDisposition(LetterDisposition $disposition, ?string $catatan = null): LetterDisposition
    {
        if ($disposition->completed_at !== null) {
            throw new CorrespondenceException('Disposisi ini sudah ditandai selesai.');
        }

        $disposition->update(['completed_at' => now(), 'completion_note' => $catatan]);

        return $disposition->refresh();
    }

    /**
     * Disposisi yang tenggatnya lewat dan belum selesai.
     *
     * DIHITUNG dari tenggat, tidak disimpan sebagai status. Disposisi yang
     * tidak bisa dilaporkan terlambat tidak pernah ditagih siapa pun.
     *
     * @return Collection<int, LetterDisposition>
     */
    public function overdueDispositions(): Collection
    {
        return LetterDisposition::query()
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date')
            ->get();
    }

    public function archiveIncoming(IncomingLetter $letter, ?LetterLocation $lokasi = null): IncomingLetter
    {
        if ($letter->status === IncomingLetter::STATUS_DIARSIPKAN) {
            throw new CorrespondenceException('Surat ini sudah diarsipkan.');
        }

        if ($lokasi !== null && $lokasi->level !== LetterLocation::JENJANG_MAP) {
            throw new CorrespondenceException(
                'Surat disimpan di dalam MAP, bukan langsung di '.$lokasi->level.'.'
            );
        }

        $letter->update(array_filter([
            'status' => IncomingLetter::STATUS_DIARSIPKAN,
            'location_id' => $lokasi?->id,
        ], fn ($nilai) => $nilai !== null));

        return $letter->refresh();
    }

    /**
     * Menandai surat masuk sudah dibalas — dengan menunjuk balasannya.
     *
     * Klaim "sudah dibalas" tanpa surat yang bisa ditunjuk tidak bisa
     * diperiksa, dan pada saat audit klaim yang tidak bisa diperiksa
     * dianggap tidak benar.
     */
    public function markReplied(IncomingLetter $letter, OutgoingLetter $reply): IncomingLetter
    {
        // ?? BALAS_TIDAK_PERLU, bukan perbandingan langsung: baris yang
        // dibuat di luar service ini bisa mengandalkan nilai bawaan kolom,
        // dan atribut yang kosong tidak boleh terbaca sebagai "boleh".
        $statusBalas = $letter->reply_status ?? IncomingLetter::BALAS_TIDAK_PERLU;

        if ($statusBalas === IncomingLetter::BALAS_TIDAK_PERLU) {
            throw new CorrespondenceException(
                'Surat ini ditandai tidak perlu dibalas; ubah dulu penandanya bila ternyata perlu.'
            );
        }

        if ($statusBalas === IncomingLetter::BALAS_SUDAH) {
            throw new CorrespondenceException('Surat ini sudah tercatat dibalas.');
        }

        return DB::transaction(function () use ($letter, $reply) {
            $letter->update([
                'reply_status' => IncomingLetter::BALAS_SUDAH,
                'replied_by_letter_id' => $reply->id,
            ]);

            // Kaitannya dua arah supaya surat balasan pun bisa menjelaskan
            // dirinya sendiri tanpa menelusuri balik seluruh surat masuk.
            $reply->update(['replies_to_letter_id' => $letter->id]);

            return $letter->refresh();
        });
    }

    /**
     * Surat masuk yang tenggat balasnya lewat dan belum dibalas.
     *
     * @return Collection<int, IncomingLetter>
     */
    public function overdueReplies(): Collection
    {
        return IncomingLetter::query()
            ->where('reply_status', IncomingLetter::BALAS_MENUNGGU)
            ->whereNotNull('reply_due_date')
            ->whereDate('reply_due_date', '<', now()->toDateString())
            ->orderBy('reply_due_date')
            ->get();
    }

    public function draftOutgoing(array $data, int $createdBy, ?LetterClassification $classification = null): OutgoingLetter
    {
        $this->assertPenyimpananSah($data);

        /*
         * Nomor berurut per klasifikasi bila klasifikasinya disebut; kalau
         * tidak, jatuh ke penomoran lama supaya surat tetap bisa dibuat
         * sebelum pola klasifikasi arsip RSP UI ditetapkan. Yang tidak
         * dilakukan: mengarang kode klasifikasi supaya nomornya kelihatan
         * lengkap.
         */
        $nomor = $classification !== null
            ? $this->letterNumbers->allocate($classification)
            : $this->numbers->allocate('SK');

        if ($classification !== null) {
            $data['classification_id'] = $classification->id;
        }

        return OutgoingLetter::query()->create($data + [
            'letter_number' => $nomor,
            'status' => OutgoingLetter::STATUS_DRAFT,
            'created_by' => $createdBy,
        ]);
    }

    public function send(OutgoingLetter $letter): OutgoingLetter
    {
        if (! $letter->isDraft()) {
            throw new CorrespondenceException('Surat ini sudah terkirim.');
        }

        $letter->update(['status' => OutgoingLetter::STATUS_TERKIRIM, 'sent_at' => now()->toDateString()]);

        return $letter->refresh();
    }

    /** Surat disimpan di dalam map, bukan langsung di ruang/almari/rak. */
    private function assertPenyimpananSah(array $data): void
    {
        $lokasiId = $data['location_id'] ?? null;

        if ($lokasiId === null) {
            return;
        }

        $lokasi = LetterLocation::query()->find($lokasiId);

        if ($lokasi === null || $lokasi->level !== LetterLocation::JENJANG_MAP) {
            throw new CorrespondenceException('Surat disimpan di dalam MAP, bukan langsung di ruang/almari/rak.');
        }
    }

    private function assertTenggatBalasSah(array $data): void
    {
        $status = $data['reply_status'] ?? IncomingLetter::BALAS_TIDAK_PERLU;
        $tenggat = $data['reply_due_date'] ?? null;

        if ($status === IncomingLetter::BALAS_TIDAK_PERLU && filled($tenggat)) {
            throw new CorrespondenceException(
                'Tenggat balas hanya berarti kalau balasannya memang ditunggu.'
            );
        }

        if ($status === IncomingLetter::BALAS_SUDAH) {
            throw new CorrespondenceException(
                'Surat baru tidak bisa langsung berstatus sudah dibalas — pakai markReplied() '.
                'supaya surat balasannya bisa ditunjuk.'
            );
        }
    }
}
