# Migration Map — Konsolidasi ke Domain `keuangan`

**Tanggal dibuat:** 2026-09-12
**Status keseluruhan:** RENCANA — belum ada satu pun pemindahan dieksekusi.

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
| `Catalog\*` (tarif layanan) | `keuangan/master-data` — CDM | merge | `catalog.tariffs`, `catalog.services` | Rencana | **TINGGI** — dipakai billing, clinical, reporting. Putus = tagihan berhenti terbentuk |
| `pharmacy.drug_markups` | `keuangan/master-data` — CDM (kategori obat) | merge | `pharmacy.drug_markups` | Rencana | SEDANG |
| `retail.price_tiers`, `retail.product_prices` | `keuangan/master-data` — CDM (kategori retail) | merge | 2 tabel | Rencana | RENDAH — retail belum berisi data |
| `parking.rates` | `keuangan/master-data` — CDM (kategori parkir) | merge | `parking.rates` | Rencana | RENDAH |
| `inpatient.rooms.daily_rate` | `keuangan/master-data` — CDM (akomodasi) | move | `inpatient.rooms` | Rencana | SEDANG — dipakai `v_room_charge` |
| `encounter.corporate_mcu_bookings` (tarif paket) | `keuangan/master-data` — CDM (paket) | move | 1 tabel | Rencana | RENDAH |
| `finance.chart_of_accounts` | `keuangan/master-data` — COA multi-dimensi | move | 1 tabel | Rencana | SEDANG — perlu tambah dimensi & hierarki |
| `finance.period_closings` | `keuangan/master-data` — kalender periode | move | 1 tabel | Rencana | RENDAH |
| `catalog.payers` | `keuangan/master-data` — master penjamin & kontrak | move | 1 tabel | Rencana | SEDANG |
| `integration.payer_code_mappings` | `keuangan/master-data` — mapping engine | move | 1 tabel | Rencana | SEDANG |
| `integration.satusehat_code_mappings` | `keuangan/master-data` — mapping engine | move | 1 tabel | Rencana | SEDANG |
| `organization.units` | — | **keep** | — | Keputusan | Struktur organisasi bukan milik keuangan; keuangan menambah **klasifikasi** revenue/cost center yang merujuk `unit_id` |

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

## Cross-cutting — Governance (paralel sejak Wave 1)

| Lokasi Lama | Lokasi Baru | Jenis | Tabel Terdampak | Status | Risiko |
|---|---|---|---|---|---|
| `platform.audit_logs` | — | **keep** + perluas | 1 tabel terpartisi | Keputusan | Audit adalah kebutuhan seluruh sistem, bukan hanya keuangan. Keuangan **menambah** hash chaining dan legal hold di atasnya |
| `platform.permissions`, `roles` | — | **keep** | — | Keputusan | RBAC milik platform. Matriks SoD dibangun di `keuangan/governance` yang membaca RBAC |
| `*.number_sequences` (tersebar 6 konteks) | `keuangan/governance` — gapless generator | merge | 6 tabel | Rencana | SEDANG |

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
