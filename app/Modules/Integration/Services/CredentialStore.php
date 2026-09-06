<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\IntegrationCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penyimpan kredensial integrasi — penghubung antara formulir dan adapter.
 *
 * INILAH YANG MEMBUAT "TINGGAL INPUT" BENAR-BENAR CUKUP. Provider bertanya
 * ke kelas ini, bukan ke config, sehingga mengisi formulir langsung
 * mengaktifkan adapter asli tanpa penerapan ulang aplikasi dan tanpa
 * menyentuh berkas .env di server.
 *
 * URUTAN PEMBACAAN: basis data lebih dulu, config/.env sebagai cadangan.
 * Urutan ini disengaja — pemasangan yang terlanjur memakai .env tidak
 * mendadak berhenti bekerja saat rumah ini dipasang, dan begitu nilainya
 * diisi lewat layar, yang di layar itulah yang menang.
 *
 * NILAI DI-CACHE PER PERMINTAAN, bukan lebih lama. Kredensial dibaca
 * berkali-kali dalam satu permintaan HTTP (tiap adapter), dan mendekripsi
 * berulang itu mahal. Tapi cache yang bertahan antar permintaan berarti
 * kredensial yang baru diganti belum berlaku sampai proses di-restart —
 * persis masalah yang rumah ini dibangun untuk menghilangkannya.
 *
 * RAHASIA TIDAK PERNAH DIKEMBALIKAN UTUH ke lapisan tampilan. Yang untuk
 * layar disediakan summary(), yang cuma membawa penanda terisi dan empat
 * huruf terakhirnya.
 */
class CredentialStore
{
    /** @var array<string, array<string, ?string>> */
    private array $cache = [];

    /**
     * Nilai satu kolom: basis data lebih dulu, config sebagai cadangan.
     */
    public function get(string $system, string $field): ?string
    {
        if (! isset($this->cache[$system])) {
            $this->cache[$system] = $this->loadFromDatabase($system);
        }

        $nilai = $this->cache[$system][$field] ?? null;

        if ($nilai !== null && $nilai !== '') {
            return $nilai;
        }

        $legacy = IntegrationRegistry::legacyConfigKey($system, $field);

        return $legacy === null ? null : (config($legacy) ?: null);
    }

    /**
     * Menyimpan beberapa kolom sekaligus.
     *
     * Kolom rahasia yang dikirim KOSONG tidak menimpa nilai lama — layar
     * tidak pernah menampilkan rahasianya, jadi mengosongkan kotaknya
     * berarti "jangan diubah", bukan "hapus". Menghapus disediakan
     * tersendiri lewat forget(), supaya penghapusan selalu disengaja.
     *
     * @param  array<string, ?string>  $values
     *
     * @throws IntegrationException
     */
    public function put(string $system, array $values, ?int $actorId = null, ?string $actorName = null): int
    {
        IntegrationRegistry::system($system);

        $tersimpan = 0;

        foreach ($values as $field => $nilai) {
            if (! IntegrationRegistry::hasField($system, $field)) {
                throw new IntegrationException("Kolom '{$field}' tidak dikenal pada sistem {$system}.");
            }

            $nilai = $nilai === null ? null : trim($nilai);

            // Rahasia kosong = jangan diubah. Lihat catatan di atas.
            if (($nilai === null || $nilai === '') && IntegrationRegistry::isSecret($system, $field)) {
                continue;
            }

            IntegrationCredential::query()->updateOrCreate(
                ['system' => $system, 'field' => $field],
                [
                    'value' => $nilai === '' ? null : $nilai,
                    'tail' => $this->tail($nilai),
                    'updated_by' => $actorId,
                    'updated_by_name' => $actorName,
                ],
            );

            $tersimpan++;
        }

        unset($this->cache[$system]);

        return $tersimpan;
    }

    /**
     * Menghapus satu kolom — disengaja dan terpisah dari penyimpanan.
     *
     * @throws IntegrationException
     */
    public function forget(string $system, string $field): void
    {
        if (! IntegrationRegistry::hasField($system, $field)) {
            throw new IntegrationException("Kolom '{$field}' tidak dikenal pada sistem {$system}.");
        }

        IntegrationCredential::query()
            ->where('system', $system)
            ->where('field', $field)
            ->delete();

        unset($this->cache[$system]);
    }

    /**
     * Apakah seluruh kolom WAJIB sistem ini sudah terisi.
     *
     * Inilah yang menentukan adapter asli atau palsu yang dipakai. Sistem
     * yang setengah terisi dianggap BELUM siap — ia akan gagal pada
     * panggilan pertama dengan pesan yang tidak masuk akal, dan itu jauh
     * lebih membingungkan daripada sistem yang jelas belum disetel.
     */
    public function isReady(string $system): bool
    {
        foreach (IntegrationRegistry::requiredFields($system) as $field) {
            $nilai = $this->get($system, $field);

            if ($nilai === null || $nilai === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> kolom wajib yang masih kosong */
    public function missingFields(string $system): array
    {
        $kurang = [];

        foreach (IntegrationRegistry::requiredFields($system) as $field) {
            $nilai = $this->get($system, $field);

            if ($nilai === null || $nilai === '') {
                $kurang[] = $field;
            }
        }

        return $kurang;
    }

    /**
     * Ringkasan untuk layar. TIDAK memuat nilai rahasia.
     *
     * @return Collection<int, object>
     */
    public function summary(): Collection
    {
        $tersimpan = IntegrationCredential::query()->get()->groupBy('system');

        return collect(IntegrationRegistry::systems())->map(function ($def, $system) use ($tersimpan) {
            $baris = $tersimpan->get($system, collect())->keyBy('field');

            $kolom = collect($def['fields'])->map(function ($f, $field) use ($system, $baris) {
                $row = $baris->get($field);
                $dariConfig = $row === null && $this->get($system, $field) !== null;

                return (object) [
                    'field' => $field,
                    'label' => $f['label'],
                    'hint' => $f['hint'],
                    'secret' => $f['secret'],
                    'required' => $f['required'],
                    'filled' => $this->get($system, $field) !== null && $this->get($system, $field) !== '',
                    'from_env' => $dariConfig,
                    // Rahasia: cuma ekornya. Bukan rahasia: nilainya boleh
                    // tampil, karena base_url dan kode faskes memang perlu
                    // diperiksa mata.
                    'display' => $f['secret']
                        ? ($row?->tail ? '••••' . $row->tail : null)
                        : $this->get($system, $field),
                    'updated_by_name' => $row?->updated_by_name,
                    'updated_at' => $row?->updated_at,
                ];
            })->values();

            return (object) [
                'system' => $system,
                'label' => $def['label'],
                'description' => $def['description'],
                'doc' => $def['doc'],
                'ready' => $this->isReady($system),
                'missing' => $this->missingFields($system),
                'fields' => $kolom,
            ];
        })->values();
    }

    /** Berapa sistem yang sudah siap dipakai. */
    public function readyCount(): int
    {
        return collect(IntegrationRegistry::keys())
            ->filter(fn ($s) => $this->isReady($s))
            ->count();
    }

    /** Dipakai pengujian dan sesudah penyimpanan massal. */
    public function flush(): void
    {
        $this->cache = [];
    }

    /** @return array<string, ?string> */
    private function loadFromDatabase(string $system): array
    {
        // Tabelnya bisa belum ada saat migrasi pertama berjalan; jangan
        // sampai boot aplikasi gagal karenanya.
        if (! $this->tableExists()) {
            return [];
        }

        return IntegrationCredential::query()
            ->where('system', $system)
            ->get()
            ->mapWithKeys(fn ($c) => [$c->field => $c->value])
            ->all();
    }

    private function tableExists(): bool
    {
        static $ada = null;

        if ($ada === null) {
            try {
                $ada = (bool) DB::select(
                    "select 1 from information_schema.tables where table_schema = 'integration' and table_name = 'integration_credentials'"
                );
            } catch (\Throwable) {
                $ada = false;
            }
        }

        return $ada;
    }

    private function tail(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return substr($nilai, -4);
    }
}
