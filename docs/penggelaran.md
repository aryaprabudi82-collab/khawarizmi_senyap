# Penggelaran SIMRS RSP UI

Ditulis untuk beban nyata: **2.000 pasien sehari dan terus bertambah** —
sekitar 600.000 kunjungan setahun.

Dokumen ini pendamping, **bukan pengganti**, pemeriksaan otomatis. Yang
memasang server dua tahun lagi bukan orang yang membaca dokumen hari ini,
jadi setiap syarat penting di bawah juga dideteksi sendiri oleh
`php artisan siap:periksa`. Kalau dokumen ini dan perintah itu berbeda,
percayai perintahnya.

---

## 1. Satu baris cron yang menahan segalanya

```
* * * * * cd /path/simrs && php artisan schedule:run >> /dev/null 2>&1
```

**Ini ketergantungan paling senyap di seluruh sistem.** Kalau baris ini
tidak dipasang — atau hilang saat server dipindah, atau mati karena
penggunanya tidak bisa menjalankan artisan — **tidak ada galat apa pun yang
muncul.** Aplikasi tetap melayani pasien seperti biasa. Yang berhenti cuma
perawatannya.

Akibatnya baru terasa berbulan-bulan kemudian: partisi bulanan tidak pernah
diperpanjang, seluruh baris baru jatuh ke partisi `DEFAULT`, dan sistem
pelan-pelan melambat tanpa sebab yang bisa ditunjuk siapa pun.

Lebih buruk lagi, kerusakannya **mahal diperbaiki setelah terjadi**: begitu
ada baris di `DEFAULT` untuk suatu bulan, PostgreSQL menolak pembuatan
partisi bulan itu. Perbaikannya menuntut melepas `DEFAULT`, memindahkan
barisnya, lalu memasang ulang — sambil mengunci tabel tersibuk di rumah
sakit.

Karena itu ketiadaannya dideteksi dalam **dua hari**, bukan dua tahun:

```
php artisan siap:periksa
```

melaporkan "Perawatan terjadwal (cron)" sebagai penghalang bila belum
pernah berjalan, dan membedakannya dari yang pernah berjalan lalu berhenti
— dua keadaan yang menuntut tindakan berbeda.

---

## 2. Dua server: aplikasi dan basis data terpisah

Sudah didukung tanpa perubahan kode. Yang perlu diperhatikan:

| Setelan | Nilai | Alasan |
|---|---|---|
| `DB_HOST` | alamat server basis data | Satu-satunya yang perlu diubah |
| `SESSION_DRIVER` | `database` | Sesi harus dibaca dari mesin mana pun |
| `CACHE_STORE` | `database` | Sama |
| `QUEUE_CONNECTION` | `database` | Sama |

Ketiganya **sudah** berdriver `database` di `.env.example`. Jangan
kembalikan ke `file`: sesi berbasis berkas hanya ada di mesin yang
menulisnya, jadi pengguna akan tampak keluar-masuk sendiri saat
permintaannya jatuh ke server yang berbeda.

Sejak aplikasi dan basis data terpisah, **tiap perjalanan kueri menanggung
latensi jaringan**. Itu sebabnya N+1 diperlakukan serius di sini dan dijaga
`ListScreenQueryCountTest` — lima puluh kueri tambahan yang di satu mesin
cuma beberapa milidetik bisa jadi setengah detik di dua mesin.

---

## 3. Setelan produksi

```
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` di produksi menampilkan jejak tumpukan berikut isi
variabel kepada siapa pun yang memicu galat — termasuk data pasien dan
kredensial basis data. `siap:periksa` menolaknya sebagai penghalang, tapi
hanya saat `APP_ENV=production`; di lingkungan pengembangan debug memang
harus menyala.

Pastikan `APP_KEY` sudah dibuat (`php artisan key:generate`). Tanpa itu
sesi dan data terenkripsi tidak bisa dibaca.

---

## 4. Urutan pemasangan

```bash
php artisan migrate --force
php artisan db:seed --force          # katalog permission, peran, data referensi
php artisan key:generate             # bila belum ada APP_KEY
php artisan partisi:pastikan         # sekaligus menandai cron sudah pernah jalan
php artisan siap:periksa             # WAJIB dibaca sebelum membuka layanan
```

`siap:periksa` keluar dengan kode gagal bila ada **penghalang**, dan lolos
bila hanya ada **peringatan**. Peringatan sengaja tidak menahan penggelaran
— menahan pelayanan demi kerapian daftar bukan pertukaran yang benar.

---

## 5. Yang harus diputuskan RSP UI sebelum operasional

Beberapa daftar **sengaja lahir kosong**. Isinya diskresi RSP UI, dan
menebaknya berarti menerbitkan angka resmi yang tidak pernah disepakati
siapa pun lalu memakainya menagih atau menolak orang.

Yang **menghalangi** operasi:

- **Shift kasir** — tanpa ini penutupan shift tidak bisa dijalankan sama
  sekali, jadi tidak ada yang mencocokkan uang laci dengan pembayaran
  tercatat.
- **Identitas rumah sakit** — nama dan kode fasilitas tidak muncul di satu
  pun kuitansi, surat keterangan, atau berkas yang dikirim ke sistem luar.

Yang **perlu diputuskan** tapi tidak menahan pembukaan layanan: ruang
operasi, waktu makan pasien, area & butir risiko ICRA, kriteria kelayakan
ZIS, dan sebelas pengaturan aplikasi — delapan di antaranya menyentuh
tagihan (embalase, tuslah, dasar harga obat, PPN).

**Kosong bukan nol.** Selama tarif embalase belum ditetapkan, obat tertagih
tanpa embalase — diam-diam, setiap hari, tanpa satu pun galat. Itu
disengaja: nol yang ditebak sistem akan terbaca wajar dan rumah sakit
kehilangan pendapatan tanpa ada yang menyadari.

Daftar lengkapnya berikut akibat masing-masing: `php artisan siap:periksa`.

---

## 6. Pemantauan berjalan

Dua perintah keluar dengan kode gagal supaya bisa dipasang sebagai
pemeriksaan pemantauan — yang membacanya mesin, dan mesin cuma mengerti
kode keluar:

```bash
php artisan partisi:periksa    # runway partisi & isi partisi DEFAULT
php artisan siap:periksa       # kesiapan operasional menyeluruh
```

Keduanya aman dijalankan sesering apa pun; keduanya hanya membaca.
