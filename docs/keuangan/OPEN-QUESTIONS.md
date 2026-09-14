# Pertanyaan Terbuka — Wajib Dijawab Manusia

Sesuai GUARDRAIL butir 9: **ambiguitas aturan bisnis tidak boleh ditebak.**
Dokumen ini mencatat apa yang saya temukan tidak bisa saya putuskan sendiri.

Tanda **[BLOKIR]** berarti Wave yang bersangkutan tidak bisa dimulai sebelum
pertanyaannya dijawab.

---

## A. Arsitektur & Fondasi

### Q1 — Presisi nilai uang: DECIMAL(19,4) atau tetap DECIMAL(14,2)? **[BLOKIR Wave 1]**

Instruksi menyebut `DECIMAL(19,4)`. Skema saat ini memakai **skala 2** pada 191 kolom.

- Untuk Rupiah, skala 2 memadai pada **nilai transaksi**.
- Skala 4 dibutuhkan pada **hasil antara** — alokasi ABC, pembagian jaspel ke banyak
  pihak, selisih kelas proporsional per hari. Membulatkan tiap langkah ke sen
  menghasilkan selisih yang menumpuk.

Pilihan:

1. **Migrasi seluruh 191 kolom ke DECIMAL(19,4)** — konsisten, tapi menyentuh hampir
   setiap tabel dan seluruh uji yang membandingkan string `'250000.00'`.
2. **Kolom transaksi tetap (14,2); kolom hasil perhitungan pakai (19,4)** — perubahan
   jauh lebih kecil, tapi dua standar hidup berdampingan.
3. Tetap (14,2) semuanya, pembulatan dinyatakan sebagai kebijakan.

**Rekomendasi saya: pilihan 2**, dengan aturan tertulis: *nilai yang ditagihkan dan
dibayarkan berskala 2; nilai hasil alokasi/pembagian berskala 4 dan dibulatkan hanya
saat menjadi nilai yang ditagihkan.*

---

### Q2 — Apakah `keuangan` menjadi bounded context ke-26? **[BLOKIR Wave 1]**

Sistem ini menegakkan batas konteks lewat `ContextBoundaryTest`: sebuah modul hanya
boleh menyentuh skema miliknya sendiri atau **view yang diterbitkan** konteks lain.

Domain `keuangan` menurut instruksi harus memuat billing, kasir, klaim, persediaan,
AP, GL, aset. Itu berarti salah satu dari:

1. **`keuangan` jadi satu konteks besar** dengan satu skema `keuangan`, dan seluruh
   tabel dari billing/finance dipindahkan ke sana. Konsisten dengan instruksi, tetapi
   menghasilkan satu skema dengan ±100 tabel.
2. **`keuangan` jadi "domain" yang berisi beberapa konteks** (sub-skema: `keuangan_billing`,
   `keuangan_gl`, dst.) dengan batas tetap ditegakkan antar sub-modul.
3. **`keuangan` jadi lapisan aplikasi** (namespace `App\Keuangan\*`) yang menaungi
   modul-modul yang skemanya tetap terpisah.

**Rekomendasi saya: pilihan 2.** Instruksi BAGIAN 5 sendiri sudah menggambarkan
struktur per modul (`master-data/`, `billing/`, `general-ledger/`) dengan
`Domain/Application/Infrastructure` masing-masing dan komunikasi lewat contract —
itu adalah beberapa konteks di bawah satu domain, bukan satu konteks raksasa.
Pilihan 1 akan membuang penjagaan batas yang sudah bekerja dan diuji.

---

### Q3 — Standar akuntansi mana yang berlaku? ✅ **TERJAWAB 2026-09-12**

**Jawaban pengguna: RSP UI berstatus PTN-BH (Perguruan Tinggi Negeri Badan Hukum).**

Implikasi yang mengikat seluruh Wave 4 dan 7:

- Standar: **SAK umum (PSAK)**, BUKAN SAP/PSAP. RSP UI bukan satker pemerintah
  maupun BLU, jadi tidak memakai Laporan Realisasi Anggaran berbasis SAP.
- Laporan pokok: **Laporan Posisi Keuangan** (neraca), **Laporan Aktivitas /
  Laba Rugi**, **Laporan Arus Kas**, **Laporan Perubahan Ekuitas**, dan **CaLK**.
- Bagan akun mengikuti struktur PSAK: aset lancar/tidak lancar, liabilitas
  jangka pendek/panjang, ekuitas.
- Pemisahan **dana pelayanan / pendidikan / penelitian** dilakukan lewat
  DIMENSI pada COA (sumber_dana + program), bukan lewat entitas atau bagan
  akun terpisah. Ini yang membuat satu transaksi bisa dilaporkan per program
  tanpa menggandakan akun.
- Anggaran berbentuk **RKA PTN-BH** yang disahkan MWA, bukan RBA BLU.
- Istilah penyajian tetap **surplus/defisit**, bukan laba/rugi — RSP UI rumah
  sakit pendidikan, dan menamainya laba mengubah cara orang membaca angkanya.

### Q3b — Apakah RSP UI menyusun laporan sendiri lalu dikonsolidasi ke UI? **[BLOKIR Wave 4 — modul konsolidasi]**

Sebagai PTN-BH, UI adalah entitas pelaporan. Yang belum jelas: apakah RSP UI
menyusun laporan keuangan TERSENDIRI yang kemudian dikonsolidasi ke laporan UI,
atau laporannya langsung menyatu sebagai unit UI.

Ini menentukan apakah modul konsolidasi (BAGIAN 6 Modul H) perlu dibangun, dan
apakah dibutuhkan akun/eliminasi transaksi antar-unit.

**Tidak memblokir Wave 1.** Baru relevan saat laporan disusun di Wave 4.

---

### Q4 — Bagan akun RSP UI **[BLOKIR Wave 1]**

Bagan akun yang terpasang **12 akun**, seluruhnya contoh pengembangan. Bagan akun
sungguhan adalah keputusan bagian keuangan RSP UI dan menentukan struktur COA
multi-dimensi.

**Pertanyaan:** apakah RSP UI sudah punya bagan akun baku? Kalau ya, bisakah
diserahkan berkasnya? Kalau belum, apakah saya menyiapkan struktur kosong berdimensi
lengkap dan pengisiannya menyusul?

---

## B. Aturan Bisnis yang Tidak Boleh Ditebak

### Q5 — Formula jasa pelayanan (jaspel) **[BLOKIR Wave 6]**

Enam komponen sudah ada (`share_doctor`, `share_paramedic`, `share_facility`,
`share_kso`, `share_management`, `share_bhp`) dan **dibekukan** pada tiap tindakan.
Yang belum ada:

1. Pembagian `share_doctor` antara **operator, asisten, anestesi, perawat instrumen**
   pada tindakan operasi — berapa persen masing-masing?
2. Apakah proporsinya **berbeda per jenis layanan, per penjamin, dan per kelas**?
3. Indexing remunerasi (basic/competency/risk/emergency/position/performance) — apakah
   RSP UI sudah punya pedomannya?
4. Pajak PPh 21 atas jaspel: dipotong per pembayaran atau per bulan?

### Q6 — Aturan selisih kelas (naik/turun kelas) **[BLOKIR Wave 2]**

Data pindah kelas sudah tercatat (`inpatient.bed_assignments` per periode).
Yang belum jelas:

1. Untuk pasien **BPJS naik kelas**, selisih dihitung dengan cara apa? Peraturan BPJS
   mengenal beberapa skema (selisih tarif INA-CBG antar kelas, atau tambahan persentase).
   Mana yang dipakai RSP UI?
2. Kalau pasien pindah kelas **di tengah hari**, hari itu dihitung kelas lama atau baru?
3. **Turun kelas** atas permintaan sendiri — apakah ada pengembalian?
4. Naik kelas karena **kamar penuh** (bukan permintaan pasien) — siapa menanggung?

### Q7 — Matriks approval & batas nominal **[BLOKIR Wave 2]**

Instruksi menuntut approval berjenjang berdasarkan nominal untuk diskon, waiver,
write-off, jurnal manual, dan pembayaran. **Angkanya tidak ada.**

**Pertanyaan:** berapa batas nominal tiap jenjang, dan siapa pemegang kewenangannya?
(Contoh yang perlu diisi: diskon ≤ Rp X oleh kepala unit; ≤ Rp Y oleh manajer;
> Rp Y oleh direktur.)

### Q8 — Metode penilaian persediaan **[BLOKIR Wave 5]**

Saat ini tidak seragam: `pharmacy` memakai FEFO per batch dengan `cost_price` per
batch; `retail` membekukan HPP per baris penjualan. Kebijakan akuntansi menuntut
**satu metode yang dinyatakan**.

**Pertanyaan:** RSP UI memakai **FIFO** atau **moving average**? Apakah sama untuk
obat, BHP, barang non-medis, dan bahan makanan?

### Q9 — Metode dan masa manfaat depresiasi **[BLOKIR Wave 5]**

**Pertanyaan:** metode apa (garis lurus / saldo menurun), dan berapa masa manfaat per
golongan aset? Apakah mengikuti PMK tentang penyusutan BMN, atau kebijakan internal?

### Q10 — Cost driver untuk Activity-Based Costing **[BLOKIR Wave 7]**

Alokasi biaya unit penunjang (laundry, gizi, IPSRS, manajemen) ke unit pelayanan
menuntut cost driver yang disepakati — luas lantai, jumlah pegawai, jumlah kg cucian,
jumlah porsi, jam mesin.

**Pertanyaan:** apakah RSP UI sudah punya pedoman unit cost? Metode **step-down**
atau **reciprocal**?

---

## C. Pertanyaan Operasional

### Q11 — Kredensial sistem luar

Sepuluh slot kredensial BPJS, SATUSEHAT, E-Klaim, SIRANAP, Sisrute, Inhealth masih
kosong. Klaim end-to-end (Gate Wave 3) **tidak bisa diverifikasi sungguhan** tanpa
kredensial sandbox.

**Pertanyaan:** kapan kredensial sandbox tersedia? Sampai itu ada, Gate Wave 3 hanya
bisa dibuktikan dengan adapter palsu.

### Q12 — Data historis

Apakah ada data keuangan dari sistem lama yang harus dimigrasikan (saldo awal,
piutang berjalan, hutang berjalan, register aset)? Ini menentukan apakah dibutuhkan
modul migrasi data dan jurnal saldo awal.

### Q13 — Multi-currency untuk pasien internasional

Modul E menyebut multi-currency. **Pertanyaan:** apakah RSP UI benar-benar melayani
pasien internasional dengan penagihan mata uang asing, atau seluruh penagihan tetap
Rupiah? Ini menghindari membangun mekanisme kurs yang tidak akan pernah dipakai.

### Q14 — Tarif kamar belum berperiode

**Ditemukan 2026-09-14 saat menaut tarif ke CDM.**

`inpatient.rooms.daily_rate` adalah satu kolom tanpa `valid_from`/`valid_until`.
Akibatnya nyata dan sudah berlaku hari ini: **menaikkan tarif kamar mengubah nilai
rawat inap yang sedang berjalan dan yang sudah lewat**, karena tidak ada cara
mengetahui tarif yang berlaku bulan lalu. Rawat inap yang masuk sebelum kenaikan
akan ditagih dengan tarif setelah kenaikan untuk seluruh hari rawatnya — termasuk
hari-hari sebelum kenaikan itu diputuskan.

Bandingkan dengan `catalog.tariffs` yang sudah bitemporal sejak awal. Pembedaannya
tidak disengaja; tarif kamar hanya kebetulan dibangun lebih sederhana.

**Pertanyaan:** apakah RSP UI menaikkan tarif kamar dengan tanggal berlaku tertentu
(dan rawat inap berjalan tetap memakai tarif saat masuk), atau kenaikan berlaku
serta-merta untuk seluruh hari rawat yang sedang berjalan?

- Kalau **berperiode** — perlu tabel `room_rates` berperiode, dan `v_room_charge`
  yang dipakai billing harus ikut menyaring per tanggal. Pekerjaan menyentuh
  billing, jadi **Wave 2**, bukan sekarang.
- Kalau **serta-merta** — keadaan sekarang sudah benar, dan yang perlu ditambahkan
  hanya catatan agar tidak ada yang memperbaikinya belakangan tanpa tahu ini
  keputusan.

**Sementara menunggu jawaban:** `InpatientTariffResolver` sudah menerima parameter
`$tanggal` dan sengaja belum memakainya, supaya kontraknya tidak perlu berubah saat
jawabannya datang.

### Q15 — Catatan mahasiswa belum punya tempatnya sendiri

**Ditemukan 2026-09-14 saat membangun sub-tab DPJP/PPA/Student di layar RME.**

Layar pemeriksaan kini punya tiga sub-tab seperti yang diminta. Dua di antaranya
berpadanan wajar dengan `kind` yang sudah ada di `clinical.assessments`:

| Sub-tab | `kind` | Padanannya |
|---|---|---|
| DPJP | `soap-dokter` | tepat |
| PPA | `asesmen-awal-keperawatan` | tepat — perawat, gizi, fisioterapi |
| **Student** | `asesmen-lanjutan` | **tidak tepat** |

`clinical.assessments` dibatasi CHECK ke tiga nilai itu saja, dan tidak satu pun
berarti "catatan mahasiswa". Memakai `asesmen-lanjutan` berarti **catatan mahasiswa
dan asesmen lanjutan dokter tersimpan di baris yang sama** — dan itu keliru secara
rekam medis: catatan mahasiswa wajib dapat dibedakan dari catatan DPJP, dan pada
umumnya wajib diverifikasi DPJP sebelum dianggap sah.

Akibat bila dibiarkan: pada penelusuran audit, tidak ada cara membedakan mana yang
ditulis mahasiswa dan mana yang ditulis dokter penanggung jawab, kecuali menebak
dari `practitioner_name`.

**Pertanyaan:** apakah RSP UI memerlukan jenis catatan tersendiri untuk mahasiswa?
Bila ya:

- perlu nilai `kind` baru (mis. `catatan-mahasiswa`) — ini **migrasi yang mengubah
  CHECK pada tabel rekam medis**, jadi menunggu keputusan Anda, bukan saya;
- perlu diputuskan apakah catatan mahasiswa **wajib diverifikasi DPJP** sebelum
  masuk rekam medis resmi, dan siapa yang boleh memverifikasi;
- perlu hak akses tersendiri (sekarang sub-tab Student memakai
  `penilaian_awal_medis_ralan`, yaitu hak yang sama dengan DPJP — artinya
  **siapa pun yang bisa menulis sebagai DPJP juga bisa menulis sebagai Student**,
  dan sebaliknya).

**Sementara menunggu jawaban:** sub-tab Student tetap dibangun agar tata letaknya
sesuai permintaan, tetapi keterbatasan di atas tidak disembunyikan — dicatat di sini
dan di komentar `records/edit.blade.php`.

---

## D. Catatan Risiko yang Perlu Keputusan (bukan pertanyaan bisnis)

### R1 — Posting GL asinkron mengubah arti "laporan hari ini"

Setelah posting jadi asinkron, laporan keuangan akan **tertinggal beberapa detik
sampai menit** dari transaksi. Itu benar secara arsitektur, tapi perlu disepakati:
apakah manajemen menerima laporan yang tidak real-time, dengan penanda
"data sampai pukul HH:MM"?

### R2 — Menambahkan constraint balance pada jurnal

Saat ini **0 jurnal tidak balance**, jadi aman ditambahkan sekarang. Menundanya
sampai ada ribuan jurnal akan membuat migrasi berisiko gagal.
**Rekomendasi: kerjakan di Wave 1.**

### R3 — Konsolidasi 5 master supplier

Lima konteks punya master supplier sendiri dengan FK yang menunjuk ke sana.
Menyatukannya menuntut pemetaan identitas vendor (vendor yang sama bisa terdaftar
5 kali dengan nama berbeda). **Perlu pembersihan data manual**, bukan hanya migrasi.
