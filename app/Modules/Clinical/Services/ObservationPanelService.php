<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Observation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pencatatan panel observasi (domain M item D) — 13 kode:
 * catatan_observasi_ranap, _igd, _bayi, _ruang_ok, _ventilator,
 * _hemodialisa, _ranap_kebidanan, _ranap_postpartum, _chbp,
 * _induksi_persalinan, _restrain_nonfarma, dan catatan_cek_gds.
 *
 * KEDUA BELASNYA PENGUKURAN BERULANG PADA SATU TITIK WAKTU, dan
 * clinical.observations sudah menyimpannya sejak awal. Yang belum ada
 * adalah panelnya — kumpulan pengukuran mana yang muncul di layar mana.
 *
 * OBSERVASI BANGSAL TIDAK MENUNTUT ASESMEN. Pencatatan lama menempelkan
 * pengukuran pada lembar asesmen, dan itu benar untuk pemeriksaan di
 * poliklinik. Tapi tanda vital bangsal dicatat tiap beberapa jam oleh
 * perawat yang tidak sedang membuat asesmen apa pun; menuntut asesmen
 * akan memaksa lembar kosong dibuat cuma supaya angkanya punya tempat.
 *
 * SATU WAKTU, SATU BARIS PER PENGUKURAN. Mencatat ulang pada waktu yang
 * sama MENGGANTI nilai sebelumnya, bukan menambah baris kedua: dua nilai
 * suhu pada jam yang sama tidak bisa dibedakan mana yang berlaku, dan
 * grafik pemantauan akan menggambar dua titik yang saling bertentangan.
 *
 * PENANDA ABNORMAL MEMAKAI RENTANG PANEL, bukan rentang bawaan kodenya.
 * Laju napas 40 normal pada bayi dan gawat pada dewasa — memakai rentang
 * dewasa untuk panel bayi akan menyalakan penanda sepanjang hari, dan
 * penanda yang selalu menyala melatih orang mengabaikannya.
 *
 * NILAI KOSONG DILEWATI, TIDAK DICATAT SEBAGAI NOL. Pengukuran yang tidak
 * dilakukan dan pengukuran yang hasilnya nol adalah dua hal berbeda —
 * saturasi oksigen 0 berarti pasien tidak bernapas.
 */
class ObservationPanelService
{
    public function __construct(private readonly ObservationCatalogContext $catalog) {}

    /**
     * Mencatat satu panel observasi pada satu titik waktu.
     *
     * @param  array<string, mixed>  $values  kode pengukuran => nilai
     * @return int jumlah pengukuran yang tercatat
     *
     * @throws ClinicalException
     */
    public function record(
        int $registrationId,
        string $panelCode,
        array $values,
        ?Carbon $observedAt = null,
        ?User $actor = null,
    ): int {
        $kunjungan = DB::table('encounter.v_registration_summary')->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $butir = $this->catalog->panelItems($panelCode);

        if ($butir->isEmpty()) {
            throw new ClinicalException("Panel observasi '{$panelCode}' tidak ada atau sudah tidak aktif.");
        }

        $asing = array_diff(array_keys($values), $butir->keys()->all());

        if ($asing !== []) {
            throw new ClinicalException(
                'Pengukuran tidak termasuk panel ini: ' . implode(', ', $asing)
                . '. Panel mungkin sudah berubah — buka ulang layarnya.'
            );
        }

        $waktu = $observedAt ?? now();
        $tercatat = 0;

        DB::transaction(function () use ($butir, $values, $kunjungan, $registrationId, $waktu, $actor, &$tercatat) {
            foreach ($values as $kode => $nilai) {
                // Kosong dilewati — lihat catatan kelas.
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $item = $butir->get($kode);
                $numerik = $item->value_type === 'numeric' ? (float) $nilai : null;

                Observation::query()->updateOrCreate(
                    [
                        'registration_id' => $registrationId,
                        'code' => $kode,
                        'observed_at' => $waktu,
                    ],
                    [
                        'patient_id' => $kunjungan->patient_id,
                        'display' => $item->display,
                        'value_numeric' => $numerik,
                        'value_text' => $item->value_type === 'text' ? (string) $nilai : null,
                        'unit' => $item->unit,
                        // Rentang PANEL, bukan rentang bawaan kodenya.
                        'is_abnormal' => $this->catalog->isAbnormal($item, $numerik),
                        'practitioner_id' => $kunjungan->practitioner_id,
                        'created_by' => $actor?->id,
                        'created_at' => now(),
                    ]
                );

                $tercatat++;
            }
        });

        return $tercatat;
    }

    /**
     * Pengukuran satu panel sepanjang kunjungan, urut waktu.
     *
     * Inilah bentuk yang dibaca perawat: satu baris per waktu, satu kolom
     * per pengukuran — dan perburukan terlihat karena tiap waktu berdiri
     * sendiri, bukan tertimpa.
     */
    public function timeline(int $registrationId, string $panelCode, int $limit = 200): Collection
    {
        $kode = $this->catalog->panelItems($panelCode)->keys()->all();

        if ($kode === []) {
            return collect();
        }

        return Observation::query()
            ->where('registration_id', $registrationId)
            ->whereIn('code', $kode)
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get()
            ->groupBy(fn ($o) => $o->observed_at->toDateTimeString())
            ->map(fn ($baris, $waktu) => (object) [
                'observed_at' => $waktu,
                'values' => $baris->keyBy('code')->map(fn ($o) => (object) [
                    'display' => $o->display,
                    'value' => $o->value_numeric ?? $o->value_text,
                    'unit' => $o->unit,
                    'is_abnormal' => $o->is_abnormal,
                ]),
            ]);
    }

    /**
     * Pengukuran abnormal terakhir pada satu kunjungan.
     *
     * Yang dicari perawat jaga bukan seluruh angka, melainkan yang di luar
     * batas — dan daftar itu harus bisa dibuka tanpa membaca seluruh
     * riwayat.
     */
    public function recentAbnormal(int $registrationId, int $limit = 20): Collection
    {
        return Observation::query()
            ->where('registration_id', $registrationId)
            ->where('is_abnormal', true)
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }
}
