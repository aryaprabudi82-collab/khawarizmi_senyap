# Gap Analysis — Modul A sampai M

**Tanggal:** 2026-09-12
**Dasar:** hasil scan `00-DISCOVERY-REPORT.md`

Legenda status:

- **ADA** — sudah terbangun dan teruji, tinggal dipindahkan/direfaktor ke domain `keuangan`
- **SEBAGIAN** — ada fondasinya, perlu dilengkapi
- **BELUM** — belum ada sama sekali

---

## MODUL A — Master Data Keuangan

| Sub-modul | Status | Lokasi sekarang | Yang kurang |
|---|---|---|---|
| Chart of Accounts | **SEBAGIAN** | `finance.chart_of_accounts` | Tidak hierarkis (tidak ada `parent_id`), **tidak multi-dimensi** (tidak ada cost_center, unit, sumber dana, proyek, DPJP, program). Pemisahan dana pelayanan/pendidikan/penelitian **belum ada** |
| Charge Description Master | **BELUM** | tersebar di 6 tabel | Tidak ada kode item global tunggal |
| Mapping Engine | **SEBAGIAN** | `integration.satusehat_code_mappings`, `integration.payer_code_mappings` | Sudah ada mekanisme pemetaan kode penjamin & SATUSEHAT. **Belum ada** pemetaan CDM↔COA, ICD-10↔INA-CBG, LOINC |
| Master Penjamin & Kontrak | **SEBAGIAN** | `catalog.payers` | Ada master penjamin. **Belum ada** skema tarif per penjamin, plafon, cost-sharing, coverage/exclusion rules, masa berlaku kontrak |
| Versioning tarif | **BELUM** | `catalog.tariffs` | **Tidak ada `valid_from`/`valid_to`**. Diselamatkan pembekuan nilai pada transaksi, tapi tarif historis tidak bisa direkonstruksi |
| Cost center & struktur organisasi | **SEBAGIAN** | `organization.units` | Ada hierarki unit. **Belum ada** klasifikasi revenue center vs cost center |
| Kalender periode akuntansi | **ADA** | `finance.period_closings` | Sudah ada tutup/buka periode. Status masih 2 nilai, perlu diperluas ke open/soft-close/closed/locked |
| Master kelas perawatan | **SEBAGIAN** | `inpatient.rooms.room_class` | Kelas ada sebagai kolom, belum jadi master dengan hak kelas |

**Kesimpulan A:** ini modul dengan gap terbesar dan **wajib dikerjakan pertama** karena
seluruh modul lain bergantung padanya.

---

## MODUL B — Revenue Cycle Front-End

| Sub-modul | Status | Lokasi sekarang | Catatan |
|---|---|---|---|
| Eligibility & SEP BPJS | **ADA** | `Integration\Bpjs\EligibilityService`, `SepService` | Berfungsi, **punya layar**. Satu SEP aktif per kunjungan dijamin partial unique index |
| Cost estimation | **ADA** | `Finance\CostEstimateService` | Sudah ada + layar + cetak |
| Deposit & jaminan | **SEBAGIAN** | `Finance\DepositService`, `finance.deposits` | Penerimaan ada. **Pengembalian deposit BELUM ADA** (tercatat `pengembalian_deposit_pasien` belum dibangun). Auto-alert saldo menipis belum ada |
| Authorization asuransi | **BELUM** | — | Belum ada pengajuan/tracking approval/plafon terpakai |
| Registrasi finansial | **SEBAGIAN** | `encounter.registrations`, `inpatient.admissions` | Kelas ada. Naik/turun kelas ada di `bed_assignments`, tapi **perhitungan selisihnya belum ada** |

**Aturan kritis terpenuhi?** Deposit sebagai liabilitas: `finance.chart_of_accounts`
punya akun `2-1000 Titipan Deposit Pasien` dan `DepositService` menjurnal ke sana. **YA.**

---

## MODUL C — Charge Capture & Billing

| Sub-modul | Status | Lokasi sekarang | Catatan |
|---|---|---|---|
| Charge Posting Engine | **SEBAGIAN** | `Billing\InvoiceService`, view `v_*_charge` dari 4 konteks | Charge terbentuk dari order/resep/tindakan lewat kontrak view. **TIDAK IDEMPOTENT** — tidak ada `idempotency_key` |
| Billing per jenis layanan | **SEBAGIAN** | `billing.invoices.care_type` | Ralan/ranap ada. IGD, HD, kemoterapi, ODC, home care, MCU, OK, ICU **belum dibedakan** |
| Paket & bundling | **SEBAGIAN** | `encounter.corporate_mcu_bookings` | Paket MCU korporat ada. Paket operasi/persalinan + exclusion handling **belum** |
| Split billing / multi-penjamin | **BELUM** | — | Satu invoice satu penjamin. COB belum ada |
| Selisih kelas | **BELUM** | — | Data pindah kelas ada (`bed_assignments`), perhitungan selisih belum |
| Diskon/subsidi/waiver | **SEBAGIAN** | `tambahan_biaya`/`potongan_biaya` | Ada, **sudah bergerbang** (diperbaiki saat verifikasi domain I). Matriks approval berjenjang **belum ada** |
| Adjustment & koreksi | **SEBAGIAN** | pembatalan pembayaran `voided_at` | Pembayaran bisa dibatalkan. **Reversal jurnal belum ada** |
| Billing hold | **BELUM** | — | |
| Bill closing | **SEBAGIAN** | status invoice | Ada penutupan, validasi kelengkapan belum |

**Optimistic locking pada bill:** **BELUM ADA** kolom `version`.

---

## MODUL D — Coding, Klaim & Piutang

| Sub-modul | Status | Lokasi sekarang | Catatan |
|---|---|---|---|
| Clinical coding & grouping | **SEBAGIAN** | `clinical.diagnoses`, `Integration\Bpjs\ClaimService` | ICD-10 ada (kamus baru 16 kode contoh). Grouping INA-CBG ada mesinnya, **tanpa layar** |
| Claim scrubbing | **BELUM** | — | Tidak ada rule engine validasi pra-kirim |
| Submission & tracking | **ADA** | `integration.claims` + lifecycle status | Mesin lengkap, **tanpa layar** |
| Denial management | **SEBAGIAN** | `claim_monitorings` | Jawaban BPJS disimpan. Kategorisasi penyebab & analitik root cause belum |
| Rekonsiliasi pembayaran klaim | **BELUM** | — | Matching 3 arah belum ada |
| AR aging & collection | **ADA** | `Finance\OtherReceivableService::aging()`, `billing.patient_receivables` | Aging ada. Dunning otomatis belum |
| Write-off & pencadangan | **SEBAGIAN** | `OtherReceivableService::writeOff()` | Write-off ada dengan jejak. **Allowance for doubtful accounts belum** |

---

## MODUL E — Kasir & Cash Management

| Sub-modul | Status | Lokasi sekarang | Catatan |
|---|---|---|---|
| POS multi-loket multi-shift | **ADA** | `billing.cashier_shifts` | |
| Multi-metode pembayaran | **ADA** | `billing.payments.method`, `payment_channels` | Tunai, transfer, QRIS, kanal bank. **Multi-currency belum** |
| Closing shift & blind cash count | **ADA** | `Billing\CashierClosingService` | **Sudah blind count** — `recorded_cash` diinput dulu. Variance wajib beralasan (CHECK). Layarnya dibangun saat verifikasi |
| Refund & pengembalian deposit | **BELUM** | — | |
| Petty cash per unit | **BELUM** | — | |
| Void & reprint | **SEBAGIAN** | `payments.voided_at` | Void ada. Reprint & pembatasan ketat belum |

---

## MODUL F — Inventory Costing

| Sub-modul | Status | Catatan |
|---|---|---|
| Inventory valuation | **SEBAGIAN** | 4 implementasi terpisah (pharmacy/retail/inventory/kitchen). Pharmacy FEFO per batch, retail HPP beku per baris. **Metode tidak seragam dan tidak dinyatakan sebagai kebijakan akuntansi** |
| COGS/HPP otomatis | **BELUM** | Nilai HPP dihitung, **tapi tidak pernah menjurnal** |
| Konsinyasi | **BELUM** | |
| Stock opname & selisih | **ADA** | 4 konteks punya opname. Selisih **dihitung**, tidak disimpan. **Jurnal penyesuaian belum ada** |
| Expired/damaged/pemusnahan | **SEBAGIAN** | `kind='kadaluarsa'`,`'rusak'` pada stock_movements + `inventory.stock_write_offs`. **Jurnal beban belum** |
| Transfer antar gudang | **ADA** | `pharmacy` mutasi antar depo | |

**Gap terbesar:** nilai persediaan **tidak pernah tersambung ke GL**.

---

## MODUL G — Procure to Pay

| Sub-modul | Status | Catatan |
|---|---|---|
| PR → PO → GR → Invoice | **SEBAGIAN** | Alur ada di 5 konteks. **3-way matching otomatis belum ada** — penerimaan diverifikasi manual |
| Vendor master | **SEBAGIAN** | **5 master supplier terpisah** (pharmacy, inventory, asset, kitchen, retail). Perlu konsolidasi |
| Invoice processing & payment scheduling | **SEBAGIAN** | `finance.payables` + `payable_payments`. Batch/prioritas/jatuh tempo belum |
| Perpajakan | **BELUM** | PPN/PPh/e-Faktur/e-Bupot **tidak ada sama sekali** |
| Kontrak & retensi | **BELUM** | |
| AP aging | **ADA** | `PayableService::aging()` |

---

## MODUL H — General Ledger & Pelaporan

| Sub-modul | Status | Catatan |
|---|---|---|
| Posting Engine | **SEBAGIAN** | Hanya 2 method, **sinkron**, cakupan 1 dari ±15 transaksi |
| Jurnal otomatis subledger | **BELUM** | Hanya invoice |
| Jurnal manual + approval | **SEBAGIAN** | `postManual()` ada, balance dipaksa. **Approval berjenjang belum** |
| Tutup buku | **SEBAGIAN** | `period_closings` ada. Checklist closing & jurnal penutup belum |
| Laporan keuangan | **SEBAGIAN** | Neraca & Laba-Rugi **baru dibangun**. Arus Kas, Perubahan Ekuitas, CaLK **belum** |
| Konsolidasi entitas | **BELUM** | |
| Rekonsiliasi subledger↔GL | **BELUM** | **Tidak ada job rekonsiliasi sama sekali** |
| Trial balance & drill-down | **SEBAGIAN** | Trial balance ada. Drill-down ke dokumen sumber belum |

---

## MODUL I — Aset Tetap

| Sub-modul | Status | Catatan |
|---|---|---|
| Register aset | **ADA** | `asset.assets` — kode, lokasi, kategori. **Sumber dana & masa manfaat belum** |
| Depresiasi otomatis | **BELUM** | Tidak ada sama sekali |
| Revaluasi/transfer/disposal | **SEBAGIAN** | `asset_transfers` ada. Revaluasi & disposal + laba-rugi pelepasan belum |
| Integrasi IPSRS | **ADA** | Sudah satu master aset (`asset.assets`), pemeliharaan merujuk `asset_id` yang sama — **sudah benar** |
| Capitalization workflow (CIP) | **BELUM** | |
| Aset hibah | **SEBAGIAN** | `asset.donation_receipts` ada. Perlakuan akuntansinya belum |

---

## MODUL J — Budgeting & Cost Accounting

**Status keseluruhan: BELUM ADA.**

Tidak ada `budgets`, `cost_centers` sebagai klasifikasi, commitment accounting,
unit cost, ABC, analisis margin casemix, maupun variance analysis.

Satu-satunya yang mendekati: `Finance\ExpenseRequestService` (pengajuan biaya
berjenjang) — bisa jadi titik sambung commitment accounting.

---

## MODUL K — Payroll, Jaspel & Remunerasi

| Sub-modul | Status | Catatan |
|---|---|---|
| Payroll | **BELUM** | Tidak ada tabel payroll |
| Perhitungan jasa medis | **ADA** | 6 komponen **dibekukan** pada `clinical.procedures`; rekap di `MedicalFeeReportService` |
| Split fee dokter | **SEBAGIAN** | 6 komponen ada (dokter/paramedis/sarana/KSO/manajemen/BHP). Pembagian operator vs asisten vs anestesi **belum** |
| Remunerasi berbasis indexing | **BELUM** | |
| Distribusi jaspel | **BELUM** | **`bayar_jm_dokter` tercatat BELUM DIBANGUN** — tidak ada mekanisme membayarkan jasa ke dokter |
| Integrasi GL & PPh 21 | **BELUM** | |

**Aturan kritis "jaspel wajib dapat direproduksi":** komponen sudah dibekukan pada
transaksi — **sebagian terpenuhi**. Snapshot formula & parameter periode belum ada.

---

## MODUL L — Treasury & Bank

| Sub-modul | Status | Catatan |
|---|---|---|
| Rekonsiliasi bank | **BELUM** | `billing.channel_payments` mencatat setoran kanal, tapi impor rekening koran & matching otomatis belum |
| Cash flow forecasting | **BELUM** | |
| Manajemen multi-bank | **SEBAGIAN** | `billing.payment_channels` menyimpan kanal/bank. Saldo & mutasi rekening belum |
| Investasi/deposito | **BELUM** | |

---

## MODUL M — Governance, Audit & Compliance

| Komponen | Status | Catatan |
|---|---|---|
| RBAC | **ADA** | `platform.permissions` (1.183 kode), `roles`, gerbang per rute, diuji `RouteGateCoverageTest` |
| Segregation of Duties | **SEBAGIAN** | **Sudah ditegakkan di service** pada `ExpenseRequestService` (pengaju ≠ penyetuju, diuji) dan penagihan piutang. **Belum ada matriks SoD umum** yang menolak kombinasi role terlarang |
| Audit trail immutable | **SEBAGIAN** | `platform.audit_logs` ada & terpartisi. **Hash chaining belum**, jadi belum benar-benar tamper-evident |
| Approval matrix berjenjang | **SEBAGIAN** | Ada per kasus (pengajuan biaya, validasi hutang). **Belum konfigurabel** berdasarkan nominal |
| Nomor dokumen gapless | **SEBAGIAN** | `number_sequences` per konteks pakai `INSERT ... ON CONFLICT DO UPDATE` — aman konkurensi. **Gapless belum dijamin** (nomor bisa hilang bila transaksi rollback) |
| Deteksi anomali & fraud | **BELUM** | |
| Data retention & legal hold | **SEBAGIAN** | Retensi RM 25 tahun ada. Retensi finansial & legal hold belum |
| Compliance SATUSEHAT/FHIR | **SEBAGIAN** | Mapper FHIR ada, **tanpa layar pemetaan kode** |

---

## Ringkasan Proporsi

| Status | Perkiraan bobot | Modul |
|---|---:|---|
| ADA — tinggal pindah/refaktor | ±40% | C(sebagian), E, D(sebagian), H(sebagian), B(sebagian) |
| SEBAGIAN — perlu dilengkapi | ±25% | A, F, G, I, M |
| BELUM — bangun baru | ±35% | J, L, K(sebagian besar), claim scrubbing, perpajakan, depresiasi |
