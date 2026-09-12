# Tahap 0 — Laporan Discovery Domain Keuangan

**Tanggal:** 2026-09-12
**Cakupan:** seluruh repo `simrs-mandiri` (25 bounded context, 1.209 berkas PHP, 499 tabel, 67 view)
**Baseline uji sebelum pekerjaan dimulai:** 2.142 lulus, 0 gagal
**Status:** SELESAI — menunggu review. Belum ada satu baris kode implementasi yang ditulis.

---

## 1. Ringkasan Eksekutif

Sistem ini **sudah memiliki sebagian besar fondasi keuangan**, tetapi tersebar di
sembilan bounded context dan **belum tersambung ke buku besar**. Temuan terpenting:

| # | Temuan | Tingkat |
|---|---|---|
| 1 | **Posting Engine baru menjurnal 1 dari ±15 jenis transaksi.** Dari 10 jurnal yang ada, 9 manual dan 1 dari invoice. Penjualan farmasi, HPP, persediaan, kasir, hutang, deposit, dan aset **tidak pernah masuk GL sama sekali**. | **KRITIS** |
| 2 | **Tidak ada constraint balance di tingkat basis data.** `finance.journal_entries` hanya punya UNIQUE dan NOT NULL. Balance dijaga aplikasi saja — satu jalur tulis yang lupa memeriksa akan merusak seluruh neraca tanpa penahan. | **KRITIS** |
| 3 | **Tidak ada idempotency sama sekali.** Nol kolom, nol tabel. Retry jaringan pada charge/payment akan menghasilkan baris ganda. | **KRITIS** |
| 4 | **Tarif tersebar di 6 tabel** pada 5 konteks berbeda, dan **tidak bitemporal**. `catalog.tariffs` tidak punya `valid_from`/`valid_to`. | **TINGGI** |
| 5 | **Valuasi persediaan/HPP tersebar di 4 konteks** (pharmacy, retail, inventory, kitchen) dengan nama kolom berbeda (`cost_price` vs `unit_cost`) dan tidak satu pun menjurnal. | **TINGGI** |
| 6 | **Posting GL bersifat sinkron** — `PostingService::syncFromBilling()` dipanggil langsung, tidak ada queue/outbox. | **TINGGI** |
| 7 | Jenis akun COA **bercampur dua tingkatan** (`kas`/`piutang` disejajarkan dengan `aset`). Sudah ditambal lapisan golongan, tetapi akar masalahnya belum dibereskan. | SEDANG |
| 8 | Belum ada: budgeting, cost accounting, treasury, aset tetap (sisi finansial), payroll, klaim scrubbing. | SEDANG |

**Kabar baiknya** — tiga hal yang biasanya paling mahal diperbaiki, sudah benar:

- **Tidak ada satu pun FLOAT/DOUBLE untuk nilai uang.** Seluruh 191 kolom uang bertipe `numeric`.
- **Sudah ada partisi** pada `charge_lines` (125 partisi), `audit_logs`, `stock_movements`, `observations`.
- **Audit trail sudah ada dan terpartisi per bulan** (`platform.audit_logs`).

---

## 2. Inventaris Repo

### 2.1 Bounded context yang ada

```
Asset  Billing  Blood  Catalog  Clinical  Correspondence  Encounter  Envlab
Finance  Hr  Identity  Inpatient  Integration  Inventory  Kitchen  Library
Order  Organization  Parking  Pharmacy  Philanthropy  Platform  Quality
Reporting  Retail
```

### 2.2 Tabel per skema (499 total)

| Skema | Tabel | | Skema | Tabel |
|---|---:|---|---|---:|
| clinical | 79 | | correspondence | 18 |
| pharmacy | 70 | | quality | 17 |
| billing | 36 | | library | 14 |
| platform | 35 | | catalog | 13 |
| integration | 33 | | hr | 13 |
| retail | 25 | | encounter | 9 |
| asset | 24 | | inpatient | 8 |
| inventory | 20 | | envlab / blood | 7 |
| kitchen | 19 | | philanthropy / organization | 6 |
| **finance** | **19** | | orders | 5 |

Perhatikan: **`finance` hanya 19 tabel dari 499.** Itu ukuran masalahnya — fungsi
keuangan yang sebenarnya jauh lebih besar daripada yang berada di dalam domainnya.

---

## 3. Anti-Pattern Kritis

### 3.1 Nilai uang — LULUS

```
FLOAT/DOUBLE/REAL pada kolom uang : TIDAK ADA
numeric                           : 191 kolom
```

Presisi yang dipakai:

| Presisi | Jumlah kolom | Catatan |
|---|---:|---|
| DECIMAL(14,2) | 120 | mayoritas |
| DECIMAL(12,2) | 33 | |
| DECIMAL(15,2) | 14 | |
| DECIMAL(16,2) | 14 | |
| DECIMAL(18,2) | 1 | |
| numeric tanpa presisi | 4 | **perlu diperiksa** |

**Catatan terhadap syarat DECIMAL(19,4):** skema saat ini memakai skala **2**, bukan 4.
Untuk Rupiah, skala 2 memadai pada nilai transaksi; skala 4 dibutuhkan saat ada
perhitungan proporsional (alokasi ABC, pembagian jaspel, selisih kelas per hari)
yang hasil antaranya bisa berupa pecahan sen. Ini **keputusan yang perlu diambil
manusia** — lihat `OPEN-QUESTIONS.md` butir Q1.

### 3.2 Idempotency — TIDAK ADA

```
kolom idempotency : 0
tabel idempotency : 0
```

Tidak ada perlindungan apa pun terhadap double-submit. Pada beban 2.000 pasien/hari
dengan jaringan rumah sakit yang tidak selalu stabil, ini menghasilkan tagihan ganda.

### 3.3 Hard delete pada tabel finansial

| Tabel | Penanda pembatalan |
|---|---|
| `billing.payments` | `voided_at` — baik |
| `finance.cash_transactions` | `cancelled_at` — baik |
| `billing.charge_lines` | **tidak ada** |
| `finance.journal_entries` | **tidak ada** |
| `finance.journal_lines` | **tidak ada** |

Jurnal memang seharusnya append-only (tidak butuh penanda batal — koreksi lewat
reversal). Yang perlu diperiksa adalah apakah ada jalur kode yang benar-benar
menghapusnya. **Belum diverifikasi pada tahap ini.**

### 3.4 Balance jurnal — hanya dijaga aplikasi

Constraint pada `finance.journal_entries` yang benar-benar ada:

```
UNIQUE (entry_number)
NOT NULL description, entry_date, entry_number, id
PRIMARY KEY (id)
```

Tidak ada CHECK maupun trigger yang menjamin `sum(debit) = sum(credit)`.
Saat ini **0 jurnal tidak balance** — tetapi itu karena hanya ada 10 jurnal dan
semuanya lewat `LedgerService::postManual()` yang memeriksanya di PHP.

---

## 4. Sebaran Fungsi Keuangan di Luar Domain

Inilah inti Aturan Konsolidasi. Semua yang di bawah lolos tes:
*"apakah ini menghasilkan jurnal, mempengaruhi tagihan, atau mempengaruhi laporan keuangan?"*

### 4.1 Tarif — 6 tabel, 5 konteks

| Tabel | Konteks | Isi |
|---|---|---|
| `catalog.tariffs` | catalog | tarif layanan klinis + 6 komponen jasa medis |
| `pharmacy.drug_markups` | pharmacy | markup obat ralan/ranap |
| `retail.price_tiers` | retail | tingkat harga koperasi |
| `retail.product_prices` | retail | harga per produk per tingkat |
| `parking.rates` | parking | tarif parkir |
| `inpatient.rooms.daily_rate` | inpatient | tarif kamar per hari |
| `encounter.corporate_mcu_bookings` | encounter | tarif paket MCU korporat |

**Tidak satu pun bitemporal.** `catalog.tariffs` tidak punya `valid_from`/`valid_to`,
sehingga tarif historis tidak bisa direkonstruksi — yang menyelamatkan saat ini
adalah pembekuan nilai pada baris transaksi (`charge_lines`, `clinical.procedures`).

### 4.2 Jasa medis — 3 lokasi

| Lokasi | Peran |
|---|---|
| `catalog.tariffs` (6 kolom `share_*`) | definisi komponen |
| `clinical.procedures` (6 kolom `share_*`) | **snapshot beku saat tindakan** |
| `clinical.v_procedure_charge` | kontrak ke billing |

Perhitungan rekapnya ada di `Reporting\MedicalFeeReportService`. **Pembayarannya
kepada dokter belum ada sama sekali** (sudah tercatat sebagai `bayar_jm_dokter`
BELUM DIBANGUN pada verifikasi domain K).

### 4.3 Valuasi persediaan / HPP — 4 konteks, penamaan tidak seragam

| Konteks | Kolom | Menjurnal? |
|---|---|---|
| pharmacy | `cost_price` pada `stock_batches`, `goods_receipt_items`, `retail_sale_items` | **TIDAK** |
| retail | `unit_cost` pada 6 tabel | **TIDAK** |
| inventory | `stock_movements` | **TIDAK** |
| kitchen | `stock_movements` | **TIDAK** |

Empat implementasi buku besar stok yang bentuknya nyaris identik. Migrasi
`retail` sendiri sudah menyatakan: *"kalau muncul instansi keempat, menyarikan
mekanisme buku besar stok jadi satu komponen bersama menjadi pilihan yang lebih
murah daripada menyalinnya sekali lagi."* **Instansi keempat sudah ada.**

### 4.4 Rantai pengadaan — 5 konteks

`pharmacy`, `inventory`, `asset`, `kitchen`, `retail` masing-masing punya
`purchase_orders`, `goods_receipts`, `supplier_returns`, dan master `suppliers`
sendiri. Hutangnya **sudah** terkonsolidasi di `finance.payables` dengan kolom
`source_context` — ini pola yang benar dan layak jadi contoh untuk yang lain.

### 4.5 Klaim BPJS

Berada di `integration.claims` + `claim_monitorings`, dengan `ClaimService` dan
`SmartClaimService`. Pada verifikasi domain L ditemukan **keduanya tidak punya
layar sama sekali** — mesinnya lengkap, tidak ada yang bisa menjalankannya.

---

## 5. Status Posting Engine

`Finance\PostingService` hanya punya dua method publik:

- `syncFromBilling()` — menjurnal invoice
- `collectReceivable()` — menjurnal penagihan piutang

Sumber jurnal yang benar-benar tercatat di basis data:

```
(manual)  9
invoice   1
```

**Yang belum pernah menjurnal:** penjualan bebas farmasi, penyerahan obat (HPP),
penerimaan barang (persediaan), pembayaran hutang, penerimaan/pengembalian deposit,
kas harian, penutupan shift kasir, retur, opname, hibah, aset, dan payroll.

Posting juga **sinkron** — dipanggil langsung, bukan lewat queue. Pada beban puncak
08.00–12.00 ini akan memperlambat charge capture.

---

## 6. Kondisi Data Saat Ini (basis data pengembangan)

| Entitas | Baris | Catatan |
|---|---:|---|
| `catalog.services` | 11 | contoh pengembangan |
| `catalog.tariffs` | 15 | contoh pengembangan |
| `pharmacy.drugs` | 12 | contoh pengembangan |
| `retail.products` | 0 | kosong |
| `finance.chart_of_accounts` | 12 | 4 seeder + 8 diisi saat uji laporan |
| `billing.invoices` | 1 | |
| `billing.charge_lines` | 3 | dari 125 partisi yang sudah siap |
| `billing.payments` | 1 | |

Volume data nyaris nol. **Load test 100 charge/detik belum pernah dilakukan** dan
tidak bisa disimpulkan dari data ini.

---

## 7. Yang Sudah Benar dan Harus Dipertahankan

Beberapa keputusan yang sudah tertanam dan **tidak boleh hilang saat konsolidasi**:

1. **Nilai dibekukan pada transaksi**, bukan dibaca ulang — `charge_lines` menyimpan
   snapshot tarif, `clinical.procedures` membekukan 6 komponen jasa, `retail.sale_items`
   membekukan HPP. Inilah yang membuat laporan periode lalu tidak berubah sendiri.
2. **Nilai turunan dihitung, tidak disimpan** — sisa piutang, sisa hutang, selisih
   opname, denda perpustakaan. Konsisten di seluruh sistem.
3. **`finance.payables` sudah satu buku untuk empat rantai pengadaan** — pola
   konsolidasi yang benar, layak ditiru.
4. **Batas konteks ditegakkan uji** (`ContextBoundaryTest`) — modul hanya boleh
   membaca view yang diterbitkan konteks lain, bukan tabelnya.
5. **Pembayaran dibatalkan selalu dikecualikan** dari seluruh rekap.
6. **Audit trail terpartisi** dan sudah berjalan.

---

## 8. Risiko Utama Konsolidasi

| Risiko | Dampak | Mitigasi yang diusulkan |
|---|---|---|
| Memindahkan tarif ke CDM tunggal memutus `catalog.tariffs` yang dipakai billing, clinical, reporting | Tagihan berhenti terbentuk | Pindah bertahap: CDM baru dulu, `catalog.tariffs` jadi view, baru pemanggil dialihkan |
| Menyatukan 4 buku besar stok berisiko mencampur stok obat dengan barang koperasi | Obat terjual di kasir toko | Satu mekanisme, **skema tetap terpisah** per konteks; yang disatukan kodenya, bukan tabelnya |
| Menambahkan constraint balance pada jurnal yang sudah ada | Migrasi gagal bila ada data lama tidak balance | Sudah diverifikasi: 0 jurnal tidak balance saat ini. Aman ditambahkan sekarang |
| Posting GL diubah jadi asinkron | Laporan tertinggal dari transaksi | Outbox + rekonsiliasi harian yang memicu alert bila selisih |
| `ContextBoundaryTest` akan menolak domain keuangan membaca tabel konteks lain | Wave 1 terhenti | Perlu keputusan: apakah `keuangan` jadi konteks ke-26 dengan kontrak, atau aturan batas direvisi |

---

## 9. Kesimpulan Tahap 0

Pekerjaan ini **bukan membangun dari nol, juga bukan sekadar memindahkan**.
Proporsinya kira-kira:

- **±40% sudah ada dan tinggal dipindahkan/direfaktor** (billing, kasir, hutang, piutang, buku besar, deposit, klaim)
- **±25% ada sebagian dan perlu dilengkapi** (master data/CDM, posting engine, persediaan, aset)
- **±35% belum ada sama sekali** (budgeting, cost accounting, treasury, payroll, jaspel payment, claim scrubbing, SoD)

Rincian per modul ada di `01-GAP-ANALYSIS.md`.
Rencana eksekusi per wave ada di `02-IMPLEMENTATION-PLAN.md`.
Pemetaan lokasi lama → baru ada di `MIGRATION-MAP.md`.
Pertanyaan yang **harus dijawab manusia sebelum Wave 1** ada di `OPEN-QUESTIONS.md`.
