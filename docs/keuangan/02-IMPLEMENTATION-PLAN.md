# Rencana Implementasi — Domain Keuangan

**Tanggal:** 2026-09-12
**Dasar:** `00-DISCOVERY-REPORT.md`, `01-GAP-ANALYSIS.md`, `MIGRATION-MAP.md`

---

## Prinsip Eksekusi

1. **Tidak ada wave yang dimulai sebelum gate wave sebelumnya lulus dan dilaporkan.**
2. **Tidak ada modul ditulis ulang** bila bisa dipindahkan — lihat `MIGRATION-MAP.md`.
3. **Governance (Modul M) dikerjakan paralel sejak Wave 1**, diterapkan ke tiap modul
   saat modul itu dibangun.
4. Setiap wave berakhir dengan: suite penuh hijau + penelusuran lewat HTTP sebagai
   pengguna sungguhan (bukan hanya uji yang memanggil service langsung).

Butir 4 bukan formalitas: verifikasi sebelumnya menemukan dua lubang keamanan dan
enam belas mesin tanpa layar yang **seluruhnya lolos dari 2.142 uji hijau**, karena
uji per konteks selalu memakai pengguna yang berhak dan selalu memanggil service
langsung.

---

## Wave 0 — Discovery ✅ SELESAI

**Keluaran:** empat dokumen di `docs/keuangan/`.
**Gate:** laporan di-review manusia.
**Status:** menunggu review. **13 pertanyaan terbuka**, 8 di antaranya memblokir wave tertentu.

---

## Wave 1 — Master Data + Shared + Kerangka Posting Engine

**Blokir DIBUKA 2026-09-12.** Pengguna menyerahkan Q1, Q2, dan Q4 kepada penilaian
saya setelah Q3 dijawab (PTN-BH). Keputusannya tercatat di
`03-KEPUTUSAN-ARSITEKTUR.md` sebagai KA-1 sampai KA-5.

### Kemajuan

| # | Pekerjaan | Status |
|---|---|---|
| 1.1 | `Keuangan/Shared` — value object `Money` (KA-1), `KeuanganException` | ✅ Selesai — 19 uji |
| 1.2 | Constraint balance jurnal di basis data | ✅ Selesai (Wave 1-pra) |
| 1.3 | `idempotency_records` + `IdempotencyGuard` | ✅ Selesai (Wave 1-pra) |
| 1.4 | COA multi-dimensi | Belum |
| 1.5 | **Charge Description Master** | ✅ Selesai — 16 uji |
| 1.6 | Versioning tarif | ✅ Sudah ada sebelumnya (koreksi §10 Discovery) |
| 1.7 | Mapping Engine — CDM↔COA | ✅ Selesai; ICD↔INA-CBG↔SATUSEHAT belum |
| 1.8 | Master penjamin & kontrak berperiode | Belum |
| 1.9 | Kalender periode 4 status | Belum |
| 1.10 | Klasifikasi revenue/cost center | Belum |
| 1.11 | Kerangka Posting Engine + outbox | Belum |
| 1.12 | Gapless number generator | ✅ Selesai (Wave 1-pra) |

### Yang berubah dari rencana semula, dan alasannya

**CDM tidak lagi menggantikan `catalog.tariffs`.** Discovery awal menyimpulkan tarif
layanan "tidak bitemporal" dan harus di-*merge*. Kesimpulan itu **salah** —
`catalog.tariffs` sudah punya `valid_from`/`valid_until`, berdimensi penjamin dan
kelas, diresolusi per tanggal transaksi, dan komponen jasanya dijaga CHECK.

Rencana yang benar: CDM sebagai **katalog penaut** yang memberi kode global dan
memegang pemetaan ke akun — bagian yang benar-benar belum ada. Koreksi lengkapnya di
`00-DISCOVERY-REPORT.md` §10, dan `MIGRATION-MAP.md` disesuaikan.

Ini persis alasan Tahap 0 diwajibkan: tanpa discovery, saya akan membangun ulang
mekanisme temporal yang sudah bekerja.

---

### Rencana semula (dipertahankan sebagai catatan)

### Yang dibangun

| # | Pekerjaan | Jenis |
|---|---|---|
| 1.1 | `keuangan/shared` — value object `Money`, `Period`, `AccountCode`; contract & domain event | baru |
| 1.2 | **Constraint balance jurnal di tingkat basis data** (trigger deferred) | baru — lihat R2 |
| 1.3 | Tabel `idempotency_records` + middleware/trait idempotency | baru |
| 1.4 | COA multi-dimensi — hierarki + dimensi cost_center/unit/sumber dana/proyek/DPJP/program | perluas `finance.chart_of_accounts` |
| 1.5 | **Charge Description Master** — kode item global tunggal | merge 6 tabel tarif |
| 1.6 | Versioning tarif `valid_from`/`valid_to` | baru pada CDM |
| 1.7 | Mapping Engine — CDM↔COA↔ICD↔INA-CBG↔SATUSEHAT↔LOINC | perluas 2 tabel mapping yang ada |
| 1.8 | Master penjamin & kontrak berperiode | perluas `catalog.payers` |
| 1.9 | Kalender periode: open/soft-close/closed/locked | perluas `period_closings` |
| 1.10 | Klasifikasi revenue center vs cost center | baru, merujuk `organization.units` |
| 1.11 | Kerangka Posting Engine + outbox + worker | baru |
| 1.12 | **Governance**: gapless number generator, hash chaining audit | baru |

### Gate Wave 1

- [ ] CDM tunggal terbentuk; seluruh tarif lama terbaca lewat CDM
- [ ] Mapping engine coverage **100%** untuk item aktif — item tanpa mapping akun **tidak bisa diaktifkan**
- [ ] Jurnal dummy balance; **constraint DB menolak jurnal tidak balance** (dibuktikan uji yang sengaja mencoba menyimpan jurnal miring)
- [ ] Idempotency terpasang dan terbukti menolak double-submit
- [ ] `catalog.tariffs` menjadi view; tidak ada dual source of truth
- [ ] Suite penuh hijau

### Risiko Wave 1

| Risiko | Mitigasi |
|---|---|
| CDM memutus billing/clinical/reporting | CDM dibangun **berdampingan** dulu; pemanggil dialihkan satu per satu; tabel lama jadi view; baru dihapus setelah tervalidasi |
| Resolusi tarif berdasarkan tanggal mengubah hasil rekap lama | Nilai pada transaksi sudah dibekukan — rekap lama membaca snapshot, bukan CDM |

---

## Wave 2 — Revenue Front, Billing, Kasir

**Blokir aktif:** Q6 (selisih kelas), Q7 (matriks approval).

### Yang dibangun

| # | Pekerjaan | Jenis |
|---|---|---|
| 2.1 | Relokasi `Billing\*` → `keuangan/billing` | move |
| 2.2 | **Idempotency pada charge capture** — `idempotency_key` per order | perluas |
| 2.3 | **Optimistic locking** pada bill (`version`) | perluas |
| 2.4 | Billing per jenis layanan (IGD, HD, kemoterapi, ODC, MCU, OK, ICU) | perluas |
| 2.5 | Paket & bundling + exclusion handling | baru |
| 2.6 | Split billing & multi-penjamin (COB) | baru |
| 2.7 | Selisih kelas proporsional per hari | baru — **menunggu Q6** |
| 2.8 | Diskon/waiver + matriks approval berjenjang | perluas — **menunggu Q7** |
| 2.9 | Reversal charge (bukan hard delete) | baru |
| 2.10 | Billing hold + validasi kelengkapan saat closing | baru |
| 2.11 | Relokasi kasir; refund & petty cash | move + baru |
| 2.12 | Pengembalian deposit (celah tercatat sejak domain K) | baru |

### Gate Wave 2

- [ ] Uji konkurensi membuktikan **tidak ada double charge** pada request ganda
- [ ] Closing kasir balance; blind count tetap terjaga
- [ ] Reversal membuktikan GL tetap balance
- [ ] Load test awal: **100 charge/detik**
- [ ] Setiap layar baru ditelusuri lewat HTTP per peran

---

## Wave 3 — Klaim & AR

**Blokir lunak:** Q11 (kredensial sandbox) — bisa dikerjakan dengan adapter palsu,
tapi gate "end-to-end jalan" hanya bisa dibuktikan penuh setelah kredensial ada.

| # | Pekerjaan | Jenis |
|---|---|---|
| 3.1 | Relokasi `ClaimService`, `SmartClaimService` → `keuangan/klaim` | move |
| 3.2 | **Layar klaim** — mesin sudah ada, tidak ada yang bisa menjalankannya | baru |
| 3.3 | Claim scrubbing — rule engine konfigurabel tanpa deploy | baru |
| 3.4 | Denial management + analitik root cause | baru |
| 3.5 | Rekonsiliasi 3 arah: diajukan vs disetujui vs uang masuk | baru |
| 3.6 | Dunning otomatis + eskalasi | baru |
| 3.7 | Allowance for doubtful accounts | baru |

### Gate Wave 3

- [ ] Klaim BPJS end-to-end jalan (adapter palsu bila kredensial belum ada — **dinyatakan terang**)
- [ ] AR aging akurat; tiap klaim dapat ditelusuri sampai ke baris charge asalnya

---

## Wave 4 — GL, Posting Engine Penuh, Pelaporan

**Blokir aktif:** Q3 (standar akuntansi).

| # | Pekerjaan | Jenis |
|---|---|---|
| 4.1 | Relokasi `LedgerService`, `PostingService`, `FinancialStatementService` | move |
| 4.2 | **Posting Engine penuh** — cakupan naik dari 1 jadi ±15 jenis transaksi | perluas |
| 4.3 | **Posting asinkron** — outbox + queue + retry + DLQ + monitoring | baru |
| 4.4 | Template jurnal konfigurabel per jenis transaksi | baru |
| 4.5 | Jurnal manual + approval berjenjang + lampiran | perluas |
| 4.6 | Tutup buku: checklist, locking, jurnal penutup, saldo awal | perluas |
| 4.7 | Laporan sesuai standar terpilih — **menunggu Q3** | baru |
| 4.8 | **Job rekonsiliasi harian subledger↔GL + alert** | baru |
| 4.9 | Drill-down laporan → dokumen sumber | baru |
| 4.10 | Reporting schema / materialized view terpisah dari OLTP | baru |

### Gate Wave 4

- [ ] **Tidak ada jurnal tidak balance** di basis data
- [ ] Rekonsiliasi harian berjalan otomatis dan hijau
- [ ] Seluruh angka laporan dapat di-drill-down ke dokumen sumber

---

## Wave 5 — Persediaan, AP, Aset

**Blokir aktif:** Q8 (metode valuasi), Q9 (depresiasi). **Risiko: R3** (5 master supplier).

| # | Pekerjaan | Jenis |
|---|---|---|
| 5.1 | Satukan **kode** buku besar stok 4 konteks (skema tetap terpisah) | merge |
| 5.2 | **COGS/HPP otomatis menjurnal** — belum pernah ada | baru |
| 5.3 | Konsinyasi alkes/implan | baru |
| 5.4 | Jurnal penyesuaian opname + approval | baru |
| 5.5 | Jurnal beban expired/rusak/pemusnahan | baru |
| 5.6 | Vendor master tunggal | merge — **butuh pembersihan data manual** |
| 5.7 | 3-way matching otomatis + toleransi konfigurabel | baru |
| 5.8 | Perpajakan: PPN, PPh 21/22/23/4(2), e-Faktur, e-Bupot | baru |
| 5.9 | Kontrak & retensi | baru |
| 5.10 | Aset tetap: masa manfaat, sumber dana, depresiasi otomatis, disposal, CIP | perluas |

### Gate Wave 5

- [ ] Nilai persediaan di modul logistik **= saldo akun persediaan di GL**, dibuktikan job rekonsiliasi harian
- [ ] Nilai AP = saldo GL; nilai aset = saldo GL
- [ ] Nomor faktur pajak **gapless**

---

## Wave 6 — Jaspel & Remunerasi

**Blokir aktif:** Q5 (formula jaspel).

| # | Pekerjaan | Jenis |
|---|---|---|
| 6.1 | Relokasi `MedicalFeeReportService` | move |
| 6.2 | **Pembayaran jasa medis** — celah tercatat sejak domain K | baru |
| 6.3 | Split fee operator/asisten/anestesi/instrumen — **menunggu Q5** | baru |
| 6.4 | Remunerasi berbasis indexing — **menunggu Q5** | baru |
| 6.5 | Payroll + PPh 21 | baru |
| 6.6 | **Snapshot formula & parameter per periode** (syarat reproducibility) | baru |

### Gate Wave 6

- [ ] Perhitungan jaspel dapat **direproduksi** dari data billing periode itu
- [ ] Satu periode **tidak bisa dibayar dua kali**

---

## Wave 7 — Budgeting, Costing, Treasury

**Blokir aktif:** Q3 (RBA/RKA sesuai badan hukum), Q10 (cost driver).

| # | Pekerjaan | Jenis |
|---|---|---|
| 7.1 | Penyusunan anggaran bottom-up + revisi | baru |
| 7.2 | **Commitment accounting** — PO ditolak bila anggaran habis | baru |
| 7.3 | Unit cost per layanan | baru |
| 7.4 | ABC step-down & reciprocal — **menunggu Q10** | baru |
| 7.5 | Analisis margin per casemix vs tarif INA-CBG | baru |
| 7.6 | Profitabilitas per unit/DPJP/penjamin | baru |
| 7.7 | Variance analysis | baru |
| 7.8 | Rekonsiliasi bank otomatis (CSV/MT940/API) | baru |
| 7.9 | Cash flow forecasting | baru |

---

## Wave 8 — Hardening

| # | Pekerjaan |
|---|---|
| 8.1 | Load test 100 charge/detik dengan data berskala 2.000 pasien/hari |
| 8.2 | Security review + uji SoD menolak kombinasi role terlarang |
| 8.3 | Deteksi anomali & fraud |
| 8.4 | OpenAPI 3.1 lengkap |
| 8.5 | `ARCHITECTURE.md` — diagram konteks, alur data, ERD final |
| 8.6 | Strategi arsip & retensi 5 tahun + legal hold |

---

## Estimasi Kasar

| Wave | Bobot relatif | Catatan |
|---|---:|---|
| 1 | 15% | Fondasi; paling menentukan |
| 2 | 20% | Terbesar; jantung pendapatan |
| 3 | 10% | Banyak yang tinggal dipindah |
| 4 | 15% | Posting engine penuh + asinkron |
| 5 | 15% | Perpajakan menambah bobot |
| 6 | 8% | Bergantung penuh pada Q5 |
| 7 | 12% | Hampir seluruhnya baru |
| 8 | 5% | |

**Catatan jujur tentang estimasi waktu:** saya sengaja tidak menuliskan estimasi
hari/minggu. Delapan dari tiga belas pertanyaan terbuka memblokir wave tertentu, dan
sebagian di antaranya (standar akuntansi, bagan akun, formula jaspel) menuntut
keputusan kebijakan RSP UI yang waktunya tidak saya kendalikan. Estimasi waktu yang
saya karang sekarang akan salah, dan salahnya akan dipakai orang membuat jadwal.

---

## Wave 1-pra — Tiga Fondasi ✅ SELESAI 2026-09-12

Disetujui pengguna, dikerjakan sambil menunggu jawaban pertanyaan yang memblokir.
Ketiganya **tidak bergantung pada satu pun pertanyaan terbuka**.

| # | Yang dibangun | Berkas |
|---|---|---|
| 1 | **Constraint balance jurnal di basis data** — constraint trigger DEFERRABLE INITIALLY DEFERRED; menolak jurnal miring DAN jurnal tanpa baris | `2027_03_10_000001_enforce_journal_balance_in_database.php` |
| 2 | **Idempotency** — tabel `finance.idempotency_records` + `IdempotencyGuard` + perintah pembersihan terjadwal | `2027_03_10_000002_*`, `IdempotencyGuard.php`, `PruneIdempotencyRecords.php` |
| 3 | **Gapless number generator** — `document_number_series` + `document_numbers` + `GaplessNumberAllocator` | `2027_03_10_000003_*`, `GaplessNumberAllocator.php` |

**Uji:** 16 lulus, 36 asersi (`FinancialFoundationTest`).

### Keputusan rancangan yang diambil saat membangunnya

**Constraint trigger, bukan CHECK.** CHECK hanya melihat satu baris; keseimbangan
jurnal adalah sifat sekumpulan baris, dan di tengah transaksi jurnal yang baru punya
satu baris memang belum seimbang — itu keadaan yang sah. Maka pemeriksaannya
ditangguhkan sampai COMMIT.

**Dua trigger, bukan satu.** Trigger pada `journal_lines` menangkap baris yang
merusak keseimbangan. Trigger pada `journal_entries` menangkap header yang
disisipkan tanpa baris sama sekali — tanpa itu, jurnal kosong lolos karena tidak ada
baris yang memicu trigger pertama.

**Dua fungsi PL/pgSQL, bukan satu.** Percobaan pertama memakai satu fungsi yang
menyebut `NEW.journal_entry_id`, dan itu meledak begitu dipasang pada
`journal_entries` yang tidak punya kolom itu — PL/pgSQL menolaknya saat jalan,
bahkan di dalam `COALESCE`. Galatnya menyamar sebagai "constraint bekerja" padahal
sebabnya sama sekali lain.

**Idempotency memakai SAVEPOINT.** Versi pertama membaca baris yang sudah ada tepat
setelah INSERT gagal — dan itu meledak bila pemanggilnya berada di dalam transaksi:
PostgreSQL menolak setiap perintah berikutnya dengan 25P02 "current transaction is
aborted". Karena **setiap pemakaian sungguhan ada di dalam transaksi**, versi pertama
akan gagal di hampir seluruh pemakaian nyata dan hanya bekerja pada pemakaian sepele.

**Penomoran membungkus transaksinya sendiri.** Versi pertama menolak pemanggilan
saat `transactionLevel() === 0` untuk memaksa pemanggil membungkusnya. Maksudnya
benar, alatnya salah: di bawah `RefreshDatabase` pemeriksaan itu tidak pernah menyala
sehingga tidak membuktikan apa pun, dan di produksi pemanggil yang lupa menerima
galat alih-alih nomor.

### Catatan pengujian yang perlu diketahui penerus

Trigger DEFERRED **tidak menembak di bawah `RefreshDatabase`**, karena seluruh uji
dibungkus satu transaksi yang sengaja tidak pernah di-commit. Uji yang mengandalkannya
akan lolos tanpa membuktikan apa pun. Pemecahannya `SET CONSTRAINTS ALL IMMEDIATE`
di dalam transaksi uji — lihat `FinancialFoundationTest::tembakConstraint()`.

### Yang BELUM dikerjakan dari ketiga fondasi ini

- `IdempotencyGuard` **belum dipasang** pada satu pun endpoint. Ia siap dipakai,
  tetapi pemasangannya menyentuh billing dan kasir — itu pekerjaan Wave 2.
- `GaplessNumberAllocator` **belum menggantikan** delapan `NumberAllocator` yang ada.
  Penggantian itu ada di `MIGRATION-MAP.md` dan dikerjakan saat modulnya dipindahkan.
- Hash chaining audit trail belum dikerjakan.

Dinyatakan terang di sini supaya tidak ada yang mengira Definition of Done butir
"idempotency terpasang di seluruh endpoint" sudah terpenuhi.
