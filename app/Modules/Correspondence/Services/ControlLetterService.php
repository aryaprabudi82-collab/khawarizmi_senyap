<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\ControlLetter;
use Illuminate\Support\Collection;

/**
 * Surat kontrol / SKDP (domain P item C, kode skdp_bpjs).
 *
 * Dua hal yang SENGAJA tidak ada di sini, dan keduanya perlu disebut
 * supaya tidak dikira lupa:
 *
 * 1. Tidak ada penyaluran ke Vclaim/BPJS. Kredensial bridging BPJS
 *    ditangguhkan sejak awal proyek. Surat ini dokumen rumah sakit;
 *    nomor SEP dan antrean BPJS-nya bukan.
 *
 * 2. Tidak membuat booking kunjungan. Booking hidup di konteks encounter
 *    dan correspondence tidak boleh menulis ke sana. Akibatnya pasien
 *    tetap harus didaftarkan seperti biasa saat datang — menyambungkan
 *    keduanya adalah pekerjaan konteks encounter.
 */
class ControlLetterService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function issue(array $data, int $issuedBy, string $issuedByName): ControlLetter
    {
        $tanggal = $data['control_date'] ?? null;

        if (blank($tanggal)) {
            throw new CorrespondenceException('Surat kontrol harus menyebutkan tanggal kontrolnya.');
        }

        /*
         * Surat kontrol bertanggal mundur bukan rujukan kontrol, ia salah
         * ketik — dan salah ketik yang lolos akan langsung tampil di daftar
         * "pasien tidak datang kontrol" pada hari yang sama ia diterbitkan.
         */
        if (strtotime((string) $tanggal) < strtotime(now()->toDateString())) {
            throw new CorrespondenceException('Tanggal kontrol tidak boleh sudah lewat.');
        }

        return ControlLetter::query()->create($data + [
            'letter_number' => $this->numbers->allocate('SKK'),
            'issued_by' => $issuedBy,
            'issued_by_name' => $issuedByName,
            'issued_at' => now(),
            'status' => ControlLetter::STATUS_MENUNGGU,
        ]);
    }

    public function markSeen(ControlLetter $letter): ControlLetter
    {
        $this->assertMasihMenunggu($letter);

        $letter->update([
            'status' => ControlLetter::STATUS_SUDAH_PERIKSA,
            'status_changed_at' => now(),
        ]);

        return $letter->refresh();
    }

    public function cancel(ControlLetter $letter, string $alasan): ControlLetter
    {
        $this->assertMasihMenunggu($letter);

        if (blank($alasan)) {
            throw new CorrespondenceException('Pembatalan surat kontrol harus menyebutkan alasannya.');
        }

        $letter->update([
            'status' => ControlLetter::STATUS_BATAL,
            'status_note' => $alasan,
            'status_changed_at' => now(),
        ]);

        return $letter->refresh();
    }

    /**
     * Surat kontrol yang tanggalnya sudah lewat tapi pasiennya tidak
     * pernah datang.
     *
     * DIHITUNG dari tanggal, tidak disimpan sebagai status keempat. Status
     * "terlewat" yang disimpan menuntut ada yang menjalankannya tiap hari,
     * dan surat yang terlewat pada hari sistem itu mati akan selamanya
     * berstatus menunggu.
     *
     * @return Collection<int, ControlLetter>
     */
    public function missed(): Collection
    {
        return ControlLetter::query()
            ->where('status', ControlLetter::STATUS_MENUNGGU)
            ->whereDate('control_date', '<', now()->toDateString())
            ->orderBy('control_date')
            ->get();
    }

    private function assertMasihMenunggu(ControlLetter $letter): void
    {
        if ($letter->status !== ControlLetter::STATUS_MENUNGGU) {
            throw new CorrespondenceException(
                'Surat kontrol ini sudah berstatus '.$letter->status.'.'
            );
        }
    }
}
