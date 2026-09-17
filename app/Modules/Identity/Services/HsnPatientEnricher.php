<?php

namespace App\Modules\Identity\Services;

use Generator;
use RuntimeException;

/**
 * Membaca demografi pasien dari ekspor `pasien.csv` sistem lama.
 *
 * KELAS INI TIDAK MENULIS APA PUN KE BASIS DATA — sama seperti
 * HsnPatientMigrator: ia membaca, menormalkan, memvalidasi, lalu
 * mengembalikan. Pemuatannya urusan pemanggil.
 *
 * MENGAPA ADA BERKAS KEDUA. Migrasi pertama memakai `Antrian`, satu-satunya
 * tabel berjembatan ke pasien pada ekspor awal — dan tabel antrean tidak
 * memuat NIK, tanggal lahir, alamat, maupun telepon. Delapan tabel yang
 * menyimpannya ikut terekspor sebagai header tanpa satu pun baris. `pasien.csv`
 * yang datang belakangan adalah tabel pasien yang sesungguhnya: 371.719 baris,
 * beririsan 100% dengan 348.880 pasien yang sudah dimuat.
 *
 * TANPA HEADER, PEMISAH TITIK KOMA. Baris pertama sudah data. Kolom dirujuk
 * per indeks, dan indeks itu diverifikasi terhadap isi sungguhan — bukan
 * ditebak dari nama berkas.
 */
class HsnPatientEnricher
{
    // Indeks kolom pada pasien.csv. Diverifikasi terhadap 371.719 baris.
    private const MRN = 0;

    private const NAMA = 1;

    private const NIK = 2;

    private const SEX = 3;

    private const TEMPAT_LAHIR = 4;

    private const TANGGAL_LAHIR = 5;

    private const ALAMAT = 7;

    private const GOL_DARAH = 8;

    private const PEKERJAAN = 9;

    private const STATUS_KAWIN = 10;

    private const AGAMA = 11;

    private const TERDAFTAR = 12;

    private const TELEPON = 13;

    private const PENDIDIKAN = 15;

    /**
     * Jenis kelamin sumber → kode internal.
     *
     * `M`/`F` (14 dan 8 baris) jelas male/female — ejaan Inggris yang
     * menyelinap masuk. `T` (30 baris) TIDAK dipetakan: nilainya tidak
     * bermakna jenis kelamin, dan contohnya saling bertentangan — ada nama
     * bersufiks `. NN` (Nona, perempuan) dan `. AN` (Anak) di dalamnya.
     * Menebaknya berarti menetapkan jenis kelamin 30 orang secara acak.
     */
    private const PETA_SEX = ['L' => 'L', 'P' => 'P', 'M' => 'L', 'F' => 'P'];

    /** Status kawin sumber (berbahasa Inggris) → istilah yang dipakai layar. */
    private const PETA_KAWIN = [
        'Maried' => 'Kawin',
        'Single' => 'Belum kawin',
        'Widow' => 'Janda',
        'Widower' => 'Duda',
        'Not Known' => null,
    ];

    /** Golongan darah yang sah. Rhesus tidak ada di sumber. */
    private const GOL_DARAH_SAH = ['A', 'B', 'AB', 'O'];

    /**
     * Nilai yang berarti KOSONG di ekspor ini.
     *
     * Sumber memakai "-" sebagai penanda kosong, bukan string kosong. Disimpan
     * apa adanya, "-" akan muncul di layar sebagai alamat dan nomor telepon
     * pasien.
     */
    private const KOSONG = ['', '-', 'NULL', 'null', 'Not Known', '0'];

    /**
     * Pemisah baris CSV.
     *
     * $escape DIBERIKAN EKSPLISIT sebagai string kosong, bukan dibiarkan
     * memakai bawaan. PHP 8.4 mendeprekasi bawaannya ("\\") dan akan
     * mengubahnya jadi "" pada versi berikutnya — tapi bukan itu alasan
     * utamanya: dengan escape "\\", sebuah alamat yang berakhir backslash
     * akan menelan pemisah berikutnya, dan seluruh kolom setelahnya bergeser
     * satu. Nomor telepon pasien berakhir di kolom pendidikan tanpa satu pun
     * galat muncul.
     */
    private const ESCAPE = '';

    public function __construct(private readonly string $berkas) {}

    /**
     * Membaca seluruh baris, satu demi satu.
     *
     * MENGEMBALIKAN GENERATOR. 371.719 baris berikut alamat panjangnya tidak
     * perlu ditampung sekaligus — pemanggil memprosesnya sambil jalan.
     *
     * NIK GANDA DITANDAI, BUKAN DIBUANG DIAM-DIAM. 2.581 NIK dipakai lebih
     * dari satu nomor rekam medis — lazimnya satu orang terdaftar dua kali,
     * atau NIK orang tua dipakai untuk anak yang belum punya KTP. Yang
     * pertama (nomor rekam medis paling awal) memegang NIK-nya; sisanya
     * masuk tanpa NIK dan dilaporkan, karena `patients_nik_unique` memang
     * harus tetap menjaga satu NIK satu pasien.
     *
     * @return Generator<int, array{
     *     medical_record_number: string, name: string, nik: ?string, sex: ?string,
     *     birth_place: ?string, birth_date: ?string, address: ?string,
     *     blood_type: ?string, occupation: ?string, marital_status: ?string,
     *     religion: ?string, phone: ?string, education: ?string,
     *     registered_on: ?string, nik_ditolak: ?string
     * }>
     */
    public function pasien(?int $batasBaris = null): Generator
    {
        $berkas = $this->buka();

        // Hanya NIK yang sudah terpakai yang diingat — 16 byte per pasien,
        // bukan seluruh barisnya.
        $nikTerpakai = [];
        $dibaca = 0;

        while (($baris = fgetcsv($berkas, 0, ';', '"', self::ESCAPE)) !== false) {
            if ($batasBaris !== null && ++$dibaca > $batasBaris) {
                break;
            }

            $mrn = $this->bersih($baris[self::MRN] ?? '');

            // BOM UTF-8 menempel pada baris pertama karena berkas tidak
            // berheader — tanpa dibuang, pasien pertama hilang.
            $mrn = preg_replace('/^\xEF\xBB\xBF/', '', (string) $mrn);

            if ($mrn === null || $mrn === '' || ! ctype_digit($mrn)) {
                continue;
            }

            $nama = $this->bersih($baris[self::NAMA] ?? '');

            if ($nama === null) {
                continue;
            }

            [$nik, $nikDitolak] = $this->nik($baris[self::NIK] ?? '', $mrn, $nikTerpakai);

            yield [
                'medical_record_number' => $mrn,
                'name' => $nama,
                'nik' => $nik,
                'nik_ditolak' => $nikDitolak,
                'sex' => $this->sex($baris[self::SEX] ?? ''),
                'birth_place' => $this->bersih($baris[self::TEMPAT_LAHIR] ?? ''),
                'birth_date' => $this->tanggal($baris[self::TANGGAL_LAHIR] ?? ''),
                'address' => $this->bersih($baris[self::ALAMAT] ?? ''),
                'blood_type' => $this->golDarah($baris[self::GOL_DARAH] ?? ''),
                'occupation' => $this->bersih($baris[self::PEKERJAAN] ?? ''),
                'marital_status' => $this->statusKawin($baris[self::STATUS_KAWIN] ?? ''),
                'religion' => $this->bersih($baris[self::AGAMA] ?? ''),
                'phone' => $this->telepon($baris[self::TELEPON] ?? ''),
                'education' => $this->bersih($baris[self::PENDIDIKAN] ?? ''),
                'registered_on' => $this->tanggal($baris[self::TERDAFTAR] ?? ''),
            ];
        }

        fclose($berkas);
    }

    /**
     * NIK yang sah dan belum dipakai pasien lain.
     *
     * @param  array<string, string>  $terpakai  NIK → nomor rekam medis pemegangnya
     * @return array{0: ?string, 1: ?string} [NIK, atau null; NIK yang ditolak berikut alasannya]
     */
    private function nik(string $mentah, string $mrn, array &$terpakai): array
    {
        $nik = trim($mentah);

        if (in_array($nik, self::KOSONG, true)) {
            return [null, null];
        }

        /*
         * 710 nilai bukan NIK sama sekali — nomor SIM, NPM mahasiswa, kartu
         * tanda mahasiswa ("SIM-7…", "NPM__…", "KTM-1…"), dan beberapa
         * 15 digit. Kolomnya memang dipakai untuk "nomor identitas apa saja",
         * bukan khusus NIK. Disimpan sebagai NIK, nomor SIM akan ikut dicari
         * saat petugas mengetikkan NIK pasien lain.
         */
        if (! preg_match('/^[0-9]{16}$/', $nik)) {
            return [null, 'bukan-16-digit'];
        }

        if (isset($terpakai[$nik])) {
            return [null, 'sudah-dipakai-mrn-'.$terpakai[$nik]];
        }

        $terpakai[$nik] = $mrn;

        return [$nik, null];
    }

    private function sex(string $mentah): ?string
    {
        return self::PETA_SEX[strtoupper(trim($mentah))] ?? null;
    }

    private function statusKawin(string $mentah): ?string
    {
        $v = trim($mentah);

        if (in_array($v, self::KOSONG, true)) {
            return null;
        }

        return self::PETA_KAWIN[$v] ?? $v;
    }

    private function golDarah(string $mentah): ?string
    {
        $v = strtoupper(trim($mentah));

        return in_array($v, self::GOL_DARAH_SAH, true) ? $v : null;
    }

    /**
     * Nomor telepon yang sungguh bisa dihubungi.
     *
     * 2,6% isinya pecahan — "3 digit", "4 digit", kadang hanya "0". Nomor
     * yang tidak bisa dihubungi lebih buruk daripada kolom kosong: petugas
     * mencoba menghubunginya saat hasil kritis keluar, lalu menyangka pasien
     * tidak bisa dijangkau padahal nomornya memang tidak pernah ada.
     */
    private function telepon(string $mentah): ?string
    {
        $v = trim($mentah);

        if (in_array($v, self::KOSONG, true)) {
            return null;
        }

        $angka = preg_replace('/[^0-9+]/', '', $v);

        if ($angka === null || $angka === '') {
            return null;
        }

        // HP Indonesia, +62, atau telepon rumah — minimal 9 digit.
        $cocok = preg_match('/^(08[0-9]{7,12}|\+?62[0-9]{8,13}|0[1-7][0-9]{7,11})$/', $angka);

        return $cocok ? mb_substr($v, 0, 40) : null;
    }

    private function tanggal(string $mentah): ?string
    {
        $v = trim($mentah);

        if (! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})/', $v, $cocok)) {
            return null;
        }

        $tahun = (int) $cocok[1];

        // Tanggal di luar rentang masuk akal berarti rusak, bukan pasien tua.
        if ($tahun < 1900 || $tahun > (int) date('Y') + 1) {
            return null;
        }

        return $cocok[1].'-'.$cocok[2].'-'.$cocok[3];
    }

    private function bersih(string $mentah): ?string
    {
        $v = trim($mentah);

        return in_array($v, self::KOSONG, true) ? null : $v;
    }

    /**
     * @return resource
     */
    private function buka()
    {
        if (! is_file($this->berkas)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$this->berkas}");
        }

        $f = fopen($this->berkas, 'r');

        if ($f === false) {
            throw new RuntimeException("Tidak bisa membuka: {$this->berkas}");
        }

        return $f;
    }
}
