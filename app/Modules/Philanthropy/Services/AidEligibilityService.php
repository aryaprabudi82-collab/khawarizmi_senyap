<?php

namespace App\Modules\Philanthropy\Services;

use App\Modules\Philanthropy\Models\Assessment;
use App\Modules\Philanthropy\Models\AssessmentAnswer;
use App\Modules\Philanthropy\Models\AssessmentCriterion;
use App\Modules\Philanthropy\Models\Disbursement;
use App\Modules\Philanthropy\Models\Recipient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kelayakan dan penyaluran dana kesehatan (ZIS/CSR).
 *
 * Seluruh domain T Khanza adalah enam belas daftar kosakata; instrumen yang
 * memakainya tidak pernah dibangun, dan penyalurannya (`ambil_dankes`)
 * tidak menyebut penerimanya. Kelas ini yang menutup kedua lubang itu.
 */
class AidEligibilityService
{
    public function __construct(private readonly PhilanthropyNumberAllocator $numbers) {}

    public function registerRecipient(array $data): Recipient
    {
        /*
         * Golongan asnaf tidak diterima sebagai id bebas dari formulir —
         * ia ditetapkan lewat tetapkanAsnaf() supaya kategorinya diperiksa.
         * Tanpa itu, id kriteria apa pun (misal "dinding tembok") bisa
         * masuk sebagai golongan asnaf, dan pemeriksaan kelayakan zakat
         * yang bergantung padanya akan meloloskan siapa saja.
         */
        unset($data['asnaf_criteria_id']);

        return Recipient::query()->create($data + [
            'recipient_number' => $this->numbers->allocate('PNR'),
            'is_active' => true,
        ]);
    }

    public function tetapkanAsnaf(Recipient $recipient, AssessmentCriterion $asnaf): Recipient
    {
        if ($asnaf->category !== AssessmentCriterion::KATEGORI_ASNAF) {
            throw new RuntimeException(
                'Golongan asnaf harus dipilih dari kategori asnaf, bukan "'.$asnaf->category.'".'
            );
        }

        $recipient->update(['asnaf_criteria_id' => $asnaf->getKey()]);

        return $recipient->refresh();
    }

    /**
     * Membuka asesmen kelayakan.
     *
     * PUTUSAN TIDAK PERNAH DITERIMA DARI PEMANGGIL. Asesmen lahir belum
     * diputuskan, dan putusannya cuma bisa lewat decide() yang menuntut
     * nama pemutus dan alasannya. Kalau putusan bisa diketik saat membuat,
     * baris "layak" bisa lahir tanpa pernah ada yang memutuskan apa pun —
     * dan uang titipan orang keluar atas dasar itu.
     */
    public function openAssessment(Recipient $recipient, array $data): Assessment
    {
        unset(
            $data['decision'], $data['decision_reason'],
            $data['decided_by_name'], $data['decided_at'], $data['recipient_id']
        );

        return Assessment::query()->create($data + [
            'assessment_number' => $this->numbers->allocate('ASK'),
            'recipient_id' => $recipient->getKey(),
            'assessed_on' => $data['assessed_on'] ?? now()->toDateString(),
            'decision' => Assessment::PUTUSAN_BELUM,
        ]);
    }

    /**
     * Menjawab satu kategori.
     *
     * $criterion null berarti "ditanyakan tapi tidak terjawab" — dan itu
     * keadaan yang sah, berbeda dari kategori yang tidak pernah disentuh.
     *
     * Label dan bobotnya DIBEKUKAN di sini. Kriteria yang besok diubah
     * kalimatnya atau diganti bobotnya tidak boleh mengubah bunyi asesmen
     * yang sudah diputuskan: putusan atas nasib orang harus tetap terbaca
     * sebagaimana ia diambil.
     */
    public function answer(
        Assessment $assessment,
        string $category,
        ?AssessmentCriterion $criterion,
        ?string $note = null
    ): AssessmentAnswer {
        $this->assertBelumDiputuskan($assessment, 'Jawaban tidak bisa diubah');

        if (! in_array($category, AssessmentCriterion::KATEGORI, true)) {
            throw new RuntimeException('Kategori "'.$category.'" tidak dikenal.');
        }

        /*
         * Kriteria harus berasal dari kategori yang sedang dijawab. Tanpa
         * ini, "atap seng" bisa tercatat sebagai jawaban penghasilan, dan
         * ringkasan asesmennya akan terbaca wajar sampai ada yang membuka
         * satu per satu.
         */
        if ($criterion !== null && $criterion->category !== $category) {
            throw new RuntimeException(
                'Pilihan "'.$criterion->name.'" milik kategori '.$criterion->category
                .', tidak bisa jadi jawaban kategori '.$category.'.'
            );
        }

        return AssessmentAnswer::query()->updateOrCreate(
            ['assessment_id' => $assessment->getKey(), 'category' => $category],
            [
                'criteria_id' => $criterion?->getKey(),
                'label' => $criterion?->name,
                'weight' => $criterion?->weight,
                'note' => $note,
            ]
        );
    }

    /**
     * Memutuskan layak/tidak layak.
     *
     * ALASAN WAJIB PADA KEDUA ARAH. Di domain lain penolakan yang dituntut
     * beralasan sementara persetujuan tidak; di sini keduanya sama-sama
     * menentukan nasib orang dan sama-sama memakai uang titipan. Bantuan
     * yang diberikan tanpa alasan tercatat sama sulitnya
     * dipertanggungjawabkan dengan penolakan tanpa alasan.
     *
     * TIDAK ADA AMBANG OTOMATIS. Bobot boleh diisi dan totalnya dihitung,
     * tapi tidak ada rumus yang mengubah total jadi putusan: rumus itu
     * belum pernah ditetapkan RSP UI, dan menebaknya berarti menolak
     * keluarga sungguhan dengan angka karangan.
     */
    public function decide(
        Assessment $assessment,
        string $decision,
        string $reason,
        string $decidedByName,
        ?float $recommendedAmount = null
    ): Assessment {
        $this->assertBelumDiputuskan($assessment, 'Asesmen sudah diputuskan');

        if (! in_array($decision, [Assessment::PUTUSAN_LAYAK, Assessment::PUTUSAN_TIDAK_LAYAK], true)) {
            throw new RuntimeException('Putusan harus layak atau tidak-layak.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Putusan kelayakan wajib menyebut alasannya.');
        }

        if (trim($decidedByName) === '') {
            throw new RuntimeException('Putusan kelayakan wajib menyebut nama pemutusnya.');
        }

        $assessment->update([
            'decision' => $decision,
            'decision_reason' => $reason,
            'decided_by_name' => $decidedByName,
            'decided_at' => now(),
            'recommended_amount' => $decision === Assessment::PUTUSAN_LAYAK ? $recommendedAmount : null,
        ]);

        return $assessment->refresh();
    }

    /**
     * Menyalurkan dana.
     *
     * Penerimanya diambil DARI asesmennya, tidak diterima dari pemanggil:
     * kalau bisa diketik, dana atas dasar asesmen seseorang bisa keluar
     * atas nama orang lain — dan itulah bentuk penyelewengan yang paling
     * sulit ditemukan belakangan karena setiap barisnya tampak lengkap.
     */
    public function disburse(Assessment $assessment, array $data): Disbursement
    {
        if ($assessment->decision !== Assessment::PUTUSAN_LAYAK) {
            throw new RuntimeException(
                'Dana hanya bisa disalurkan atas asesmen yang diputuskan layak.'
            );
        }

        $sumber = $data['fund_source'] ?? null;

        if (! in_array($sumber, Disbursement::SUMBER, true)) {
            throw new RuntimeException('Sumber dana tidak dikenal.');
        }

        $jumlah = (float) ($data['amount'] ?? 0);

        if ($jumlah <= 0) {
            throw new RuntimeException('Jumlah penyaluran harus lebih dari nol.');
        }

        $penerima = $assessment->recipient;

        /*
         * Zakat hanya sah untuk delapan golongan asnaf, dan itu syarat
         * dari luar rumah sakit — bukan kebijakan yang boleh dilonggarkan
         * panitia saat buru-buru. Yang belum ditetapkan golongannya bukan
         * berarti tidak berhak; ia berarti belum ada yang menetapkan, dan
         * itu harus diselesaikan dulu, bukan dilewati.
         */
        if (in_array($sumber, Disbursement::WAJIB_ASNAF, true) && $penerima->asnaf_criteria_id === null) {
            throw new RuntimeException(
                'Penyaluran zakat menuntut golongan asnaf penerima sudah ditetapkan.'
            );
        }

        unset($data['recipient_id'], $data['assessment_id']);

        return Disbursement::query()->create($data + [
            'disbursement_number' => $this->numbers->allocate('SLR'),
            'recipient_id' => $penerima->getKey(),
            'assessment_id' => $assessment->getKey(),
            'disbursed_on' => $data['disbursed_on'] ?? now()->toDateString(),
        ]);
    }

    /**
     * Rekap penyaluran per sumber dana untuk satu rentang.
     *
     * Menjawab pertanyaan yang tidak pernah bisa dijawab `ambil_dankes`:
     * berapa yang keluar dari tiap titipan, kepada berapa orang.
     *
     * @return array<string, array{jumlah: string, penerima: int, penyaluran: int}>
     */
    public function rekapSumber(string $dari, string $sampai): array
    {
        $baris = DB::table('philanthropy.disbursements')
            ->whereBetween('disbursed_on', [$dari, $sampai])
            ->groupBy('fund_source')
            ->selectRaw('fund_source, sum(amount) as jumlah, count(*) as penyaluran,
                         count(distinct recipient_id) as penerima')
            ->get();

        $rekap = [];

        foreach ($baris as $b) {
            $rekap[$b->fund_source] = [
                'jumlah' => (string) $b->jumlah,
                'penerima' => (int) $b->penerima,
                'penyaluran' => (int) $b->penyaluran,
            ];
        }

        return $rekap;
    }

    /**
     * Penerima yang dibantu lebih dari sekali dalam satu rentang.
     *
     * Bukan tuduhan, melainkan daftar yang perlu dilihat amil: bantuan
     * berulang sering memang wajar (pengobatan berkelanjutan), tapi ia
     * juga satu-satunya tempat penerimaan ganda bisa terlihat — dan pada
     * Khanza ia tidak bisa terlihat sama sekali karena penerimanya tidak
     * dicatat.
     */
    public function penerimaBerulang(string $dari, string $sampai): array
    {
        return DB::table('philanthropy.disbursements as d')
            ->join('philanthropy.recipients as p', 'p.id', '=', 'd.recipient_id')
            ->whereBetween('d.disbursed_on', [$dari, $sampai])
            ->groupBy('p.id', 'p.name', 'p.recipient_number')
            ->havingRaw('count(*) > 1')
            ->selectRaw('p.recipient_number, p.name, count(*) as kali, sum(d.amount) as jumlah')
            ->orderByDesc('kali')
            ->get()
            ->map(fn ($b) => [
                'nomor' => $b->recipient_number,
                'nama' => $b->name,
                'kali' => (int) $b->kali,
                'jumlah' => (string) $b->jumlah,
            ])
            ->all();
    }

    private function assertBelumDiputuskan(Assessment $assessment, string $pesan): void
    {
        if ($assessment->sudahDiputuskan()) {
            throw new RuntimeException(
                $pesan.' — asesmen '.$assessment->assessment_number.' sudah berputusan "'
                .$assessment->decision.'". Buka asesmen baru kalau keadaannya berubah.'
            );
        }
    }
}
