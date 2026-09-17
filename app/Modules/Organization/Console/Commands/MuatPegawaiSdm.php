<?php

namespace App\Modules\Organization\Console\Commands;

use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Services\SdmClassifier;
use App\Modules\Organization\Services\SdmWorkbookReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Memuat data kepegawaian resmi RSUI ke organization.practitioners.
 *
 * SUMBER INI LEBIH DAPAT DIPERCAYA daripada ekspor sistem lama. `nakes.csv`
 * memuat 6.825 orang tanpa NIP, tanpa unit kerja, dan tanpa jabatan — dua
 * pertiganya mahasiswa dan residen. Berkas ini memuat 1.353 pegawai berikut
 * NIP asli, 66 unit kerja, 244 jabatan, status kepegawaian, dan kategori staf
 * yang ditetapkan RSUI sendiri; ditambah 337 dokter berikut KSM, konsultan,
 * dan tanggal mulai bertugas.
 *
 * DUA LEMBAR DIGABUNG LEWAT NIP. 166 dokter juga tercatat sebagai pegawai;
 * pada NIP yang beririsan, 165 namanya cocok persis. Penggabungan lewat NIP
 * aman — 171 sisanya dokter mitra yang memang bukan pegawai RSUI.
 *
 * PENAUTAN KE PRAKTISI LAMA MEMAKAI NAMA, DAN ITU TIDAK PASTI. Tidak ada
 * pengenal bersama antara berkas ini (NIP) dan ekspor lama (ID internal HSN).
 * Yang namanya cocok persis ditautkan; yang cocok ke LEBIH DARI SATU praktisi
 * tidak ditautkan sama sekali, melainkan dilaporkan. Pada data nyata ada 11
 * kasus begitu, dan satu di antaranya benar-benar dua orang berbeda yang
 * kebetulan bernama sama. Menebak di situ berarti menggabungkan rekam dua
 * orang.
 *
 * SELURUH KEPUTUSAN PENAUTAN DITULIS KE BERKAS LAPORAN supaya petugas SDM
 * bisa memverifikasi — bukan disembunyikan di dalam basis data.
 */
class MuatPegawaiSdm extends Command
{
    protected $signature = 'organization:muat-pegawai-sdm
        {--berkas= : Path berkas xlsx; bawaan database/data sdm.xlsx}
        {--lembar-pegawai=ALL_AGS26 : Nama lembar data pegawai}
        {--lembar-dokter=MED AGS26 : Nama lembar data dokter}
        {--lihat-saja : Tampilkan ringkasan tanpa menulis apa pun}';

    protected $description = 'Memuat pegawai & dokter RSUI dari berkas kepegawaian, digabung lewat NIP';

    // Kolom lembar pegawai (ALL_AGS26).
    private const P_NIP = 'B';

    private const P_NAMA = 'C';

    private const P_NAMA_GELAR = 'D';

    private const P_STATUS_MASUK = 'E';

    private const P_STATUS_PEG = 'F';

    private const P_KATEGORI = 'G';

    private const P_UNIT = 'H';

    private const P_JABATAN = 'K';

    // Kolom lembar dokter (MED AGS26).
    private const D_NIP = 'B';

    private const D_NAMA = 'C';

    private const D_NAMA_GELAR = 'D';

    private const D_STATUS_RSUI = 'F';

    private const D_TMT = 'G';

    private const D_KSM = 'I';

    private const D_PENYEBUTAN = 'J';

    private const D_KONSULTAN = 'K';

    private const D_STATUS_PRAKTIK = 'L';

    public function handle(SdmClassifier $klasifikasi): int
    {
        $berkas = $this->option('berkas') ?: database_path('data sdm.xlsx');

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $lihatSaja = (bool) $this->option('lihat-saja');

        try {
            $reader = new SdmWorkbookReader($berkas);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Lembar tersedia: '.implode(', ', $reader->daftarLembar()));
        $this->newLine();

        $orang = $this->kumpulkan($reader, $klasifikasi);

        $this->line('Pegawai + dokter, digabung lewat NIP: '.number_format(count($orang)));
        $this->newLine();

        $hasil = $this->tulis($orang, $klasifikasi, $lihatSaja);

        $this->ringkasan($orang, $hasil, $lihatSaja);

        if (! $lihatSaja) {
            $this->tulisLaporan($hasil);
        }

        return self::SUCCESS;
    }

    /**
     * Membaca kedua lembar dan menggabungkannya per NIP.
     *
     * @return array<string, array<string, mixed>>
     */
    private function kumpulkan(SdmWorkbookReader $reader, SdmClassifier $k): array
    {
        $orang = [];

        foreach ($reader->baris($this->option('lembar-pegawai')) as $b) {
            $nip = trim($b[self::P_NIP] ?? '');
            $nama = trim($b[self::P_NAMA] ?? '');

            if ($nip === '' || $nama === '') {
                continue;
            }

            $g = $k->golongkanPegawai($b[self::P_KATEGORI] ?? '', $b[self::P_JABATAN] ?? '');

            $orang[$nip] = [
                'nip' => $nip,
                'nama' => $nama,
                'gelar' => $k->gelarDepan($b[self::P_NAMA_GELAR] ?? ''),
                'category' => $g['category'],
                'support_type' => $g['support_type'],
                'unit' => trim($b[self::P_UNIT] ?? '') ?: null,
                'jabatan' => trim($b[self::P_JABATAN] ?? '') ?: null,
                'status_masuk' => trim($b[self::P_STATUS_MASUK] ?? '') ?: null,
                'status_pegawai' => trim($b[self::P_STATUS_PEG] ?? '') ?: null,
                'spesialisasi' => null,
                'dpjp' => false,
                'tmt' => null,
                'sumber' => 'pegawai',
            ];
        }

        foreach ($reader->baris($this->option('lembar-dokter')) as $b) {
            $nip = trim($b[self::D_NIP] ?? '');
            $nama = trim($b[self::D_NAMA] ?? '');

            if ($nip === '' || $nama === '') {
                continue;
            }

            $praktik = trim($b[self::D_STATUS_PRAKTIK] ?? '');

            $tambahan = [
                'spesialisasi' => $k->spesialisasiDokter($b[self::D_PENYEBUTAN] ?? ''),
                'unit' => trim($b[self::D_KSM] ?? '') ?: ($orang[$nip]['unit'] ?? null),
                'jabatan' => trim($b[self::D_PENYEBUTAN] ?? '') ?: ($orang[$nip]['jabatan'] ?? null),
                'konsultan' => trim($b[self::D_KONSULTAN] ?? '') ?: null,
                'tmt' => $reader->tanggal($b[self::D_TMT] ?? ''),
                'dpjp' => $k->bolehJadiDpjp($praktik),
                'status_pegawai' => trim($b[self::D_STATUS_RSUI] ?? '') ?: ($orang[$nip]['status_pegawai'] ?? null),
            ];

            if (isset($orang[$nip])) {
                // Sudah ada sebagai pegawai — diperkaya, kategorinya dokter.
                $orang[$nip] = array_merge($orang[$nip], $tambahan, [
                    'category' => SdmClassifier::DOKTER,
                    'support_type' => null,
                    'sumber' => 'pegawai+dokter',
                ]);

                continue;
            }

            // Dokter mitra: tidak ada di daftar pegawai.
            $orang[$nip] = array_merge([
                'nip' => $nip,
                'nama' => $nama,
                'gelar' => $k->gelarDepan($b[self::D_NAMA_GELAR] ?? ''),
                'category' => SdmClassifier::DOKTER,
                'support_type' => null,
                'status_masuk' => null,
                'sumber' => 'dokter-mitra',
            ], $tambahan);
        }

        return $orang;
    }

    /**
     * @param  array<string, array<string, mixed>>  $orang
     * @return array<string, mixed>
     */
    private function tulis(array $orang, SdmClassifier $k, bool $lihatSaja): array
    {
        // Indeks nama praktisi yang sudah ada, untuk mencari kandidat tautan.
        $indeks = [];

        foreach (DB::table('organization.practitioners')->get(['id', 'code', 'name', 'staff_kind']) as $p) {
            $kunci = $k->kunciNama($p->name);

            if ($kunci !== '') {
                $indeks[$kunci][] = $p;
            }
        }

        $nipTerpakai = DB::table('organization.practitioners')
            ->whereNotNull('employee_number')
            ->pluck('id', 'employee_number')
            ->all();

        $h = [
            'ditautkan' => 0, 'baru' => 0, 'diperbarui' => 0, 'gagal' => 0,
            'ambigu' => [], 'tautan' => [], 'galat' => [],
        ];

        foreach ($orang as $nip => $o) {
            $data = [
                'name' => mb_substr($o['nama'], 0, 150),
                'title' => $o['gelar'],
                'specialty' => $o['spesialisasi'] !== null ? mb_substr($o['spesialisasi'], 0, 100) : null,
                'category' => $o['category'],
                'support_type' => $o['support_type'],
                'position' => $o['jabatan'] !== null ? mb_substr($o['jabatan'], 0, 120) : null,
                'unit_name' => $o['unit'] !== null ? mb_substr($o['unit'], 0, 150) : null,
                'employment_status' => $o['status_pegawai'] !== null ? mb_substr($o['status_pegawai'], 0, 60) : null,
                'entry_status' => $o['status_masuk'] !== null ? mb_substr($o['status_masuk'], 0, 30) : null,
                'employee_number' => mb_substr($nip, 0, 30),
                'is_active' => true,
                'active_from' => $o['tmt'] ?? null,
            ];

            /*
             * staff_kind SELALU 'pegawai' dari sumber ini — termasuk dokter
             * mitra. Berkas ini daftar ketenagaan resmi; tidak ada mahasiswa
             * maupun residen di dalamnya. Yang menentukan boleh-tidaknya jadi
             * DPJP adalah kategori dan status praktik, bukan status kemitraan.
             */
            $data['staff_kind'] = 'pegawai';

            if ($lihatSaja) {
                continue;
            }

            try {
                // Sudah pernah dimuat dari sumber ini?
                if (isset($nipTerpakai[$nip])) {
                    Practitioner::query()->whereKey($nipTerpakai[$nip])->update($data);
                    $h['diperbarui']++;

                    continue;
                }

                $kunci = $k->kunciNama($o['nama']);
                $kandidat = $indeks[$kunci] ?? [];

                if (count($kandidat) > 1) {
                    /*
                     * Lebih dari satu praktisi bernama sama. TIDAK ditautkan —
                     * pada data nyata satu kasus benar-benar dua orang
                     * berbeda, dan menebak berarti menggabungkan rekam dua
                     * orang jadi satu.
                     */
                    $h['ambigu'][] = $nip.';'.count($kandidat).';'
                        .implode('|', array_map(fn ($p) => $p->code, $kandidat));

                    Practitioner::query()->create($data + ['code' => 'SDM-'.$nip]);
                    $h['baru']++;

                    continue;
                }

                if (count($kandidat) === 1) {
                    $p = $kandidat[0];
                    Practitioner::query()->whereKey($p->id)->update($data);
                    $h['ditautkan']++;
                    $h['tautan'][] = $nip.';'.$p->code.';'.$p->staff_kind;

                    // Jangan tertaut dua kali bila ada NIP lain bernama sama.
                    unset($indeks[$kunci]);

                    continue;
                }

                Practitioner::query()->create($data + ['code' => 'SDM-'.$nip]);
                $h['baru']++;
            } catch (Throwable $e) {
                $h['gagal']++;

                if (count($h['galat']) < 10) {
                    $h['galat'][] = $nip.': '.mb_substr($e->getMessage(), 0, 110);
                }
            }
        }

        return $h;
    }

    /**
     * @param  array<string, array<string, mixed>>  $orang
     * @param  array<string, mixed>  $h
     */
    private function ringkasan(array $orang, array $h, bool $lihatSaja): void
    {
        $kat = [];
        $sup = [];
        $sumber = [];
        $unit = [];

        foreach ($orang as $o) {
            $kat[$o['category']] = ($kat[$o['category']] ?? 0) + 1;
            $sumber[$o['sumber']] = ($sumber[$o['sumber']] ?? 0) + 1;

            if ($o['support_type'] !== null) {
                $sup[$o['support_type']] = ($sup[$o['support_type']] ?? 0) + 1;
            }

            if (($o['unit'] ?? null) !== null) {
                $unit[$o['unit']] = true;
            }
        }

        $this->line('KATEGORI:');
        arsort($kat);

        foreach ($kat as $k => $v) {
            $this->line(sprintf('   %-12s %s', $k, number_format($v)));
        }

        $this->newLine();
        $this->line('RINCIAN PENUNJANG:');
        arsort($sup);

        foreach ($sup as $k => $v) {
            $this->line(sprintf('   %-16s %s', $k, number_format($v)));
        }

        $this->newLine();
        $this->line('ASAL DATA:');
        arsort($sumber);

        foreach ($sumber as $k => $v) {
            $this->line(sprintf('   %-16s %s', $k, number_format($v)));
        }

        $this->line('   unit kerja unik : '.number_format(count($unit)));

        if ($lihatSaja) {
            $this->newLine();
            $this->comment('Mode lihat-saja — tidak ada yang ditulis.');

            return;
        }

        $this->newLine();
        $this->line('PENULISAN:');
        $this->line('   Ditautkan ke praktisi lama : '.number_format($h['ditautkan']));
        $this->line('   Praktisi baru              : '.number_format($h['baru']));
        $this->line('   Diperbarui (NIP sudah ada) : '.number_format($h['diperbarui']));
        $this->line('   Gagal                      : '.number_format($h['gagal']));

        if ($h['ambigu'] !== []) {
            $this->newLine();
            $this->warn(count($h['ambigu']).' nama cocok ke LEBIH DARI SATU praktisi — tidak ditautkan.');
            $this->line('   Dimuat sebagai baris baru berkode SDM-<nip>. Perlu ditinjau petugas SDM:');
            $this->line('   sebagian memang orang yang sama tercatat dua kali, sebagian orang berbeda');
            $this->line('   yang kebetulan bernama sama.');
        }

        if ($h['galat'] !== []) {
            $this->newLine();
            $this->warn('Contoh kegagalan:');

            foreach ($h['galat'] as $g) {
                $this->line('   '.$g);
            }
        }
    }

    /** @param array<string, mixed> $h */
    private function tulisLaporan(array $h): void
    {
        $stamp = date('Ymd-His');

        if ($h['tautan'] !== []) {
            $path = storage_path('app/sdm-tautan-'.$stamp.'.csv');
            file_put_contents($path, "nip;code_praktisi_lama;staff_kind_lama\n".implode("\n", $h['tautan'])."\n");
            $this->newLine();
            $this->line('Laporan penautan : '.$path);
        }

        if ($h['ambigu'] !== []) {
            $path = storage_path('app/sdm-ambigu-'.$stamp.'.csv');
            file_put_contents($path, "nip;jumlah_kandidat;kode_kandidat\n".implode("\n", $h['ambigu'])."\n");
            $this->line('Laporan ambigu   : '.$path);
        }
    }
}
