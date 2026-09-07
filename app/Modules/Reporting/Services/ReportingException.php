<?php

namespace App\Modules\Reporting\Services;

use RuntimeException;

/**
 * Kesalahan yang bisa dijelaskan kepada pemakainya.
 *
 * Konteks reporting sebelumnya tidak punya exception sendiri karena
 * layanannya cuma membaca dan menjumlah. Sejak domain O item A ia
 * menerima parameter dari luar — dataset, sumbu, satuan waktu — dan
 * parameter yang tidak dikenal harus DITOLAK dengan pesan yang
 * menyebutkan pilihannya, bukan menghasilkan grafik kosong yang
 * terbaca sebagai "tidak ada kejadian".
 */
class ReportingException extends RuntimeException {}
