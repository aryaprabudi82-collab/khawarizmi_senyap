<?php

namespace App\Modules\Identity\Services;

use Generator;
use RuntimeException;

/**
 * Membaca pasien dari ekspor CSV HSN dan menyiapkannya untuk identity.patients.
 *
 * KELAS INI TIDAK MENULIS APA PUN KE BASIS DATA. Ia membaca, menormalkan, dan
 * mengembalikan — pemuatannya urusan pemanggil lewat PatientRegistry. Pemisahan
 * ini disengaja: menggabungkan pembacaan 14 GB CSV dengan penulisan basis data
 * membuat keduanya tidak bisa diuji sendiri-sendiri, dan yang gagal di tengah
 * meninggalkan keadaan yang tidak jelas sudah sampai mana.
 *
 * SUMBERNYA `Antrian`, BUKAN TABEL PASIEN — karena ekspor HSN tidak punya tabel
 * pasien. Tidak ada `Patient`, `Person`, maupun `Pasien` di antara 129 berkas;
 * identitas pasien hanya hidup sebagai kolom di tabel antrean. Yang membuat ini
 * tetap dapat dipercaya: `RekamMedik` ↔ `PersonKey` terbukti 1:1 sempurna
 * (348.880 ↔ 348.880, nol bentrok dua arah) pada seluruh 1.358.156 baris
 * ber-pasien.
 */
class HsnPatientMigrator
{
    /**
     * Sufiks gelar yang menandai jenis kelamin.
     *
     * Divalidasi terhadap 10.076 baris yang punya nama bersufiks DAN jenis
     * kelamin sungguhan di `BillingPenjaminHeader`: 10.073 cocok, 3 meleset —
     * akurasi 99,97%. NY/NN bahkan nol kesalahan.
     */
    private const SUFIKS_PEREMPUAN = ['NY', 'NN'];

    private const SUFIKS_LAKI = ['TN'];

    /**
     * Sufiks yang TIDAK menentukan jenis kelamin.
     *
     * `AN` (Anak) dan `BY` (Bayi) menandai kelompok umur. Menyimpulkan gender
     * darinya berarti mengarang — dan pada anak, jenis kelamin yang salah
     * menggeser seluruh rentang rujukan pertumbuhan.
     */
    private const SUFIKS_BUKAN_GENDER = ['AN', 'BY'];

    public function __construct(private readonly string $berkasAntrian) {}

    /**
     * Membaca seluruh baris dan mengumpulkannya jadi satu baris per pasien.
     *
     * MENGEMBALIKAN GENERATOR, bukan array. 1,87 juta baris yang ditampung
     * sekaligus sebagai array asosiatif menghabiskan memori jauh sebelum
     * selesai; pemanggil memprosesnya sambil jalan.
     *
     * @return Generator<int, array{
     *     person_key: string, medical_record_number: string, name: string,
     *     sex: ?string, sumber_sex: string, registered_on: string,
     *     nama_asli: string, sufiks: ?string
     * }>
     */
    public function pasienUnik(?int $batasBaris = null): Generator
    {
        $berkas = $this->buka();
        $header = fgetcsv($berkas);

        if ($header === false) {
            throw new RuntimeException("Berkas kosong: {$this->berkasAntrian}");
        }

        $iPk = $this->kolom($header, 'PersonKey');
        $iRm = $this->kolom($header, 'RekamMedik');
        $iNama = $this->kolom($header, 'NamaPasien');
        $iTgl = $this->kolom($header, 'TanggalAntrian');

        /*
         * Dikumpulkan di memori sebagai satu baris per PersonKey — bukan per
         * baris antrean. 348.880 pasien × beberapa field masih wajar; 1,87 juta
         * baris mentah tidak.
         *
         * Nama disimpan yang TERPANJANG. 202.212 baris punya nama berbeda untuk
         * PersonKey yang sama, dan 78% di antaranya karena sufiks gelar
         * ditambahkan belakangan — varian terpanjang adalah yang paling lengkap,
         * sekaligus satu-satunya yang membawa penanda gender.
         */
        $pasien = [];
        $dibaca = 0;

        while (($baris = fgetcsv($berkas)) !== false) {
            /*
             * Batas diterapkan di sini — pada PEMBACAAN, bukan pada hasil.
             * Membatasi di pemanggil tidak menolong: seluruh berkas sudah
             * terlanjur dibaca dan 348.880 pasien sudah terkumpul di memori
             * sebelum baris pertama di-yield.
             */
            if ($batasBaris !== null && ++$dibaca > $batasBaris) {
                break;
            }

            $pk = trim($baris[$iPk] ?? '');
            $rm = trim($baris[$iRm] ?? '');
            $nama = trim($baris[$iNama] ?? '');
            $tgl = trim($baris[$iTgl] ?? '');

            // 27,5% baris hanya antrean tanpa pasien — dilewati, bukan diberi
            // nilai kosong. Pasien tanpa nomor rekam medis bukan pasien.
            if ($pk === '' || $rm === '' || $nama === '') {
                continue;
            }

            if (! isset($pasien[$pk])) {
                $pasien[$pk] = [
                    'person_key' => $pk,
                    'medical_record_number' => $rm,
                    'nama_asli' => $nama,
                    'tanggal_awal' => $tgl,
                ];

                continue;
            }

            if (mb_strlen($nama) > mb_strlen($pasien[$pk]['nama_asli'])) {
                $pasien[$pk]['nama_asli'] = $nama;
            }

            // Tanggal terdaftar = kunjungan PERTAMA, bukan yang terakhir dibaca.
            if ($tgl !== '' && ($pasien[$pk]['tanggal_awal'] === '' || $tgl < $pasien[$pk]['tanggal_awal'])) {
                $pasien[$pk]['tanggal_awal'] = $tgl;
            }
        }

        fclose($berkas);

        foreach ($pasien as $p) {
            [$namaBersih, $sufiks] = $this->pisahkanSufiks($p['nama_asli']);
            [$sex, $sumberSex] = $this->simpulkanSex($sufiks);

            yield [
                'person_key' => $p['person_key'],
                'medical_record_number' => $p['medical_record_number'],
                'name' => $namaBersih,
                'nama_asli' => $p['nama_asli'],
                'sufiks' => $sufiks,
                'sex' => $sex,
                'sumber_sex' => $sumberSex,
                'registered_on' => $p['tanggal_awal'] !== '' ? substr($p['tanggal_awal'], 0, 10) : null,
            ];
        }
    }

    /**
     * Memisahkan sufiks gelar dari nama.
     *
     * Bentuknya beragam di data nyata: ". NY", ".NY", ", NY", " NY", ". TN.".
     * Yang diambil hanya sufiks di UJUNG nama — "NY" di tengah nama orang
     * (mis. nama yang memang mengandung suku kata itu) tidak boleh ikut
     * terpotong.
     *
     * DIPOTONG BERULANG, karena data sumber memuat sufiks GANDA: ". NY. NY",
     * ". AN. AN", ". TN. TN" — penanda yang tertulis dua kali, agaknya karena
     * sufiks ditambahkan lagi pada nama yang sudah bersufiks. Memotong sekali
     * menyisakan yang kedua menempel pada nama pasien, dan itu terbawa ke
     * setiap gelang identitas dan label spesimen yang dicetak. Ditemukan pada
     * 9 dari 6.299 pasien contoh (0,14%) — kecil, tapi tidak ada alasan
     * membiarkannya.
     *
     * Sufiks yang dikembalikan adalah yang TERLUAR (dipotong pertama). Pada
     * sufiks ganda keduanya selalu sama, jadi tidak ada yang hilang.
     *
     * @return array{0: string, 1: ?string} [nama bersih, sufiks atau null]
     */
    public function pisahkanSufiks(string $nama): array
    {
        $daftar = implode('|', array_merge(
            self::SUFIKS_PEREMPUAN,
            self::SUFIKS_LAKI,
            self::SUFIKS_BUKAN_GENDER,
        ));

        $bersih = $nama;
        $sufiks = null;

        while (preg_match('/^(.*?)[\s.,]+('.$daftar.')\.?$/iu', $bersih, $cocok)) {
            $calon = trim($cocok[1], " \t.,");

            // Nama yang habis setelah sufiks dipotong berarti sufiks itu
            // sebenarnya namanya sendiri — hentikan, pertahankan yang ada.
            if ($calon === '') {
                break;
            }

            $bersih = $calon;
            $sufiks ??= mb_strtoupper($cocok[2]);
        }

        return $sufiks === null ? [$nama, null] : [$bersih, $sufiks];
    }

    /**
     * Menyimpulkan jenis kelamin dari sufiks gelar.
     *
     * @return array{0: ?string, 1: string} [sex atau null, asal-usul nilainya]
     */
    public function simpulkanSex(?string $sufiks): array
    {
        if ($sufiks === null) {
            return [null, 'tidak-diketahui:tanpa-sufiks'];
        }

        if (in_array($sufiks, self::SUFIKS_PEREMPUAN, true)) {
            return ['P', 'sufiks-nama:'.$sufiks];
        }

        if (in_array($sufiks, self::SUFIKS_LAKI, true)) {
            return ['L', 'sufiks-nama:'.$sufiks];
        }

        // AN/BY — sufiks ada, tapi tidak menyatakan gender.
        return [null, 'tidak-diketahui:sufiks-umur:'.$sufiks];
    }

    /** Nomor rekam medis tertinggi, untuk memajukan sequence setelah migrasi. */
    public function mrnTertinggi(): int
    {
        $berkas = $this->buka();
        $header = fgetcsv($berkas);
        $iRm = $this->kolom($header, 'RekamMedik');

        $maks = 0;

        while (($baris = fgetcsv($berkas)) !== false) {
            $rm = trim($baris[$iRm] ?? '');

            if ($rm !== '' && ctype_digit($rm)) {
                $maks = max($maks, (int) $rm);
            }
        }

        fclose($berkas);

        return $maks;
    }

    /**
     * @return resource
     */
    private function buka()
    {
        if (! is_file($this->berkasAntrian)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$this->berkasAntrian}");
        }

        $berkas = fopen($this->berkasAntrian, 'r');

        if ($berkas === false) {
            throw new RuntimeException("Tidak bisa membuka: {$this->berkasAntrian}");
        }

        return $berkas;
    }

    /**
     * @param  list<string>  $header
     */
    private function kolom(array $header, string $nama): int
    {
        $i = array_search($nama, $header, true);

        if ($i === false) {
            throw new RuntimeException("Kolom '{$nama}' tidak ada di berkas HSN.");
        }

        return $i;
    }
}
