# Keuangan / MasterData — Modul A

**Skema:** `keuangan_master`
**Status:** Wave 1 — **gate lulus** per 2026-09-14. Yang tersisa menunggu jawaban
RSP UI (Q4, Q6), bukan pekerjaan. Lihat *Yang masih belum* di bagian bawah.

---

## Ruang lingkup

Fondasi seluruh domain keuangan. Semua modul lain bergantung ke sini.

Yang **sudah** dibangun:

- **Charge Description Master (CDM)** — kode item global untuk seluruh yang bisa
  ditagihkan: tindakan, penunjang, obat, BHP, alkes, akomodasi, visite, parkir,
  barang koperasi.
- **Pemetaan item ke akun COA** — akun pendapatan, akun beban pokok, akun potongan.
- **Kontrak penjamin berperiode** — plafon, cost-sharing, pengecualian, tenggat.
- **Pusat biaya & pusat pendapatan** — empat jenis, dasar alokasi ABC Wave 7.
- **COA hierarkis + klasifikasi PSAK + enam dimensi** (di modul Finance, lihat KA-7).
- **Kalender periode empat status** (di modul Finance).

- **Penautan tarif lama ke CDM** — lima resolver di balik satu antarmuka
  `TariffResolver`, dirakit `TariffSourceRegistry`. Selesai 2026-09-14.

Yang **belum**:

- Mapping engine ICD-10 ↔ INA-CBG ↔ SATUSEHAT ↔ LOINC — butuh sumber kode resmi.
- Master kelas perawatan & hak kelas — menunggu Q6.
- Bagan akun RSP UI — menunggu Q4.
- ~~Layar untuk seluruh modul ini~~ — **selesai 2026-09-14**, lihat bagian *Layar*
  di bawah. Sempat saya catat sebagai "Q3b", padahal Q3b di OPEN-QUESTIONS adalah
  pertanyaan lain (konsolidasi laporan ke UI) — salah rujuk, bukan pertanyaan yang
  menunggu jawaban.

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
| `finance.v_account` | kontrak terbitan | Bagan akun: kode, nama, jenis, klasifikasi, induk, `is_postable`. **Tanpa saldo** — saldo adalah hasil hitungan atas jurnal, dan menerbitkannya mengundang tiap konteks menghitung sendiri-sendiri |
| `catalog.v_payer_summary` | kontrak terbitan | Identitas penjamin, untuk memastikan kontrak menunjuk penjamin yang ada |
| `organization.v_unit_summary` | kontrak terbitan | Unit organisasi, untuk menaut pusat biaya |

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

---

## Pembaruan 2026-09-14 — Modul A hampir lengkap

Tiga sub-modul baru ditambahkan. Ruang lingkupnya sekarang:

| Sub-modul | Tabel | Status |
|---|---|---|
| Charge Description Master | `charge_items` | ✅ 16 uji |
| Kontrak penjamin berperiode | `payer_contracts`, `contract_exclusions` | ✅ 16 uji |
| Pusat biaya & pendapatan | `cost_centers` | ✅ 13 uji |
| COA hierarkis + dimensi | `finance.chart_of_accounts`, `finance.journal_lines` | ✅ (di modul Finance — lihat KA-7) |
| Kalender periode 4 status | `finance.accounting_periods` | ✅ 14 uji (di modul Finance) |

### Kontrak penjamin

`catalog.payers` menyimpan IDENTITAS penjamin dan **tidak dipindahkan** — ia dipakai
pendaftaran dan billing setiap hari. Yang dikelola di sini adalah **kontraknya**:
masa berlaku, plafon, cost-sharing, pengecualian, tenggat pengajuan.

**Berperiode, dan itu intinya.** Tagihan yang terbit Maret dinilai dengan kontrak yang
berlaku Maret, bukan kontrak yang berlaku saat laporannya dibuka.

Empat basis cost-sharing, dan **basis menentukan kolom mana yang wajib terisi** —
ditegakkan CHECK. Kontrak berbasis persentase tanpa angka persennya akan menghitung
bagian pasien sebagai NOL: pasien tidak ditagih apa-apa, tidak ada galat, dan
selisihnya baru ketahuan saat rekonsiliasi penjamin.

Bagian pasien dihitung lewat `Money::bagi()`, bukan perkalian desimal — sehingga
**bagian pasien + bagian penjamin selalu sama persis** dengan tagihannya.

### Pusat biaya

Empat jenis, dan pembedaannya menentukan **arah alokasi**:

| Jenis | Peran | Cost driver |
|---|---|---|
| `revenue-center` | menghasilkan pendapatan; **penerima** alokasi | **dilarang** |
| `cost-center` | melayani unit lain (laundry, gizi, IPSRS) | **wajib** |
| `support-center` | manajemen & administrasi | **wajib** |
| `program-center` | pendidikan & penelitian | **wajib** |

`program-center` **sengaja dipisah** dari support-center. Menggabungkannya membuat
biaya pendidikan tersebar ke tarif pelayanan lewat alokasi biasa — dan itu berarti
pasien ikut membiayai pendidikan tanpa ada yang memutuskannya. Dana pendidikan PTN-BH
harus bisa dipertanggungjawabkan terpisah.

Klasifikasi diubah dengan **meng-expire yang lama**, bukan menimpanya: sebuah poli
bisa berpindah dari pusat biaya jadi pusat pendapatan saat layanannya mulai
ditagihkan, dan laporan tahun lalu harus tetap memakai klasifikasi yang berlaku
waktu itu.

---

## Pembaruan 2026-09-14 — penautan tarif selesai, gate Wave 1 lulus

### Yang diseragamkan bukan bentuk tarifnya, melainkan cara menanyakannya

Rencana semula: *merge* lima tabel tarif ke CDM. Membuka keenam tabelnya mengubah
keputusan itu — dan ini koreksi kedua setelah `catalog.tariffs` di Discovery §10.

**Lima dari enam tabel kosong.** Hanya `catalog.tariffs` (15 baris) dan
`inpatient.rooms.daily_rate` (6 baris) yang berisi. Jadi ini bukan migrasi data;
ini soal bentuk. Dan bentuknya memang berbeda-beda dengan alasan yang nyata:

| Sumber | Dimensi tarifnya |
|---|---|
| tindakan | penjamin + kelas + tanggal |
| obat | harga dasar **×** markup penjamin |
| koperasi | tingkat harga (umum/pegawai/mitra) — **bukan** penjamin |
| kamar | melekat pada kamarnya |
| parkir | dihitung dari **durasi**, dengan menit bebas |

Menyatukannya ke satu tabel berarti tabel itu punya belasan kolom yang kebanyakan
NULL, dan **tiap pembacanya harus tahu kolom mana yang berlaku untuk golongan
mana** — pengetahuan yang lalu tersebar lagi, persis masalah yang hendak
diselesaikan CDM.

Maka: antarmuka `TariffResolver`. Satu pertanyaan, satu jawaban `Money`, atau
`null`. Lima sumber tetap menghitung dengan caranya masing-masing;
`TariffSourceRegistry` hanya tahu siapa yang harus ditanya.

### `null` berbeda dari nol, dan itu yang paling menentukan

Nol berarti "item ini memang gratis". `null` berarti "tidak ada tarif yang berlaku
untuk kombinasi ini". Menyamakan keduanya membuat tagihan terbit senilai nol saat
tarifnya sebenarnya belum diisi — **tanpa galat di mana pun**, sampai ada yang
membandingkan pendapatan dengan jumlah kunjungan.

Empat tempat menegakkannya:

- tarif layanan **tanpa penjamin** → `null`, tidak jatuh ke tarif umum;
- markup obat **belum ditetapkan** → `null`, tidak dianggap 0%;
- tingkat harga koperasi **belum berharga** → `null`, tidak jatuh ke tingkat umum;
- tarif parkir **per jam tanpa durasi** → `null`, tidak dianggap satu jam.

Sebaliknya, **konteks tanpa resolver melempar**. Itu cacat pemasangan, bukan
keadaan bisnis — dan `null` akan membuatnya menyamar jadi "tarif belum diisi".

### Menautkan ≠ mengaktifkan

Sapuan menghasilkan item **nonaktif dan belum dipetakan akun**. Menautkan berarti
"barang ini punya kode global"; mengaktifkan berarti "boleh ditagihkan dan akan
masuk buku besar". Yang kedua menuntut pemetaan akun — keputusan akuntansi, bukan
hasil sapuan.

### Salah pilih view = angka yang terlihat wajar tapi salah

Inpatient menerbitkan tiga view berkamar. Dua di antaranya akan menghasilkan
tagihan salah **tanpa melempar galat apa pun**:

| View | Kalau dipakai menagih |
|---|---|
| `v_room_daily_charge` | biaya **oksigen/laundry** ditagih sebagai harga kamar |
| `v_room_class_rate` | **rata-rata** kelas — dua kamar VIP bertarif beda ditagih di angka tengah yang tidak pernah diputuskan siapa pun |
| `v_room_rate` *(dibuat di sini)* | nominal kamar sebenarnya ✅ |

Dikunci uji: `tarif_kamar_memakai_nominal_kamar_bukan_rata_rata_kelas`.

### `Money::persen()`

Ditambahkan untuk markup obat. Persennya **string**, bukan float. Dihitung pada
skala alokasi (4) dan **pembulatannya ditunda** — membulatkan tiap baris ke rupiah
lebih dulu membuat resep 30 tablet meleset 19 sen dari hitung ulang mana pun
(dibuktikan `pembulatan_persentase_ditunda_sampai_akhir`).

### Kontrak terbitan baru

`catalog.v_tariff`, `catalog.v_service`, `pharmacy.v_drug_price`,
`pharmacy.v_drug_markup`, `inpatient.v_room_rate`, `retail.v_product_price`,
`parking.v_rate`.

Keuangan **tidak menyentuh satu pun tabel sumber langsung** — uji batas konteks
menegakkannya.

### Yang masih belum

- Mapping engine ICD-10 ↔ INA-CBG ↔ SATUSEHAT ↔ LOINC — butuh sumber kode resmi.
- Master kelas perawatan & hak kelas — menunggu **Q6**.
- Bagan akun RSP UI — menunggu **Q4**; tercatat sebagai **penghalang** di
  `siap:periksa`.

### Layar — selesai 2026-09-14

Modul ini sempat dibangun **tanpa satu pun layar**, dan saya mencatat
ketiadaannya sebagai pertanyaan terbuka seolah itu keputusan yang menunggu RSP UI.
Bukan. BAGIAN 5 instruksi justru menuntut tiap modul punya folder `Http/`, dan tidak
pernah ada permintaan "backend saja". Tanpa layar, tidak ada seorang pun di RSP UI
yang bisa memeriksa apakah kode ini benar — dan pemeriksaan itulah satu-satunya cara
kesalahan tertangkap sebelum uang mengalir lewatnya.

| Layar | Rute | Isi |
|---|---|---|
| Master Keuangan | `/master-keuangan` | kesiapan, cakupan penautan per konteks, tombol tautkan |
| Charge Master | `/master-keuangan/item` | item berperiode, pemetaan akun, aktifkan, expire |
| Kontrak Penjamin | `/master-keuangan/kontrak` | kontrak berlaku, yang akan berakhir, pendaftaran |
| Pusat Biaya | `/master-keuangan/pusat-biaya` | empat jenis, unit belum terklasifikasi, pendaftaran |

Digerbangi `pendapatan_per_akun` — dipegang Petugas Keuangan dan Manajemen RS.
Pemetaan item ke akun COA adalah keputusan akuntansi, jadi ia memang milik
keuangan, bukan milik administrator sistem yang tidak tahu akun mana yang
menampung pendapatan tindakan.

Prefiks `master-keuangan`, bukan `keuangan`, supaya tidak menabrak
`keuangan.index` milik Pusat Keuangan yang sudah ada — dua rute bernama sama
akan membuat salah satunya tidak pernah terbuka, dan yang menang ditentukan
urutan pemuatan modul.

**Diuji lewat HTTP, bukan lewat service** — `MasterKeuanganScreenTest`, 14 uji.
Bug pemilih obat dulu lolos justru karena seluruh uji memanggil service langsung,
jalur yang tidak pernah ditempuh manusia.

### Cacat yang ditemukan saat menaut, tercatat bukan ditambal

| Temuan | Tercatat di |
|---|---|
| `rooms.daily_rate` tidak berperiode — kenaikan tarif mengubah nilai rawat inap yang sudah lewat | **Q14** |
| `TariffLookup::resolve(): ?float` — uang sebagai float | MIGRATION-MAP, Wave 2 |
| `Retail\ProductService:60` — `round((float) ...)` untuk uang | MIGRATION-MAP, Wave 5 |
| `pharmacy.drug_markups` punya model lengkap tapi **tidak dipanggil siapa pun** — seluruh penjamin ditagih harga obat yang sama | MIGRATION-MAP; resolver keuangan sudah memakai markup, jadi begitu tabelnya diisi harganya langsung benar |

### Uji

`tests/Feature/Keuangan/TariffLinkingTest.php` — 17 uji.
`tests/Unit/Keuangan/MoneyTest.php` — 23 uji (4 di antaranya untuk `persen()`).
