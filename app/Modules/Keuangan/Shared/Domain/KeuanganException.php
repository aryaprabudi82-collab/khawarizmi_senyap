<?php

namespace App\Modules\Keuangan\Shared\Domain;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis domain keuangan.
 *
 * Dipakai SELURUH sub-konteks keuangan, bukan satu per modul. Pesannya
 * ditulis untuk dibaca PETUGAS, bukan pengembang: ia muncul di layar, dan
 * galat yang hanya menyatakan sesuatu gagal tanpa menyebut apa yang harus
 * dilakukan membuat orang mencoba hal yang sama berulang-ulang.
 */
class KeuanganException extends RuntimeException {}
