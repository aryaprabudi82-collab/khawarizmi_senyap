<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Perawatan terjadwal
|--------------------------------------------------------------------------
|
| PENTING UNTUK PENGGELARAN: jadwal ini hanya berjalan kalau ada satu cron
| yang memanggil `php artisan schedule:run` setiap menit. Tanpa itu, seluruh
| blok di bawah tidak pernah dieksekusi dan tidak ada galat apa pun yang
| muncul — sistem cuma diam-diam berhenti dirawat.
|
|   * * * * * cd /path/simrs && php artisan schedule:run >> /dev/null 2>&1
|
*/

/*
 * Partisi bulanan, dibuat jauh sebelum dibutuhkan.
 *
 * Empat tabel terbesar sistem ini dipartisi per bulan, dan partisinya
 * dibuat sekali saat migrasi sebanyak 24 bulan. Pada RSP UI dengan 2.000
 * pasien sehari, keempatnya tumbuh jutaan baris per tahun — dan begitu
 * bulan ke-25 tiba tanpa ada yang menambah partisi, seluruh baris baru
 * jatuh ke partisi DEFAULT. Yang terjadi bukan galat, melainkan sistem yang
 * pelan-pelan melambat tanpa ada yang tahu sebabnya.
 *
 * Dijalankan HARIAN, bukan bulanan: perintahnya idempoten dan nyaris tanpa
 * biaya kalau tidak ada yang perlu dibuat, sementara jadwal bulanan yang
 * kebetulan gagal sekali berarti menunggu sebulan penuh untuk percobaan
 * berikutnya.
 */
Schedule::command('partisi:pastikan')
    ->dailyAt('01:10')
    ->onOneServer()
    ->withoutOverlapping();

/*
 * Pemeriksaan kesehatannya sendiri, terpisah dari perawatannya.
 *
 * `partisi:pastikan` bisa berhasil sambil menyisakan keadaan yang tidak
 * sehat — misalnya ada baris yang sudah telanjur masuk DEFAULT, yang tidak
 * bisa diperbaiki perintah itu. Pemeriksaan yang terpisah keluar dengan
 * kode 1 supaya pemantauan menangkapnya.
 */
Schedule::command('partisi:periksa')
    ->dailyAt('01:20')
    ->onOneServer();

/*
| Membersihkan catatan idempotensi keuangan yang kedaluwarsa.
|
| Catatan penahan itu pelindung terhadap pengiriman ulang yang terjadi
| dalam hitungan detik sampai jam — bukan jejak audit; jejaknya ada di
| platform.audit_logs. Tanpa pembersihan, tabelnya tumbuh sebesar tabel
| transaksinya sendiri tanpa menambah satu pun perlindungan.
|
| Dijadwalkan pada jam sepi, setelah perawatan partisi selesai.
*/
Schedule::command('keuangan:bersihkan-idempotensi')
    ->dailyAt('01:30')
    ->onOneServer()
    ->withoutOverlapping();
