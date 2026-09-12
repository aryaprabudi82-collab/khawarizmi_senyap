# Keputusan Arsitektur Domain Keuangan

**Tanggal:** 2026-09-12
**Dasar kewenangan:** pengguna menyerahkan Q1, Q2, dan Q4 kepada penilaian saya,
setelah Q3 dijawab (RSP UI berstatus **PTN-BH**).

Dokumen ini mencatat keputusan yang **mengikat seluruh wave berikutnya**. Setiap
keputusan menyebut alasannya dan apa yang dikorbankan — supaya penerus bisa menilai
ulang dengan bahan yang sama, bukan hanya menerima atau menolak.

---

## KA-1 — Presisi nilai uang (menjawab Q1)

**Keputusan: dua skala, dengan aturan batas yang tegas.**

| Jenis nilai | Tipe | Contoh |
|---|---|---|
| Nilai yang **ditagihkan, dibayarkan, dijurnalkan** | `DECIMAL(19,2)` | `charge_lines.amount`, `payments.amount`, `journal_lines.debit` |
| Nilai **hasil alokasi/pembagian** yang belum jadi tagihan | `DECIMAL(19,4)` | alokasi ABC, pembagian jaspel, selisih kelas per hari, harga pokok rata-rata |

**Aturan yang mengikat:** nilai berskala 4 **dibulatkan ke skala 2 tepat pada saat ia
menjadi nilai yang ditagihkan atau dijurnalkan** — tidak lebih awal, tidak lebih akhir.
Pembulatan memakai *round-half-up*, dan **selisih pembulatan pada pembagian
dibebankan ke baris terakhir** supaya jumlah bagian selalu sama persis dengan nilai
yang dibagi.

### Alasan

Instruksi menyebut `DECIMAL(19,4)`. Skema yang ada memakai skala 2 pada 191 kolom.
Migrasi buta ke skala 4 menyentuh hampir seluruh tabel dan seluruh uji yang
membandingkan string `'250000.00'` — biaya besar, manfaat nol untuk nilai yang memang
tidak pernah pecahan sen.

Tetapi skala 2 **tidak memadai** pada hasil antara. Membagi jasa dokter Rp 1.000.000
ke tiga pihak dengan pembulatan tiap langkah menghasilkan Rp 999.999 atau
Rp 1.000.002 — dan selisih itu menumpuk ribuan kali per bulan sampai jadi angka yang
harus dijelaskan seseorang.

### Yang dikorbankan

Dua standar hidup berdampingan, dan itu **butuh kedisiplinan**. Karena itu batasnya
dibuat tegas dan mudah diperiksa: *kalau kolomnya bisa muncul di kuitansi atau di
jurnal, skalanya 2; kalau ia hanya perhitungan antara, skalanya 4.*

### Catatan tentang lebar

Lebar dinaikkan dari 14 ke **19** untuk kolom transaksi baru. `DECIMAL(14,2)` memuat
maksimum Rp 999.999.999.999,99 — cukup untuk satu transaksi, **tidak cukup untuk
saldo akumulatif** akun pendapatan rumah sakit setelah beberapa tahun. Kolom lama
tidak dimigrasikan sekarang; itu pekerjaan Wave 4 saat GL dipindahkan, dan dicatat
di `MIGRATION-MAP.md`.

---

## KA-2 — Bentuk domain `keuangan` (menjawab Q2)

**Keputusan: `keuangan` adalah DOMAIN berisi beberapa bounded context, bukan satu
konteks raksasa.**

Bentuknya:

```
app/Modules/Keuangan/            <- domain
├── Shared/                      <- value object, contract, event (tanpa skema)
├── MasterData/                  <- skema: keuangan_master
├── Billing/                     <- skema: keuangan_billing   (Wave 2)
├── Kasir/                       <- skema: keuangan_kasir     (Wave 2)
├── Klaim/                       <- skema: keuangan_klaim     (Wave 3)
├── GeneralLedger/               <- skema: keuangan_gl        (Wave 4)
├── PostingEngine/               <- skema: keuangan_posting   (Wave 4)
└── ...
```

Tiap sub-konteks punya `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Jobs/`,
`Tests/`, `README.md` — persis struktur yang diminta BAGIAN 5.

### Alasan

Instruksi BAGIAN 5 sendiri menggambarkan struktur **per modul** dengan
`Domain/Application/Infrastructure` masing-masing dan komunikasi lewat contract atau
domain event. Itu adalah beberapa konteks di bawah satu domain — bukan satu skema
dengan seratus tabel.

Yang lebih menentukan: sistem ini sudah menegakkan batas konteks lewat
`ContextBoundaryTest`, dan penjagaan itu **sudah menangkap kesalahan nyata** (laporan
yang membaca langsung tabel `clinical`, aturan `unique:` yang salah menunjuk skema).
Menggabungkan seluruh keuangan jadi satu skema berarti membuang penjagaan itu justru
di domain yang paling tidak boleh salah.

### Yang dikorbankan

Komunikasi antar sub-konteks keuangan harus lewat kontrak view atau interface — tidak
bisa `JOIN` langsung. Itu menambah kerja, dan sengaja: *JOIN* langsung antara billing
dan GL adalah persis cara sebuah posting engine berubah pelan-pelan jadi kueri raksasa
yang tidak ada yang berani sentuh.

### Aturan batas di dalam domain keuangan

1. Sub-konteks keuangan **boleh** membaca view terbitan sesama sub-konteks keuangan.
2. Sub-konteks keuangan **tidak boleh** membaca tabel sesama sub-konteks keuangan.
3. Konteks di luar keuangan **hanya** boleh membaca view terbitan keuangan.
4. `Keuangan\Shared` tidak punya skema dan tidak boleh menyentuh basis data.

`ContextBoundaryTest` diperluas untuk menegakkan keempatnya.

---

## KA-3 — Bagan akun (menjawab Q4)

**Keputusan: bangun STRUKTUR bagan akun berdimensi lengkap sekarang; ISINYA diisi
bagian keuangan RSP UI kemudian.**

Yang saya bangun:

- Hierarki akun (`parent_id`), sehingga akun induk menjumlahkan anaknya.
- Klasifikasi PSAK: aset lancar / aset tidak lancar / liabilitas jangka pendek /
  liabilitas jangka panjang / ekuitas / pendapatan / beban.
- **Enam dimensi** pada baris jurnal: `cost_center`, `unit_id`, `sumber_dana`,
  `proyek`, `praktisi_id` (DPJP), `program`.
- `program` bernilai **pelayanan / pendidikan / penelitian** — inilah yang membuat
  RSP UI bisa melaporkan dana pendidikan terpisah tanpa menggandakan bagan akun.
- Bagan akun **berversi** (`valid_from`/`valid_to`), akun dinonaktifkan bukan dihapus.

Yang **tidak** saya isi:

- Nomor akun dan namanya. Empat akun contoh yang ada sekarang tetap ditandai sebagai
  contoh pengembangan, dan `siap:periksa` terus melaporkannya sebagai penghalang
  sampai bagan akun sungguhan masuk.

### Alasan

Struktur adalah keputusan arsitektur; isi adalah keputusan kebijakan. Menebak nomor
akun berarti mengarang bagan akun sebuah rumah sakit — dan bagan akun yang salah
tidak menghasilkan galat, melainkan laporan yang rapi dan keliru.

Menunda **strukturnya** juga tidak masuk akal: seluruh Wave 1 sampai 7 bergantung
pada bentuk COA, dan menunggu daftar akun berarti tidak ada yang bisa dikerjakan.

### Konsekuensi yang harus dinyatakan

Sampai bagan akun sungguhan masuk, **laporan keuangan tidak bisa dipakai menutup
buku**. Layar Pusat Keuangan sudah menyatakan itu di bagian paling atas, dan
pernyataan itu tidak boleh dihapus sampai syaratnya benar-benar terpenuhi.

---

## KA-4 — Dimensi disimpan di baris jurnal, bukan di akun

**Keputusan:** dimensi (`cost_center`, `unit`, `sumber_dana`, `proyek`, `praktisi`,
`program`) adalah kolom pada **`journal_lines`**, bukan bagian dari kode akun.

### Alasan

Cara lain yang lazim adalah memasukkan dimensi ke dalam nomor akun —
`4-1000-RJ-PELAYANAN-DR001`. Itu terlihat rapi dan **selalu berakhir sama**: jumlah
akun meledak jadi puluhan ribu, menambah satu dimensi berarti menomori ulang seluruh
bagan akun, dan laporan per dimensi menjadi latihan mengurai string.

Dimensi sebagai kolom membuat satu akun pendapatan bisa dilaporkan per unit, per
dokter, per program, dan per sumber dana **sekaligus** — tanpa satu pun akun tambahan.

### Yang dikorbankan

Baris jurnal jadi lebih lebar, dan laporan per dimensi menuntut indeks yang tepat.
Itu harga yang wajar, dan sudah diantisipasi: `journal_lines` masuk daftar tabel yang
dipartisi.

---

## KA-5 — Yang TIDAK dipindahkan ke domain keuangan

Ditegaskan ulang di sini karena ia keputusan, bukan kelalaian. Rinciannya di
`MIGRATION-MAP.md` bagian *Yang TIDAK Dipindahkan*.

Ringkasnya: **snapshot nilai yang dibekukan pada transaksi klinis tetap di sana.**
`clinical.procedures` menyimpan enam komponen jasa medis yang dibekukan saat tindakan
dicatat. Itu **bukti apa yang terjadi pada pasien**, bukan perhitungan keuangan.
Keuangan membacanya lewat kontrak view.

Memindahkannya akan membuat rekam medis kehilangan keterangan tentang tindakannya
sendiri, dan membuat keuangan menyimpan data klinis yang tidak berhak disimpannya.

---

## Ringkasan keputusan

| Kode | Pertanyaan | Keputusan |
|---|---|---|
| KA-1 | Q1 — presisi uang | Skala 2 untuk nilai tertagih/terjurnal; skala 4 untuk hasil alokasi. Lebar 19 untuk kolom baru |
| KA-2 | Q2 — bentuk domain | Domain berisi beberapa bounded context, batas tetap ditegakkan uji |
| KA-3 | Q4 — bagan akun | Struktur dibangun sekarang, isi menyusul; `siap:periksa` tetap memblokir |
| KA-4 | — | Dimensi di baris jurnal, bukan di kode akun |
| KA-5 | — | Snapshot nilai pada transaksi klinis tidak dipindahkan |

---

## KA-6 — `is_active` dan `valid_until` menjawab pertanyaan berbeda

**Keputusan:** pada seluruh master berperiode di domain keuangan,

| Kolom | Menjawab |
|---|---|
| `is_active` | boleh dipakai **menagih sekarang**? |
| `valid_from` / `valid_until` | **sampai kapan** ia pernah berlaku? |

Yang menentukan apakah sebuah item berlaku pada satu tanggal adalah **rentang
tanggalnya**, bukan penanda aktifnya.

### Alasan — cacat nyata yang ditemukan pengujian

Versi pertama `ChargeMasterService::expire()` menyetel `is_active = false` bersamaan
dengan mengisi `valid_until`. Akibatnya item **hilang dari seluruh tanggal** —
termasuk masa ketika ia masih sah berlaku.

Yang rusak karenanya: rekap tarif bulan lalu kehilangan item yang waktu itu
benar-benar dipakai menagih, **tanpa satu pun tanda bahwa ada yang hilang**. Totalnya
tetap tampak wajar, hanya rinciannya berkurang.

`is_active` dipakai untuk menonaktifkan **sementara** — item yang salah harga dan
perlu ditahan sampai diperbaiki, tanpa mengubah riwayatnya.

---

## KA-7 — Komentar "sementara" tidak membuat pelanggaran batas jadi sah

**Keputusan:** modul keuangan **tidak boleh** membaca tabel konteks lain, bahkan
dengan catatan "akan diganti kontrak view nanti". Kalau butuh data konteks lain,
terbitkan kontraknya **sekarang**.

### Alasan

`ChargeMasterService` versi pertama membaca `finance.chart_of_accounts` langsung,
dengan komentar *"akan diganti kontrak view saat COA dipindahkan"*. Uji batas konteks
menolaknya, dan penolakan itu benar: **yang berlaku bukan maksud yang tertulis di
komentar, melainkan apa yang sungguh dikerjakan kodenya.**

Komentar "sementara" yang tidak punya tenggat adalah cara paling sopan membuat
pelanggaran jadi permanen. Pola yang sama sudah menghasilkan dua lubang keamanan
nyata di proyek ini — berkas rute yang menyatakan maksudnya dalam komentar lalu tidak
memasang gerbang apa pun.

Kontrak `finance.v_account` diterbitkan sebagai gantinya, dan **saldo sengaja tidak
ikut**: saldo adalah hasil hitungan atas jurnal, bukan atribut akun. Menerbitkannya
akan mengundang konteks lain menghitung saldo sendiri-sendiri, lalu buku besar punya
dua sumber kebenaran.

### Akibat operasional

Migrasi yang menyentuh skema konteks lain **tinggal di modul pemilik skemanya**, bukan
di modul yang membutuhkannya. Migrasi COA hierarkis karena itu berada di
`app/Modules/Finance/Database/Migrations/`, meski isinya pekerjaan Modul A —
pemindahan COA ke `keuangan_master` adalah pemindahan tersendiri di Wave 4.
