<?php

namespace App\Modules\Catalog\Services;

use RuntimeException;

/**
 * Kesalahan yang berasal dari aturan konteks catalog.
 *
 * Dibuat saat template formulir masuk (domain M item A): sebelum itu
 * catalog cukup memakai RuntimeException karena aturannya sedikit.
 * Sekarang aturannya punya pesan yang memang ditujukan kepada pengguna —
 * dan pesan seperti itu perlu bisa dibedakan dari galat pemrograman.
 */
class CatalogException extends RuntimeException
{
}
