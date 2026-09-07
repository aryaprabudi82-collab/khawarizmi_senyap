<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Delivery;
use App\Modules\Clinical\Models\DeliveryBaby;
use App\Modules\Clinical\Models\DeliveryBabyApgarScore;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persalinan & bayi baru lahir (domain M item J).
 *
 * EMPAT ATURAN.
 *
 * 1. BERAPA PUN BAYINYA MUAT. catatan_persalinan Khanza hanya punya satu
 *    kolom untuk tiap hal tentang bayi, sehingga bayi kedua pada
 *    kelahiran kembar tidak bisa dicatat sama sekali. Di sini tiap bayi
 *    satu baris dengan urutan lahirnya sendiri.
 *
 * 2. APGAR MENIT 1 DAN 5 WAJIB UNTUK TIAP BAYI LAHIR HIDUP sebelum
 *    catatan persalinan difinalkan. Itu penilaian baku, dan bayi yang
 *    lahir tanpa APGAR tercatat adalah bayi yang tidak ada bukti pernah
 *    dinilai keadaannya.
 *
 * 3. BAYI LAHIR MATI TIDAK DINILAI APGAR. Menuntutnya berarti memaksa
 *    bidan mengarang angka untuk bayi yang tidak bernapas.
 *
 * 4. JUMLAH PERDARAHAN DAN LAMA PERSALINAN DIHITUNG, TIDAK DISIMPAN.
 *    Khanza menyediakan darah_keluar_jumlah dan waktu_persalinan_jumlah
 *    di samping bagian-bagiannya; keduanya bisa berbeda dari
 *    penjumlahannya, dan yang pertama menentukan apakah ini perdarahan
 *    pascasalin.
 */
class DeliveryService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Membuka catatan persalinan untuk kunjungan ibu.
     *
     * Idempoten: satu kunjungan satu persalinan.
     *
     * @throws ClinicalException
     */
    public function open(int $registrationId, array $data = [], ?User $actor = null): Delivery
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $ada = Delivery::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', Delivery::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        return Delivery::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'started_at' => $data['started_at'] ?? now(),
            'attending_practitioner_id' => $data['attending_practitioner_id'] ?? $kunjungan->practitioner_id,
            'attending_practitioner_name' => $data['attending_practitioner_name'] ?? $kunjungan->practitioner_name,
            'midwife_name' => $data['midwife_name'] ?? null,
            'status' => Delivery::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function save(Delivery $delivery, array $data): Delivery
    {
        $this->assertEditable($delivery);

        if (isset($data['delivery_method']) && ! array_key_exists($data['delivery_method'], Delivery::CARA)) {
            throw new ClinicalException(
                "Cara persalinan '{$data['delivery_method']}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(Delivery::CARA)).'.'
            );
        }

        $delivery->update(array_intersect_key($data, array_flip([
            'ended_at', 'attending_practitioner_id', 'attending_practitioner_name', 'midwife_name',
            'delivery_method', 'gravida', 'para', 'abortus', 'gestational_age',
            'membrane_ruptured_at', 'amniotic_fluid',
            'stage1_minutes', 'stage2_minutes', 'stage3_minutes', 'stage4_minutes',
            'blood_loss_stage2_ml', 'blood_loss_stage3_ml', 'blood_loss_stage4_ml',
            'perineum', 'perineum_degree', 'outer_sutures', 'inner_sutures',
            'placenta_delivery', 'uterine_contraction', 'vaginal_bleeding', 'medication', 'note',
        ])));

        return $delivery->refresh();
    }

    // ------------------------------------------------------------------ bayi

    /**
     * Mencatat satu bayi.
     *
     * Urutan lahirnya diberikan otomatis bila tidak disebut — bayi kedua
     * hanya perlu dicatat, tanpa siapa pun harus memikirkan di kolom mana
     * ia muat.
     *
     * @throws ClinicalException
     */
    public function addBaby(Delivery $delivery, array $data, ?User $actor = null): DeliveryBaby
    {
        $this->assertEditable($delivery);

        $jenisKelamin = $data['sex'] ?? null;

        if (! in_array($jenisKelamin, ['L', 'P', 'tidak-jelas'], true)) {
            throw new ClinicalException(
                "Jenis kelamin '{$jenisKelamin}' tidak dikenali. Pilihannya: L, P, tidak-jelas."
            );
        }

        $keadaan = $data['birth_status'] ?? DeliveryBaby::HIDUP;

        if (! in_array($keadaan, [DeliveryBaby::HIDUP, DeliveryBaby::LAHIR_MATI], true)) {
            throw new ClinicalException("Keadaan lahir '{$keadaan}' tidak dikenali.");
        }

        $urutan = $data['birth_order']
            ?? ((int) $delivery->babies()->max('birth_order') + 1);

        return DeliveryBaby::query()->create([
            'delivery_id' => $delivery->id,
            'birth_order' => $urutan,
            'born_at' => $data['born_at'] ?? now(),
            'sex' => $jenisKelamin,
            'birth_status' => $keadaan,
            'weight_grams' => $data['weight_grams'] ?? null,
            'length_cm' => $data['length_cm'] ?? null,
            'head_circumference_cm' => $data['head_circumference_cm'] ?? null,
            'chest_circumference_cm' => $data['chest_circumference_cm'] ?? null,
            'abdominal_circumference_cm' => $data['abdominal_circumference_cm'] ?? null,
            'abnormality' => $data['abnormality'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }

    /**
     * Menautkan bayi ke rekam medisnya sendiri setelah ia didaftarkan.
     *
     * Identitas pasien milik konteks identity; di sini yang disimpan
     * hanya tautan longgar berikut nomor rekam medisnya.
     *
     * @throws ClinicalException
     */
    public function linkPatient(DeliveryBaby $baby, int $patientId, string $medicalRecordNumber): DeliveryBaby
    {
        if ($baby->patient_id !== null) {
            throw new ClinicalException('Bayi ini sudah tertaut ke rekam medis '.$baby->patient_mrn.'.');
        }

        if ($baby->birth_status === DeliveryBaby::LAHIR_MATI) {
            throw new ClinicalException(
                'Bayi lahir mati tidak didaftarkan sebagai pasien. Yang diterbitkan untuknya adalah '
                .'surat keterangan lahir mati, bukan rekam medis rawat.'
            );
        }

        $baby->update(['patient_id' => $patientId, 'patient_mrn' => $medicalRecordNumber]);

        return $baby->refresh();
    }

    // ----------------------------------------------------------------- apgar

    /**
     * Mencatat APGAR satu bayi pada satu menit penilaian.
     *
     * @throws ClinicalException
     */
    public function recordApgar(
        DeliveryBaby $baby,
        int $minute,
        array $components,
        ?User $actor = null,
    ): DeliveryBabyApgarScore {
        $this->assertEditable($baby->delivery);

        if (! in_array($minute, DeliveryBabyApgarScore::MENIT, true)) {
            throw new ClinicalException(
                "Menit penilaian {$minute} bukan menit baku APGAR. Pilihannya: "
                .implode(', ', DeliveryBabyApgarScore::MENIT).'.'
            );
        }

        if ($baby->birth_status === DeliveryBaby::LAHIR_MATI) {
            throw new ClinicalException(
                'Bayi lahir mati tidak dinilai APGAR. Menuntutnya berarti memaksa penolong persalinan '
                .'mengarang angka untuk bayi yang tidak bernapas.'
            );
        }

        $nilai = [];

        foreach (array_keys(DeliveryBabyApgarScore::KOMPONEN) as $komponen) {
            // Sengaja memeriksa keberadaan kunci, bukan memakai empty():
            // nol adalah nilai APGAR yang sah, dan justru nol yang paling
            // menentukan tindakan.
            if (! array_key_exists($komponen, $components)) {
                throw new ClinicalException(
                    "Komponen APGAR '{$komponen}' ("
                    .DeliveryBabyApgarScore::KOMPONEN[$komponen].') belum dinilai. '
                    .'Kelimanya harus dinilai bersama; menjumlahkan yang tidak lengkap menghasilkan '
                    .'angka yang terlihat rendah tanpa alasan.'
                );
            }

            $angka = $components[$komponen];

            if (! is_int($angka) || $angka < 0 || $angka > 2) {
                throw new ClinicalException(
                    "Nilai komponen '{$komponen}' harus bilangan bulat 0, 1, atau 2."
                );
            }

            $nilai[$komponen] = $angka;
        }

        $skor = DeliveryBabyApgarScore::query()->updateOrCreate(
            ['baby_id' => $baby->id, 'minute' => $minute],
            $nilai + ['recorded_by' => $actor?->id],
        );

        return $skor->refresh();
    }

    // ------------------------------------------------------------ finalisasi

    /**
     * @throws ClinicalException
     */
    public function finalize(Delivery $delivery, ?User $actor = null): Delivery
    {
        $this->assertEditable($delivery);

        if ($delivery->ended_at === null) {
            throw new ClinicalException(
                'Waktu selesai persalinan wajib diisi sebelum catatannya difinalkan.'
            );
        }

        $bayi = $delivery->babies()->with('apgarScores')->get();

        if ($bayi->isEmpty()) {
            throw new ClinicalException(
                'Belum ada bayi yang dicatat pada persalinan ini. Catatan persalinan tanpa bayi tidak '
                .'menjelaskan apa yang terjadi — termasuk bila bayinya lahir mati, yang justru harus '
                .'tercatat.'
            );
        }

        $kurang = [];

        foreach ($bayi as $satu) {
            if (! $satu->isLiveBirth()) {
                continue;
            }

            $menit = $satu->apgarScores->pluck('minute')->all();

            foreach (DeliveryBabyApgarScore::MENIT_WAJIB as $wajib) {
                if (! in_array($wajib, $menit, true)) {
                    $kurang[] = "bayi ke-{$satu->birth_order} menit {$wajib}";
                }
            }
        }

        if ($kurang !== []) {
            throw new ClinicalException(
                'APGAR belum lengkap: '.implode(', ', $kurang).'. Bayi lahir hidup dinilai pada menit 1 '
                .'dan 5; tanpa itu tidak ada bukti keadaannya pernah dinilai.'
            );
        }

        $delivery->update([
            'status' => Delivery::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $delivery->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(Delivery $delivery, string $reason): Delivery
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($delivery->status === Delivery::DIBATALKAN) {
            throw new ClinicalException('Catatan persalinan ini sudah dibatalkan.');
        }

        $delivery->update([
            'status' => Delivery::DIBATALKAN,
            'note' => trim(($delivery->note ? $delivery->note.' ' : '')."[Dibatalkan: {$alasan}]"),
        ]);

        return $delivery->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): ?Delivery
    {
        return Delivery::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', Delivery::DIBATALKAN)
            ->with('babies.apgarScores')
            ->first();
    }

    /**
     * Persalinan dengan perdarahan yang sudah mencapai ambang pascasalin.
     *
     * Dihitung di PHP, bukan di SQL, supaya ambangnya cuma didefinisikan
     * sekali — di Delivery::AMBANG_PERDARAHAN_ML.
     */
    public function withHaemorrhage(string $from, string $until): Collection
    {
        return Delivery::query()
            ->whereBetween('started_at', [$from, $until])
            ->where('status', '<>', Delivery::DIBATALKAN)
            ->get()
            ->filter(fn (Delivery $persalinan) => $persalinan->isPostpartumHaemorrhage() === true)
            ->values();
    }

    /** Bayi lahir dengan berat rendah pada satu periode. */
    public function lowBirthWeightBabies(string $from, string $until): Collection
    {
        return DeliveryBaby::query()
            ->whereBetween('born_at', [$from, $until])
            ->where('birth_status', DeliveryBaby::HIDUP)
            ->whereNotNull('weight_grams')
            ->where('weight_grams', '<', DeliveryBaby::BBLR_GRAM)
            ->orderBy('born_at')
            ->get();
    }

    /**
     * @throws ClinicalException
     */
    private function assertEditable(Delivery $delivery): void
    {
        if ($delivery->isEditable()) {
            return;
        }

        throw new ClinicalException(
            $delivery->status === Delivery::FINAL
                ? 'Catatan persalinan yang sudah difinalkan tidak bisa diubah.'
                : 'Catatan persalinan ini sudah dibatalkan.'
        );
    }
}
