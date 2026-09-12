# Keuangan / MasterData — Modul A

**Skema:** `keuangan_master`
**Status:** Wave 1, sebagian (CDM + pemetaan akun selesai; COA multi-dimensi, master
penjamin & kontrak, kalender periode belum)

---

## Ruang lingkup

Fondasi seluruh domain keuangan. Semua modul lain bergantung ke sini.

Yang **sudah** dibangun:

- **Charge Description Master (CDM)** — kode item global untuk seluruh yang bisa
  ditagihkan: tindakan, penunjang, obat, BHP, alkes, akomodasi, visite, parkir,
  barang koperasi.
- **Pemetaan item ke akun COA** — akun pendapatan, akun beban pokok, akun potongan.

Yang **belum** (lanjutan Wave 1):

- COA multi-dimensi (hierarki + 6 dimensi)
- Master penjamin & kontrak berperiode
- Kalender periode akuntansi open/soft-close/closed/locked
- Klasifikasi revenue center vs cost center
- Mapping engine ICD-10 ↔ INA-CBG ↔ SATUSEHAT ↔ LOINC

---

## Keputusan yang menentukan bentuk modul ini

### CDM adalah KATALOG PENAUT, bukan pengganti `catalog.tariffs`

Discovery awal menyimpulkan tarif layanan harus di-*merge* ke CDM baru karena
"tidak bitemporal". **Kesimpulan itu salah** — `catalog.tariffs` sudah punya
`valid_from`/`valid_until`, sudah berdimensi penjamin dan kelas, resolusinya per
tanggal transaksi sudah berjalan lewat `TariffLookup`, dan komponen jasanya dijaga
CHECK di basis data.

Menggantinya berarti membuang mekanisme yang sudah bekerja, sudah diuji, dan dipakai
billing setiap hari — persis yang dilarang Aturan Konsolidasi. Koreksinya tercatat di
`docs/keuangan/00-DISCOVERY-REPORT.md` §10.

Maka CDM di sini:

- memberi **kode global** untuk tiap item yang bisa ditagihkan,
- **menunjuk** ke sumber tarifnya lewat `source_context` + `source_id`,
- memegang **pemetaan ke akun COA** — bagian yang benar-benar belum ada di mana pun,
  dan justru ketiadaannya yang membuat pendapatan tidak bisa dijurnalkan otomatis.

### Item tanpa pemetaan akun TIDAK BISA diaktifkan

Aturan kritis Modul A, ditegakkan **dua lapis**:

| Lapis | Peran |
|---|---|
| CHECK di basis data | **menjamin** — satu seeder atau perbaikan lewat tinker tidak bisa menembusnya |
| `ChargeMasterService::aktifkan()` | **menjelaskan** — galat SQL mentah tidak menolong siapa pun |

Item aktif tanpa akun berarti ada uang masuk yang tidak pernah sampai ke buku besar,
dan selisihnya baru ketahuan berbulan-bulan kemudian saat ada yang menutup buku.

Barang berpersediaan (obat/BHP/alkes) menuntut **akun beban pokok** juga — tanpa itu
HPP-nya tidak bisa dijurnalkan dan nilai persediaan di GL akan terus melenceng dari
gudang.

### Kode tidak pernah dihapus, hanya di-expire

Kode yang dipakai ulang membuat tagihan tahun lalu menunjuk barang yang sama sekali
lain, dan tidak ada cara mengetahuinya selain membandingkan tanggal.

### Satu sumber, satu item berjalan

Dijamin indeks unik parsial (`WHERE valid_until IS NULL`). Parsial karena di
PostgreSQL `NULL` tidak sama dengan `NULL` — indeks unik biasa akan membiarkan dua
item berjalan bersamaan untuk sumber yang sama tanpa ada yang menahannya.

---

## Entitas

| Entitas | Tabel | Peran |
|---|---|---|
| `ChargeItem` | `keuangan_master.charge_items` | Item yang bisa ditagihkan + pemetaan akunnya |

### Kolom yang menentukan

| Kolom | Peran |
|---|---|
| `code` | kode global, unik seluruh rumah sakit |
| `golongan` | tindakan/penunjang/obat/bhp/alkes/akomodasi/visite/administrasi/paket/lain |
| `source_context` + `source_id` | ke mana tarifnya dicari |
| `revenue_account_id` | akun pendapatan — **wajib untuk item aktif** |
| `cogs_account_id` | akun beban pokok — **wajib untuk obat/BHP/alkes aktif** |
| `default_program` | pelayanan/pendidikan/penelitian — **dimensi PTN-BH** |
| `valid_from` / `valid_until` | masa berlaku; `NULL` berarti masih berjalan |

---

## Aturan bisnis

1. Item lahir **nonaktif**. Mendaftar dan mengizinkan menagih adalah dua keputusan
   berbeda, sering oleh orang yang berbeda.
2. Item aktif **wajib** punya akun pendapatan.
3. Obat/BHP/alkes aktif **wajib** punya akun beban pokok.
4. Kode di-*expire*, tidak dihapus.
5. Satu baris sumber hanya boleh punya satu item CDM yang berlaku pada satu waktu.
6. `default_program` hanya boleh pelayanan/pendidikan/penelitian.
7. `valid_until` tidak boleh mendahului `valid_from`.

Butir 2, 3, 5, 6, 7 ditegakkan **basis data**, bukan hanya aplikasi.

---

## Event yang dipublish

Belum ada. Akan ditambahkan saat Posting Engine membutuhkannya (Wave 4):
`ChargeItemActivated`, `ChargeItemExpired`, `AccountMappingChanged`.

## Event yang dikonsumsi

Belum ada.

---

## Dependency

| Ke | Lewat | Catatan |
|---|---|---|
| `finance.chart_of_accounts` | kueri langsung | **Sementara.** Akan jadi kontrak view saat COA dipindahkan ke `keuangan_master` pada lanjutan Wave 1 |

Tidak mengimpor satu pun model konteks lain — uji batas konteks melarangnya, dan
larangan itu benar: impor model berarti ikut mewarisi seluruh relasi dan perilakunya.

---

## Uji

`tests/Feature/Keuangan/ChargeMasterTest.php` — 16 uji.

Yang paling penting dikunci:

- item tanpa akun tidak bisa diaktifkan (**dua lapis**, termasuk penulisan langsung
  lewat query builder yang melewati service),
- obat tanpa akun HPP tidak bisa diaktifkan,
- satu sumber satu item berjalan, dan boleh ditaut ulang setelah yang lama expire,
- katalog kosong dilaporkan sebagai cakupan **tidak ada**, bukan 100%.
