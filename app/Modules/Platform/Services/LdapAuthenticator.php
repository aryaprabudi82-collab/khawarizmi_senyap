<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Satu-satunya tempat aplikasi ini berbicara dengan Active Directory.
 *
 * POLA YANG DIPAKAI: ikat sebagai akun layanan, cari penggunanya, lalu IKAT
 * ULANG memakai DN pengguna beserta kata sandi yang ia ketik. Pengikatan
 * kedua itulah yang membuktikan kata sandinya benar — menemukan pengguna di
 * direktori tidak membuktikan apa pun, karena siapa saja bisa dicari.
 *
 * TIGA HAL YANG DITEGAKKAN DI SINI, dan ketiganya punya akibat nyata bila
 * dilewatkan:
 *
 * 1. KEANGGOTAAN GRUP, di dalam filter pencarian. Tanpa ini, setiap akun
 *    domain — termasuk akun tamu, akun vendor, akun mesin — bisa masuk ke
 *    sistem rekam medis. Sistem rujukan yang saya periksa justru tidak
 *    memeriksanya sama sekali.
 *
 * 2. ESCAPING FILTER. Nama pengguna masuk ke filter LDAP, dan nama pengguna
 *    datang dari formulir yang bisa diisi siapa saja. `ldap_escape()` yang
 *    terlewat di jalur login adalah celah yang bisa dipakai memutar
 *    pemeriksaan grup di butir 1.
 *
 * 3. AKUN YANG DINONAKTIFKAN DI AD. Pegawai yang sudah keluar biasanya
 *    di-disable, bukan dihapus, dan kata sandinya masih berlaku sampai
 *    kedaluwarsa. Tanpa pemeriksaan bit ini, ia masih bisa masuk.
 *
 * KEGAGALAN SENGAJA DIBUAT TIDAK MELEMPAR ke pemanggil. AD yang tak
 * terjangkau harus jatuh ke kata sandi lokal, bukan mengunci seluruh rumah
 * sakit — tapi ia DICATAT ke log, supaya "jatuh diam-diam" tidak berarti
 * tidak ada yang tahu AD sudah mati berhari-hari.
 */
class LdapAuthenticator
{
    /** Bit ACCOUNTDISABLE pada userAccountControl Active Directory. */
    private const AD_ACCOUNTDISABLE = 2;

    public function aktif(): bool
    {
        return (bool) config('ldap.enabled', false);
    }

    /**
     * Memverifikasi kredensial ke Active Directory.
     *
     * @return array{username: string, name: string, email: ?string, dn: string}|null
     *                                                                                null bila gagal, tidak berhak, atau AD tak terjangkau —
     *                                                                                pemanggil memperlakukan ketiganya sama: coba kata sandi lokal
     */
    public function coba(string $username, string $password): ?array
    {
        if (! $this->aktif()) {
            return null;
        }

        /*
         * Kata sandi kosong ditolak SEBELUM menyentuh direktori.
         *
         * LDAP memperlakukan bind berkata-sandi-kosong sebagai "anonymous
         * bind" dan mengembalikannya SUKSES pada banyak server. Tanpa
         * penjagaan ini, mengosongkan kolom kata sandi bisa meloloskan
         * siapa pun yang tahu sebuah nama pengguna.
         */
        if (trim($password) === '') {
            return null;
        }

        if (! extension_loaded('ldap')) {
            $this->catat('Ekstensi PHP ldap tidak terpasang; autentikasi AD dilewati.');

            return null;
        }

        $koneksi = null;

        try {
            $koneksi = $this->sambung();
            $pengguna = $this->cari($koneksi, $username);

            if ($pengguna === null) {
                // Tidak ditemukan ATAU bukan anggota grup — keduanya sengaja
                // tidak dibedakan di sini maupun di pesan galat. Membedakannya
                // memberi tahu penebak nama pengguna mana yang sungguh ada.
                return null;
            }

            if ($this->dinonaktifkanDiAd($pengguna)) {
                $this->catat("Akun '{$username}' dinonaktifkan di Active Directory; login ditolak.");

                return null;
            }

            // PENGIKATAN KEDUA: inilah pembuktian kata sandinya.
            if (! @ldap_bind($koneksi, $pengguna['dn'], $password)) {
                return null;
            }

            return [
                'username' => mb_strtolower($pengguna['samaccountname']),
                'name' => $pengguna['displayname'] ?: $pengguna['samaccountname'],
                'email' => $pengguna['mail'],
                'dn' => $pengguna['dn'],
            ];
        } catch (RuntimeException $e) {
            $this->catat('Autentikasi AD gagal, jatuh ke kata sandi lokal: '.$e->getMessage());

            return null;
        } finally {
            if ($koneksi !== null) {
                @ldap_unbind($koneksi);
            }
        }
    }

    /**
     * Mencari pengguna di direktori tanpa memverifikasi kata sandinya.
     *
     * Dipakai perintah pembuat akun administrator: akun dibuat hanya bila
     * orangnya memang ada di AD dan berhak, supaya tidak lahir akun yang
     * tidak akan pernah bisa dipakai masuk.
     *
     * @return array{username: string, name: string, email: ?string, dn: string}|null
     */
    public function cariPengguna(string $username): ?array
    {
        if (! extension_loaded('ldap')) {
            throw new RuntimeException('Ekstensi PHP ldap tidak terpasang.');
        }

        $koneksi = null;

        try {
            $koneksi = $this->sambung();
            $pengguna = $this->cari($koneksi, $username);

            if ($pengguna === null) {
                return null;
            }

            return [
                'username' => mb_strtolower($pengguna['samaccountname']),
                'name' => $pengguna['displayname'] ?: $pengguna['samaccountname'],
                'email' => $pengguna['mail'],
                'dn' => $pengguna['dn'],
            ];
        } finally {
            if ($koneksi !== null) {
                @ldap_unbind($koneksi);
            }
        }
    }

    // ------------------------------------------------------------- internal

    /**
     * @return resource|\LDAP\Connection
     *
     * @throws RuntimeException
     */
    private function sambung()
    {
        $host = (string) config('ldap.host');

        if ($host === '') {
            throw new RuntimeException('LDAP_HOST belum diisi.');
        }

        $skema = config('ldap.ssl') ? 'ldaps' : 'ldap';
        $port = (int) config('ldap.port', 389);

        $koneksi = @ldap_connect("{$skema}://{$host}:{$port}");

        if ($koneksi === false) {
            throw new RuntimeException("Tidak bisa menyiapkan koneksi ke {$host}:{$port}.");
        }

        ldap_set_option($koneksi, LDAP_OPT_PROTOCOL_VERSION, 3);

        /*
         * Referral dimatikan. Active Directory mengembalikan rujukan ke
         * pengendali domain lain saat mencari di root domain, dan klien PHP
         * mengikutinya TANPA membawa kredensial — hasilnya pencarian yang
         * gagal dengan galat yang menyesatkan.
         */
        ldap_set_option($koneksi, LDAP_OPT_REFERRALS, 0);

        $timeout = (int) config('ldap.timeout', 5);
        ldap_set_option($koneksi, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($koneksi, LDAP_OPT_TIMELIMIT, $timeout);

        if (config('ldap.tls')) {
            if (! @ldap_start_tls($koneksi)) {
                throw new RuntimeException('START TLS ditolak server: '.ldap_error($koneksi));
            }
        }

        $akunLayanan = (string) config('ldap.username');
        $sandiLayanan = (string) config('ldap.password');

        if (! @ldap_bind($koneksi, $akunLayanan, $sandiLayanan)) {
            throw new RuntimeException('Pengikatan akun layanan ditolak: '.ldap_error($koneksi));
        }

        return $koneksi;
    }

    /**
     * @param  resource|\LDAP\Connection  $koneksi
     * @return array{samaccountname: string, displayname: string, mail: ?string, dn: string, useraccountcontrol: int}|null
     *
     * @throws RuntimeException
     */
    private function cari($koneksi, string $username): ?array
    {
        $baseDn = (string) config('ldap.base_dn');

        if ($baseDn === '') {
            throw new RuntimeException('LDAP_BASE_DN belum diisi.');
        }

        // Wajib di-escape: nilainya datang dari formulir login.
        $aman = ldap_escape($username, '', LDAP_ESCAPE_FILTER);

        $filter = "(&(objectClass=user)(|(sAMAccountName={$aman})(userPrincipalName={$aman})))";

        /*
         * KEANGGOTAAN GRUP MASUK KE DALAM FILTER, bukan diperiksa setelah
         * hasilnya kembali. Bukan anggota berarti tidak ada baris yang
         * kembali sama sekali — tidak ada cabang kode yang bisa terlewat.
         */
        $grup = trim((string) config('ldap.allowed_group', ''));

        if ($grup !== '') {
            $grupDn = str_contains($grup, '=')
                ? $grup
                : 'CN='.$grup.','.$this->wadahBawaan($baseDn);

            $filter = '(&'.$filter.'(memberOf='.ldap_escape($grupDn, '', LDAP_ESCAPE_FILTER).'))';
        } else {
            $this->catat(
                'LDAP_ALLOWED_GROUP kosong: SELURUH akun domain bisa masuk. '
                .'Isi kunci itu bila hanya sebagian pegawai yang boleh memakai sistem ini.'
            );
        }

        $hasil = @ldap_search($koneksi, $baseDn, $filter, config('ldap.attributes'), 0, 2);

        if ($hasil === false) {
            throw new RuntimeException('Pencarian direktori gagal: '.ldap_error($koneksi));
        }

        $baris = ldap_get_entries($koneksi, $hasil);

        if (($baris['count'] ?? 0) === 0) {
            return null;
        }

        /*
         * Lebih dari satu kecocokan berarti direktori punya dua akun dengan
         * nama pengguna yang sama — keadaan yang seharusnya mustahil di AD.
         * Ditolak, bukan diambil yang pertama: menebak salah satunya berarti
         * meloloskan orang ke akun yang bukan miliknya.
         */
        if ($baris['count'] > 1) {
            throw new RuntimeException("Nama pengguna '{$username}' cocok dengan lebih dari satu akun di direktori.");
        }

        $u = $baris[0];

        return [
            'samaccountname' => (string) ($u['samaccountname'][0] ?? $username),
            'displayname' => (string) ($u['displayname'][0] ?? ''),
            'mail' => isset($u['mail'][0]) ? (string) $u['mail'][0] : null,
            'dn' => (string) $u['dn'],
            'useraccountcontrol' => (int) ($u['useraccountcontrol'][0] ?? 0),
        ];
    }

    /**
     * @param  array{useraccountcontrol: int}  $pengguna
     */
    private function dinonaktifkanDiAd(array $pengguna): bool
    {
        return ($pengguna['useraccountcontrol'] & self::AD_ACCOUNTDISABLE) !== 0;
    }

    /**
     * Wadah bawaan tempat grup berada bila LDAP_ALLOWED_GROUP hanya berisi nama.
     *
     * Active Directory menaruh grup bawaan di CN=Users. Yang memakai OU
     * tersendiri cukup menuliskan DN lengkap di konfigurasinya, dan nilai ini
     * tidak dipakai.
     */
    private function wadahBawaan(string $baseDn): string
    {
        return 'CN=Users,'.$baseDn;
    }

    private function catat(string $pesan): void
    {
        if (config('ldap.logging', true)) {
            Log::warning('[ldap] '.$pesan);
        }
    }
}
