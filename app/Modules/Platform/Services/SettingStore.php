<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Models\SettingRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pembacaan & perubahan pengaturan aplikasi.
 *
 * DUA ATURAN YANG MEMBEDAKANNYA DARI `set_*` KHANZA:
 *
 * 1. NILAI PUNYA TANGGAL BERLAKU. `valueAt()` menjawab "berapa nilainya
 *    pada tanggal itu", bukan "berapa nilainya sekarang". Menagih ulang
 *    resep bulan lalu dengan tarif embalase hari ini adalah kesalahan yang
 *    tidak akan terlihat oleh siapa pun yang memeriksa, karena hasilnya
 *    tetap berupa angka yang wajar.
 *
 * 2. NILAI KOSONG BUKAN NOL. Pengaturan yang belum pernah ditetapkan
 *    RSP UI mengembalikan null, dan pemanggil harus memutuskan apa artinya.
 *    Kalau ia dijadikan nol diam-diam, embalase yang belum diputuskan akan
 *    tertagih sebagai gratis dan tidak ada yang tahu bahwa itu bukan
 *    keputusan siapa pun.
 */
class SettingStore
{
    /** @var array<string, Setting> */
    private array $cache = [];

    public function setting(string $kunci): Setting
    {
        return $this->cache[$kunci] ??= Setting::query()->where('key', $kunci)->firstOr(
            fn () => throw new RuntimeException('Pengaturan "'.$kunci.'" tidak dikenal.')
        );
    }

    /**
     * Nilai berjalan, sudah dikembalikan ke tipenya. Null berarti BELUM
     * DITETAPKAN — bukan nol, bukan "tidak".
     */
    public function get(string $kunci): string|int|float|bool|null
    {
        $setting = $this->setting($kunci);

        return $this->tafsirkan($setting->value, $setting->value_type);
    }

    /**
     * Nilai yang berlaku pada satu tanggal.
     *
     * Dipakai setiap kali sebuah peristiwa lampau harus dihitung ulang —
     * tagihan, laporan, pemeriksaan selisih. Kalau tidak ada revisi yang
     * berlaku pada tanggal itu, jawabannya null: pengaturannya memang
     * belum ada saat itu, dan mengarang nilai untuk masa sebelum ia
     * ditetapkan berarti menagih dengan aturan yang belum berlaku.
     */
    public function valueAt(string $kunci, string|Carbon $tanggal): string|int|float|bool|null
    {
        $setting = $this->setting($kunci);
        $acuan = $tanggal instanceof Carbon ? $tanggal->toDateString() : $tanggal;

        $revisi = SettingRevision::query()
            ->where('setting_id', $setting->getKey())
            ->whereDate('effective_from', '<=', $acuan)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        return $revisi === null ? null : $this->tafsirkan($revisi->new_value, $setting->value_type);
    }

    /**
     * Mengubah nilai, selalu bersama riwayatnya.
     *
     * Nilai lama disalin ke revisi — bukan dibaca ulang belakangan dari
     * revisi sebelumnya — supaya rantainya tetap terbaca walau ada revisi
     * yang dihapus atau diimpor belakangan.
     */
    public function set(
        string $kunci,
        string|int|float|bool|null $nilai,
        ?int $olehId = null,
        ?string $olehNama = null,
        ?string $alasan = null,
        ?string $berlakuSejak = null,
    ): Setting {
        $setting = $this->setting($kunci);
        $baru = $this->bakukan($nilai, $setting);

        DB::transaction(function () use ($setting, $baru, $olehId, $olehNama, $alasan, $berlakuSejak) {
            SettingRevision::query()->create([
                'setting_id' => $setting->getKey(),
                'old_value' => $setting->value,
                'new_value' => $baru,
                'effective_from' => $berlakuSejak ?? now()->toDateString(),
                'changed_by' => $olehId,
                'changed_by_name' => $olehNama,
                'reason' => $alasan,
            ]);

            $setting->update(['value' => $baru]);
        });

        unset($this->cache[$kunci]);

        return $setting->refresh();
    }

    /**
     * Pengaturan yang kuncinya ada tapi nilainya belum ditetapkan.
     *
     * Daftar kejujuran: pertanyaannya sudah pasti, jawabannya belum. Yang
     * membedakannya dari diam adalah kuncinya ADA, sehingga layar
     * pengaturan menampilkannya sebagai pekerjaan yang belum selesai.
     *
     * @return list<string>
     */
    public function belumDitetapkan(): array
    {
        return Setting::query()->whereNull('value')->where('is_active', true)
            ->orderBy('group')->orderBy('key')->pluck('key')->all();
    }

    private function bakukan(string|int|float|bool|null $nilai, Setting $setting): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if ($setting->value_type === Setting::TIPE_BOOLEAN) {
            return $nilai ? '1' : '0';
        }

        $teks = (string) $nilai;

        /*
         * Pilihan diperiksa terhadap daftarnya. Tanpa ini, "harga dasar"
         * bisa terisi kata yang tidak dikenal mesin harga, dan yang terjadi
         * kemudian bukan galat melainkan harga yang salah diam-diam.
         */
        if ($setting->value_type === Setting::TIPE_PILIHAN) {
            $pilihan = $setting->options ?? [];

            if (! in_array($teks, $pilihan, true)) {
                throw new RuntimeException(
                    'Nilai "'.$teks.'" bukan pilihan yang sah untuk '.$setting->key
                    .'. Pilihan: '.implode(', ', $pilihan).'.'
                );
            }
        }

        if (in_array($setting->value_type, [Setting::TIPE_ANGKA, Setting::TIPE_UANG], true)
            && ! is_numeric($teks)) {
            throw new RuntimeException('Pengaturan '.$setting->key.' harus berupa angka.');
        }

        return $teks;
    }

    private function tafsirkan(?string $mentah, string $tipe): string|int|float|bool|null
    {
        if ($mentah === null) {
            return null;
        }

        return match ($tipe) {
            Setting::TIPE_BOOLEAN => $mentah === '1',
            Setting::TIPE_ANGKA => (int) $mentah,
            Setting::TIPE_UANG => (float) $mentah,
            default => $mentah,
        };
    }
}
