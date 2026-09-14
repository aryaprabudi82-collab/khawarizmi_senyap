# Migration Map — Konsolidasi ke Domain `keuangan`

**Tanggal dibuat:** 2026-09-12
**Diperbarui:** 2026-09-14 — penautan tarif Wave 1 selesai.

**Status keseluruhan:** **Wave 1 tuntas; Wave 2 dan seterusnya belum dimulai.**

Enam entri Wave 1 berstatus **Ditaut** (jenisnya berubah dari merge/move jadi
**extend** — alasannya di koreksi 2026-09-14 di bawah), satu **dibatalkan**, dan
enam sisanya masih **Rencana**. Belum ada satu pun pemindahan modul dieksekusi:
`Billing\*`, `Finance\*`, dan sisanya masih di tempatnya masing-masing.

Dokumen ini adalah **kontrak wajib** Aturan Konsolidasi (BAGIAN 2). Setiap
pemindahan dicatat di sini, dan Definition of Done global menuntut seluruh entri
berstatus **Selesai**.

Kolom **Jenis**:

- **move** — dipindahkan apa adanya (refactor + relokasi, bukan tulis ulang)
- **merge** — beberapa lokasi disatukan jadi satu
- **deprecate** — lokasi lama jadi thin adapter `@deprecated` yang meneruskan ke domain keuangan
- **keep** — tetap di tempatnya; bukan urusan keuangan (dicatat supaya keputusannya terlihat)

---

## Wave 1 — Master Data & Fondasi

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Catalog\*` (tarif layanan) | `keuangan_master` — **ditaut CDM, TIDAK dipindah** | **extend** | `catalog.tariffs`, `catalog.services` | **Ditaut** | RENDAH — jenisnya berubah dari merge setelah koreksi §10 Discovery: tarif layanan SUDAH bitemporal, berdimensi penjamin & kelas, dan diresolusi per tanggal. Menggantinya berarti membuang mekanisme yang sudah bekerja |
| `pharmacy.drug_markups` | **ditaut CDM lewat `v_drug_price` + `v_drug_markup`, TIDAK dipindah** | **extend** | `pharmacy.drug_markups`, `pharmacy.drugs` | **Ditaut** | RENDAH — jenisnya berubah dari merge: markup berdimensi penjamin & kelas, berperiode, dan aturan resolusinya (`DrugMarkup::berlaku()`) sudah benar. Yang salah bukan tabelnya melainkan tidak ada yang memanggilnya — memindahkannya tidak memperbaiki itu |
| `retail.price_tiers`, `retail.product_prices` | **ditaut CDM lewat `v_product_price`** | **extend** | 2 tabel | **Ditaut** | RENDAH — retail belum berisi data. Harga koperasi berdimensi TINGKAT HARGA, bukan penjamin; menyeretnya ke CDM berdimensi penjamin akan memaksa tiap penjualan mengarang penjamin |
| `parking.rates` | **ditaut CDM lewat `v_rate`** | **extend** | `parking.rates` | **Ditaut** | RENDAH — jenisnya berubah dari merge: tarif parkir punya BASIS (jam/harian) dan menit bebas. Memaksakannya ke kolom `amount` tunggal berarti seseorang harus mengingat bahwa untuk parkir angka itu berarti "per jam", dan yang lupa menagih parkir seharian seharga sejam |
| `inpatient.rooms.daily_rate` | **ditaut CDM lewat `v_room_rate` (kontrak baru)** | **extend** | `inpatient.rooms` | **Ditaut** | RENDAH — jenisnya berubah dari move: `daily_rate` melekat pada kamar, dan kamar dipakai papan tempat tidur, lama rawat, dan pemindahan kamar — semuanya di luar keuangan. **Cacat yang ditemukan saat menaut: belum berperiode → Q14** |
| ~~`encounter.corporate_mcu_bookings` (tarif paket)~~ | — | **keep** | — | **Dibatalkan 2026-09-14** | Bukan master tarif melainkan tabel PEMESANAN MCU perusahaan (nomor booking, PIC, jumlah karyawan, jadwal); `package_description` teks bebas. Tarif paket MCU belum ada di mana pun — itu pekerjaan tersendiri, bukan penautan. Lihat koreksi di bawah |
| `finance.chart_of_accounts` | `keuangan/master-data` — COA multi-dimensi | move | 1 tabel | Rencana | SEDANG — perlu tambah dimensi & hierarki |
| `finance.period_closings` | `keuangan/master-data` — kalender periode | move | 1 tabel | Rencana | RENDAH |
| `catalog.payers` | `keuangan/master-data` — master penjamin & kontrak | move | 1 tabel | Rencana | SEDANG |
| `integration.payer_code_mappings` | `keuangan/master-data` — mapping engine | move | 1 tabel | Rencana | SEDANG |
| `integration.satusehat_code_mappings` | `keuangan/master-data` — mapping engine | move | 1 tabel | Rencana | SEDANG |
| `organization.units` | — | **keep** | — | Keputusan | Struktur organisasi bukan milik keuangan; keuangan menambah **klasifikasi** revenue/cost center yang merujuk `unit_id` |

---

### Koreksi 2026-09-14 — lima `merge`/`move` berubah jadi `extend`

Penautan tarif dikerjakan, dan mengerjakannya mengubah lima keputusan sekaligus.
Perubahan sebesar ini tidak boleh lewat tanpa alasan tercatat, jadi ini alasannya.

**Yang saya temukan saat membuka keenam tabelnya:**

| Tabel | Isi | Bentuk |
|---|---|---|
| `catalog.tariffs` | 15 baris | bitemporal, berdimensi penjamin & kelas |
| `inpatient.rooms.daily_rate` | 6 baris | **tidak** berperiode |
| `pharmacy.drug_markups` | **0 baris** | berperiode, berdimensi penjamin & kelas |
| `retail.price_tiers` / `product_prices` | **0 baris** | berdimensi tingkat harga |
| `parking.rates` | **0 baris** | berbasis jam/harian + menit bebas |
| `encounter.corporate_mcu_bookings` | **0 baris** | bukan master tarif — itu tabel pemesanan |

**Lima dari enam kosong.** Jadi ini bukan migrasi data; ini soal bentuk. Dan
bentuknya berbeda-beda dengan alasan yang nyata:

- Tarif tindakan berdimensi **penjamin + kelas + tanggal**.
- Harga obat adalah **harga dasar × markup penjamin**, bukan harga tersimpan.
- Harga koperasi berdimensi **tingkat harga** (umum/pegawai/mitra), bukan penjamin
  — penjualan koperasi memang tidak mengenal penjamin.
- Tarif kamar melekat pada **kamarnya**.
- Tarif parkir dihitung dari **durasi**, dengan menit bebas.

Menyatukan kelimanya ke satu tabel berarti tabel itu punya belasan kolom yang
kebanyakan NULL, dan **tiap pembacanya harus tahu kolom mana yang berlaku untuk
golongan mana** — pengetahuan yang lalu tersebar lagi ke seluruh sistem, persis
masalah yang hendak diselesaikan CDM.

**Maka yang diseragamkan bukan bentuk tarifnya, melainkan cara menanyakannya:**
antarmuka `TariffResolver` — satu pertanyaan, satu jawaban `Money`, atau `null`
bila tidak ada tarif yang berlaku. Kelima sumber tetap menghitung dengan caranya
masing-masing; `TariffSourceRegistry` hanya tahu siapa yang harus ditanya.

Ini konsisten dengan Aturan Konsolidasi, bukan pengecualian darinya. Aturannya
melarang **dua sumber kebenaran** dan melarang **menulis ulang yang bisa
dipindahkan**. Menyalin tarif ke CDM justru akan menciptakan sumber kebenaran
kedua yang menyimpang diam-diam begitu tarif aslinya diperbarui.

**`encounter.corporate_mcu_bookings` dikeluarkan dari daftar** — ia tabel
pemesanan MCU perusahaan (nomor booking, PIC, jumlah karyawan, jadwal), bukan
master tarif. `package_description` adalah teks bebas. Tarif paket MCU belum ada
di mana pun; itu pekerjaan tersendiri, bukan penautan.

**Yang dihasilkan penautan:**

| Kontrak terbitan baru | Konteks |
|---|---|
| `catalog.v_tariff`, `catalog.v_service` | catalog |
| `pharmacy.v_drug_price`, `pharmacy.v_drug_markup` | pharmacy |
| `inpatient.v_room_rate` | inpatient |
| `retail.v_product_price` | retail |
| `parking.v_rate` | parking |

**Utang teknis yang ikut tercatat, sengaja tidak diperbaiki sekarang:**

| Temuan | Mengapa ditunda |
|---|---|
| `Catalog\TariffLookup::resolve(): ?float` — uang sebagai float | Dipakai billing setiap hari; `Billing\*` pindah di Wave 2, perbaikannya di sana |
| `Retail\ProductService:60` — `round((float) ...)` untuk uang | `Retail\*` pindah ke `inventory-costing` di Wave 5 |
| `pharmacy.drug_markups` punya model lengkap tapi **tidak dipanggil siapa pun** | Harga obat hari ini `sell_price` polos, jadi seluruh penjamin ditagih sama. Resolver keuangan sudah memakai markup, jadi saat tabelnya diisi harganya langsung benar |
| `rooms.daily_rate` tidak berperiode | Menyentuh billing → Q14, Wave 2 |

Tidak satu pun menular ke keuangan: seluruh resolver membaca nominal sebagai
**string** lalu masuk ke `Money`, dan tidak ada kolom uang bertipe float di basis
data — seluruhnya `numeric`.

---

## Wave 2 — Revenue Front, Billing, Kasir

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Billing\*` (seluruh modul) | `keuangan/billing` | move | `billing.invoices`, `charge_lines`, `payments` | Rencana | **TINGGI** — jantung pendapatan |
| `Billing\CashierClosingService` + `cashier_shifts` | `keuangan/kasir` | move | 3 tabel | Rencana | SEDANG |
| `billing.payment_channels`, `channel_payments` | `keuangan/kasir` + `keuangan/treasury` | move | 2 tabel | Rencana | SEDANG |
| `Finance\DepositService` | `keuangan/revenue-front` | move | `finance.deposits` | Rencana | RENDAH |
| `Finance\CostEstimateService` | `keuangan/revenue-front` | move | `finance.cost_estimates` | Rencana | RENDAH |
| `Integration\Bpjs\EligibilityService`, `SepService` | `keuangan/revenue-front` | move | `integration.bpjs_*` | Rencana | SEDANG — bersinggungan dengan konteks integration |

---

## Wave 3 — Klaim & AR

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Integration\Bpjs\ClaimService`, `SmartClaimService` | `keuangan/klaim` | move | `integration.claims`, `claim_monitorings` | Rencana | SEDANG — **belum punya layar**, jadi tidak ada pengguna yang terganggu |
| `billing.patient_receivables` + `ReceivableCollectionService` | `keuangan/klaim` (AR) | move | 1 tabel | Rencana | SEDANG |
| `Finance\OtherReceivableService` | `keuangan/klaim` (AR non-pasien) | move | `finance.other_receivables` | Rencana | RENDAH |

---

## Wave 4 — GL & Posting Engine

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Finance\LedgerService` | `keuangan/general-ledger` | move | `finance.journal_entries`, `journal_lines`, `account_opening_balances` | Rencana | SEDANG |
| `Finance\PostingService` | `keuangan/posting-engine` | move + perluas | — | Rencana | **TINGGI** — cakupan naik dari 1 jadi ±15 jenis transaksi, dan berubah jadi asinkron |
| `Finance\AccountingReportService` | `keuangan/general-ledger` | move | `finance.period_closings` | Rencana | RENDAH |
| `Finance\FinancialStatementService` | `keuangan/general-ledger` | move | — | Rencana | RENDAH — baru dibangun 2026-09-12 |
| `Finance\CashService` | `keuangan/general-ledger` (kas harian) | move | `finance.cash_transactions`, `cash_categories` | Rencana | RENDAH |

---

## Wave 5 — Persediaan, AP, Aset

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Pharmacy\StockLedger` (valuasi & HPP) | `keuangan/inventory-costing` | move | `pharmacy.stock_batches`, `stock_movements` | Rencana | **TINGGI** — dipakai penyerahan obat setiap hari |
| `Retail\RetailStockLedger` (HPP) | `keuangan/inventory-costing` | merge | `retail.*` | Rencana | RENDAH — belum berisi data |
| `Inventory\StockLedger` | `keuangan/inventory-costing` | merge | `inventory.stock_movements` | Rencana | SEDANG |
| `Kitchen\StockLedger` | `keuangan/inventory-costing` | merge | `kitchen.stock_movements` | Rencana | SEDANG |
| `Pharmacy/Inventory/Asset/Kitchen/Retail` — `suppliers` (5 master) | `keuangan/procure-to-pay` — vendor master tunggal | merge | 5 tabel | Rencana | **TINGGI** — 5 FK berbeda menunjuk ke sana |
| `Finance\PayableService` | `keuangan/procure-to-pay` | move | `finance.payables`, `payable_payments` | Rencana | RENDAH — **sudah konsolidasi**, tinggal relokasi |
| `asset.assets` (sisi finansial) | `keuangan/aset-tetap` | move | `asset.assets`, `asset.categories` | Rencana | SEDANG — IPSRS tetap pegang data teknis, merujuk `asset_id` sama |

**Catatan penting untuk buku besar stok:** yang disatukan adalah **kodenya**, bukan
tabelnya. Skema `pharmacy`, `inventory`, `kitchen`, `retail` tetap terpisah — kalau
disatukan, permintaan bangsal bisa menarik stok dari barang dagangan koperasi dan
obat bisa terjual di kasir toko. Batas konteks proyek ini justru ada untuk mencegah itu.

---

## Wave 6 — Jaspel & Remunerasi

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Reporting\MedicalFeeReportService` | `keuangan/jaspel-remunerasi` | move | — (baca `clinical.v_procedure_charge`) | Rencana | RENDAH |
| `clinical.procedures` kolom `share_*` | — | **keep** | — | Keputusan | Snapshot beku **harus tetap** di transaksi klinisnya. Keuangan membacanya lewat kontrak view, tidak memindahkannya |
| `hr.performance_appraisals` | — | **keep** | — | Keputusan | SKP adalah penilaian kinerja kepegawaian, bukan remunerasi finansial. Remunerasi berbasis indexing dibangun **baru** di keuangan dan membaca SKP lewat kontrak |

---

## Wave 7 — Budgeting, Costing, Treasury

Seluruhnya **bangun baru** di domain `keuangan`. Tidak ada pemindahan.

Satu titik sambung: `Finance\ExpenseRequestService` (pengajuan biaya berjenjang)
akan jadi dasar commitment accounting — dipindahkan ke `keuangan/budgeting-costing`.

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `Finance\ExpenseRequestService` | `keuangan/budgeting-costing` | move | `finance.expense_requests` | Rencana | RENDAH |

---

## Wave 1-pra — Fondasi yang sudah terpasang (2026-09-12)

Bukan pemindahan, melainkan penambahan yang **tidak menggantikan apa pun** — jadi
tidak melahirkan dual source of truth. Dicatat di sini supaya urutannya terlihat.

| Yang ditambahkan | Menggantikan? | Status |
|---|---|---|
| Constraint trigger balance pada `finance.journal_entries`/`journal_lines` | Tidak — **melengkapi** pemeriksaan PHP di `LedgerService`, tidak menghapusnya. Dua lapis disengaja: PHP memberi pesan yang menolong, basis data menjamin | Selesai |
| `finance.idempotency_records` + `IdempotencyGuard` | Tidak — belum ada apa pun sebelumnya | Selesai (belum terpasang di endpoint) |
| `finance.document_number_series` + `document_numbers` + `GaplessNumberAllocator` | **Belum** — delapan `NumberAllocator` lama masih berjalan | Selesai (penggantian menyusul per modul) |

**Penting untuk Definition of Done:** `GaplessNumberAllocator` berdampingan dengan
delapan `NumberAllocator` lama. Itu **bukan** dual source of truth karena keduanya
menomori dokumen yang berbeda — tapi ia **akan menjadi** dual source of truth bila
ada dokumen yang dinomori keduanya. Penggantian per modul dicatat di baris
`*.number_sequences` pada bagian Governance di bawah.

---

## Cross-cutting — Governance (paralel sejak Wave 1)

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `platform.audit_logs` | — | **keep** + perluas | 1 tabel terpartisi | Keputusan | Audit adalah kebutuhan seluruh sistem, bukan hanya keuangan. Keuangan **menambah** hash chaining dan legal hold di atasnya |
| `platform.permissions`, `roles` | — | **keep** | — | Keputusan | RBAC milik platform. Matriks SoD dibangun di `keuangan/governance` yang membaca RBAC |
| `*.number_sequences` (tersebar 8 konteks) | `keuangan/governance` — `GaplessNumberAllocator` | merge | 8 tabel | **Generator siap; penggantian per modul menyusul** | SEDANG — hanya 4 jenis dokumen yang WAJIB gapless (faktur pajak, jurnal, bukti kas, kuitansi); sisanya tetap memakai penomoran lama, dan itu benar |

---

## Yang TIDAK Dipindahkan (dan alasannya)

Dicatat supaya keputusannya terlihat, bukan tampak terlewat:

| Hal | Alasan tetap di tempatnya |
|---|---|
| `organization.units` | Struktur organisasi dipakai seluruh sistem; keuangan menambah klasifikasi yang merujuk, bukan menggantikan |
| `clinical.procedures` | Tindakan adalah rekam medis. Komponen jasa yang dibekukan di sana adalah **bukti apa yang terjadi**, bukan perhitungan keuangan |
| `platform.audit_logs` | Audit lintas domain |
| Buku besar stok per konteks | Menyatukan tabelnya membuat obat bisa terjual di kasir toko |
| `hr.employees` | Data kepegawaian; payroll keuangan membacanya lewat kontrak |

---

## Rekapitulasi

| Jenis | Jumlah entri | Selesai |
|---|---:|---:|
| move | 19 | 0 |
| merge | 9 | 0 |
| deprecate | 0 | 0 |
| keep (keputusan tercatat) | 7 | — |
| **TOTAL rencana pemindahan** | **28** | **0** |

**Definition of Done global belum terpenuhi:** 0 dari 28 entri selesai.
