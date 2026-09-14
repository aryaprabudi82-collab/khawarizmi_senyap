# Pertanyaan Terbuka — Autentikasi & Kewenangan

Dokumen ini memuat keputusan yang **sudah diambil beserta akibatnya**, dan hal-hal
yang menunggu keputusan RSP UI. Ditulis terpisah dari kode supaya risikonya sampai ke
orang yang memutuskan, bukan berhenti di komentar yang hanya dibaca pemrogram.

**Dibuat:** 2026-09-14, saat autentikasi Active Directory dipasang.

---

## Q-A1 — Anggota grup AD baru langsung memperoleh 228 hak klinis

**Status: KEPUTUSAN SUDAH DIAMBIL.** Dipilih sadar setelah akibatnya disampaikan.

`LDAP_DEFAULT_ROLE=dokter`. Siapa pun yang ditambahkan ke grup Active Directory
`Khawarizmi SIMRS` memperoleh peran `dokter` secara otomatis saat login pertama,
tanpa persetujuan siapa pun di dalam SIMRS.

Peran `dokter` membuka **228 hak**, termasuk:

| Hak | Artinya |
|---|---|
| `pasien` | master data pasien |
| `penilaian_awal_medis_ralan` | membuka & menulis rekam medis |
| `resep_obat` | menulis resep |
| `periksa_lab`, `periksa_radiologi` | memesan pemeriksaan penunjang |
| `operasi` | mencatat tindakan operasi |
| `diagnosa_pasien` | menegakkan diagnosis |

**Yang membuat ini lebih berat dari kelihatannya:** deskripsi peran `dokter` di
`database/data/roles.json` berbunyi *"Data pasien dibatasi policy DPJP"* — dan
**janji itu belum ditegakkan kode mana pun**. Tidak ada satu pun direktori
`Policies/` di seluruh repositori. Seorang dokter hari ini bisa membuka rekam medis
pasien mana saja, bukan hanya pasien yang ia tangani.

**Akibat gabungannya:** siapa pun yang berwenang menambah anggota grup di Active
Directory — biasanya tim infrastruktur, bukan tim klinis — efektifnya bisa memberi
akses rekam medis seluruh pasien.

**Yang perlu diputuskan RSP UI:**

1. Apakah pemberian peran otomatis ini memang dikehendaki, atau anggota grup baru
   sebaiknya masuk **tanpa peran** lalu dinaikkan administrator sesuai pekerjaannya?
   Mengubahnya cukup dengan mengosongkan `LDAP_DEFAULT_ROLE` di `.env` — tidak perlu
   menyentuh kode.
2. Siapa yang berwenang menambah anggota grup `Khawarizmi SIMRS`, dan apakah
   penambahan itu melalui persetujuan pihak klinis?
3. Kapan policy DPJP dibangun, sehingga deskripsi peran `dokter` menjadi benar?

---

## Q-A2 — Kata sandi akun layanan AD sudah terekspos

**Status: PERLU TINDAKAN.**

Kata sandi `it@rs.ui.ac.id` dikirim sebagai teks biasa saat pemasangan ini diminta,
dan di aplikasi Khawarizmi ia **ter-commit di `.env` yang terlacak git** — artinya ia
ada di riwayat repositori itu dan bisa dibaca siapa pun yang punya akses ke sana.

Di SIMRS Mandiri, `.env` sudah tercantum di `.gitignore` (terverifikasi), jadi
pemasangan ini **tidak menambah** kebocoran. Tapi kebocoran yang sudah ada tidak
hilang dengan sendirinya.

**Yang disarankan:**

1. Ganti kata sandi akun layanan `it@rs.ui.ac.id` di Active Directory.
2. Perbarui `LDAP_PASSWORD` di `.env` SIMRS Mandiri dan di Khawarizmi.
3. Pertimbangkan akun layanan **tersendiri** untuk SIMRS Mandiri, dengan hak baca
   direktori saja — bukan akun `it` yang kemungkinan berwenang lebih. Akun layanan
   per aplikasi membuat pencabutan akses satu aplikasi tidak mengganggu yang lain.

---

## Q-A3 — `super-admin` melewati pemeriksaan `is_active`

**Status: SUDAH ADA SEBELUM PEMASANGAN INI, ikut tercatat karena kini relevan.**

`mohammad.hud` diberi peran `super-admin` sesuai permintaan. Peran itu bekerja lewat
`Gate::before` di `PermissionRegistry` baris 34–36:

```php
if ($user->isSuperAdmin()) {
    return true;
}
```

Dua akibatnya:

1. **Kewenangannya tidak terlihat di tabel mana pun.** `role_permission` untuk
   `super-admin` berisi **nol baris** — layar Peran akan menampilkannya seolah tidak
   punya hak apa-apa, padahal ia punya semuanya.
2. **Bypass itu terjadi sebelum pemeriksaan `is_active`.** Baris berikutnya
   (`$user->is_active && $user->hasPermission(...)`) hanya tercapai untuk non-super-admin.
   Menonaktifkan akun super-admin **menutup pintu login**, tetapi **tidak menghentikan
   sesi yang sedang berjalan** — pemegang sesi itu tetap lolos setiap pemeriksaan hak
   sampai sesinya berakhir.

**Yang perlu diputuskan:** apakah `super-admin` sebaiknya tetap tunduk pada
`is_active`. Perbaikannya satu baris, tetapi mengubah perilaku peran paling
berwenang — jadi bukan sesuatu yang saya ubah tanpa diminta.

---

## Catatan pemasangan — apa yang DITEGAKKAN di sini

Tiga hal yang **tidak** diperiksa aplikasi Khawarizmi, dan ditegakkan di SIMRS Mandiri:

| Pemeriksaan | Khawarizmi | SIMRS Mandiri |
|---|---|---|
| Keanggotaan grup | ❌ tidak ada — seluruh akun domain bisa masuk | ✅ di dalam filter pencarian |
| Escaping filter LDAP | ❌ input disisipkan mentah | ✅ `ldap_escape()` |
| Akun di-*disable* di AD | ❌ tidak diperiksa | ✅ bit `userAccountControl & 2` |

Terbukti lewat pengujian langsung ke direktori: `mohammad.hud` dan `arya.prabudi`
(anggota grup) ditemukan; `it`, `administrator`, dan `guest` (bukan anggota) ditolak.
