<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\ConsentInformationItem;
use App\Modules\Correspondence\Models\ConsentTemplate;
use App\Modules\Correspondence\Models\PatientConsent;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * Persetujuan tanpa rincian butir — untuk jenis yang memang tidak
     * berbentuk penjelasan tindakan (persetujuan umum saat masuk,
     * persetujuan pemeriksaan HIV, penundaan pelayanan).
     */
    public function issue(array $data, int $issuedBy): PatientConsent
    {
        $this->assertPenandaTanganSah($data);
        $this->assertPenolakanDijelaskanRisikonya($data);

        return PatientConsent::query()->create($data + [
            'consent_number' => $this->numbers->allocate('PST'),
            'issued_by' => $issuedBy,
            'signed_at' => now(),
            'status' => PatientConsent::STATUS_AKTIF,
        ]);
    }

    /**
     * Persetujuan tindakan dari template.
     *
     * Butir penjelasan DISALIN, bukan dirujuk: kalau dirujuk, revisi
     * kalimat risiko tahun depan akan mengubah bunyi persetujuan yang
     * ditandatangani tahun ini.
     *
     * Lahir dalam keadaan 'belum-dikonfirmasi' — formulirnya sudah dibuat
     * tapi belum ada yang menyatakan apa pun. Keputusannya direkam
     * terpisah lewat decide(), setelah butirnya dijelaskan.
     */
    public function issueFromTemplate(ConsentTemplate $template, array $data, int $issuedBy): PatientConsent
    {
        $this->assertPenandaTanganSah($data);

        if (! $template->is_active) {
            throw new CorrespondenceException(
                'Template "'.$template->name.'" sudah tidak aktif; pakai versi yang berlaku.'
            );
        }

        $butir = $template->items()->get();

        if ($butir->isEmpty()) {
            throw new CorrespondenceException(
                'Template "'.$template->name.'" belum berisi butir penjelasan apa pun.'
            );
        }

        // Dibuang lebih dulu: operator + mempertahankan kunci di operan kiri,
        // jadi nilai dari pemanggil akan menang atas nilai template kalau
        // dibiarkan.
        unset($data['consent_type']);

        return DB::transaction(function () use ($template, $butir, $data, $issuedBy) {
            $consent = PatientConsent::query()->create($data + [
                'consent_number' => $this->numbers->allocate('PST'),
                'issued_by' => $issuedBy,
                'signed_at' => now(),
                'status' => PatientConsent::STATUS_AKTIF,
                'decision' => PatientConsent::KEPUTUSAN_BELUM,

                // JENIS DIAMBIL DARI TEMPLATE, TIDAK DITERIMA DARI PEMANGGIL.
                // Aturan yang sama seperti arah kas dan arah cairan: kalau
                // pemanggil boleh menentukannya, formulir persetujuan tindakan
                // bisa tersimpan berjenis "persetujuan umum" — dan rekap
                // persetujuan tindakan tidak akan memuatnya.
                'consent_type' => $template->consent_type,

                // Versi dan nama ikut dibekukan supaya pertanyaan "versi mana
                // yang ditandatangani" tetap punya jawaban setelah template
                // direvisi berkali-kali.
                'template_id' => $template->id,
                'template_code' => $template->code,
                'template_version' => $template->version,
                'template_name' => $template->name,
            ]);

            foreach ($butir as $isi) {
                $consent->items()->create([
                    'position' => $isi->position,
                    'label' => $isi->label,
                    'body' => $isi->body,
                    'is_required' => $isi->is_required,
                    'confirmed' => null,
                ]);
            }

            return $consent->load('items');
        });
    }

    /**
     * Mencatat hasil penjelasan satu butir.
     *
     * $confirmed null berarti mengembalikan butir ke keadaan belum
     * dijelaskan — dipakai saat petugas salah menandai, bukan sebagai
     * cara menghapus jejak.
     */
    public function confirmItem(ConsentInformationItem $item, ?bool $confirmed, ?string $catatan = null): ConsentInformationItem
    {
        $consent = $item->consent;

        if ($consent->decision !== PatientConsent::KEPUTUSAN_BELUM) {
            throw new CorrespondenceException(
                'Persetujuan ini sudah diputuskan; butir penjelasannya tidak bisa diubah lagi.'
            );
        }

        // Pasien yang menyatakan belum paham harus diikuti keterangan apa
        // yang belum dipahami. Tanpa itu, catatannya cuma memberi tahu ada
        // masalah tanpa memberi tahu masalahnya — dan tidak ada yang bisa
        // menindaklanjutinya.
        if ($confirmed === false && blank($catatan)) {
            throw new CorrespondenceException(
                'Butir yang dinyatakan belum dipahami harus disertai keterangan apa yang belum dipahami.'
            );
        }

        $item->update([
            'confirmed' => $confirmed,
            'confirmation_note' => $confirmed === false ? $catatan : null,
        ]);

        return $item->refresh();
    }

    /**
     * Merekam keputusan pasien.
     *
     * ATURANNYA SENGAJA TIDAK SIMETRIS.
     *
     * SETUJU ditahan selama masih ada butir wajib yang belum dijelaskan.
     * Orang tidak bisa menyetujui apa yang belum pernah disampaikan
     * kepadanya, dan formulir yang berbunyi "setuju" di atas butir kosong
     * justru menjadi bukti bahwa penjelasannya tidak lengkap.
     *
     * MENOLAK tidak ditahan. Pasien berhak menghentikan penjelasan di
     * tengah jalan dan menolak saat itu juga. Memaksa petugas mengisi
     * seluruh butir lebih dulu tidak membuat penjelasannya jadi ada — ia
     * hanya melahirkan konfirmasi karangan demi bisa menyimpan formulir.
     * Penolakan yang tercatat "menolak sebelum risiko sempat dijelaskan"
     * adalah catatan jujur dan berguna.
     *
     * Butir yang ditandai false (sudah dijelaskan, pasien menyatakan belum
     * paham) TIDAK menahan apa pun — itu keadaan yang sah untuk
     * ditandatangani, dan menahannya akan mendorong petugas mengubahnya
     * jadi true supaya formulirnya bisa disimpan.
     */
    public function decide(
        PatientConsent $consent,
        string $decision,
        ?string $penandaTangan = null,
        ?string $saksi = null
    ): PatientConsent {
        if ($consent->status !== PatientConsent::STATUS_AKTIF) {
            throw new CorrespondenceException('Persetujuan ini sudah dibatalkan.');
        }

        if ($consent->decision !== PatientConsent::KEPUTUSAN_BELUM) {
            throw new CorrespondenceException(
                'Persetujuan ini sudah diputuskan '.$consent->decision.'; buat dokumen baru bila keputusannya berubah.'
            );
        }

        if (! in_array($decision, [PatientConsent::KEPUTUSAN_SETUJU, PatientConsent::KEPUTUSAN_MENOLAK], true)) {
            throw new CorrespondenceException('Keputusan hanya boleh setuju atau menolak.');
        }

        if ($decision === PatientConsent::KEPUTUSAN_SETUJU) {
            $belum = $consent->load('items')->butirBelumDijelaskan();

            if ($belum !== []) {
                throw new CorrespondenceException(
                    'Belum bisa disetujui — butir berikut belum dijelaskan: '.implode(', ', $belum).'.'
                );
            }
        }

        $consent->update(array_filter([
            'decision' => $decision,
            'signer_name' => $penandaTangan,
            'witness_name' => $saksi,
        ], fn ($nilai) => $nilai !== null));

        return $consent->refresh();
    }

    public function cancel(PatientConsent $consent): PatientConsent
    {
        if ($consent->status !== PatientConsent::STATUS_AKTIF) {
            throw new CorrespondenceException('Persetujuan ini sudah dibatalkan.');
        }

        $consent->update(['status' => PatientConsent::STATUS_DIBATALKAN]);

        return $consent->refresh();
    }

    /**
     * Persetujuan yang ditandatangani orang lain harus bisa menjelaskan
     * kewenangannya.
     *
     * Permenkes 290/2008 pasal 13-14 hanya membolehkan keluarga terdekat
     * memberi persetujuan bila pasien tidak kompeten. Tanpa alasan
     * perwakilan, tidak ada yang bisa menilai belakangan apakah
     * perwakilannya sah — dan persetujuan dari orang yang tidak berhak
     * sama saja dengan tidak ada persetujuan.
     */
    private function assertPenandaTanganSah(array $data): void
    {
        $hubungan = $data['signer_relationship'] ?? null;

        if ($hubungan === null) {
            return;
        }

        if (! in_array($hubungan, PatientConsent::HUBUNGAN, true)) {
            throw new CorrespondenceException('Hubungan penanda tangan "'.$hubungan.'" tidak dikenal.');
        }

        $alasan = $data['delegation_reason'] ?? null;

        if ($hubungan !== 'diri-sendiri' && blank($alasan)) {
            throw new CorrespondenceException(
                'Persetujuan yang diwakilkan harus menyebutkan alasan pasien tidak menandatangani sendiri.'
            );
        }

        if ($hubungan === 'diri-sendiri' && filled($alasan)) {
            throw new CorrespondenceException(
                'Pasien menandatangani sendiri, jadi tidak ada alasan perwakilan yang perlu dicatat.'
            );
        }
    }

    /**
     * Menolak anjuran medis tanpa diberi tahu akibatnya bukan penolakan
     * yang sah — cerminan dari aturan persetujuan: keduanya menuntut
     * penjelasan lebih dulu.
     */
    private function assertPenolakanDijelaskanRisikonya(array $data): void
    {
        $jenis = $data['consent_type'] ?? null;
        $keputusan = $data['decision'] ?? null;

        if ($jenis === 'penolakan-anjuran-medis'
            && $keputusan === PatientConsent::KEPUTUSAN_MENOLAK
            && blank($data['refusal_risk_explained'] ?? null)) {
            throw new CorrespondenceException(
                'Penolakan anjuran medis harus mencatat akibat yang sudah dijelaskan kepada pasien.'
            );
        }
    }
}
