<?php

namespace App\Modules\Integration\Services;

use RuntimeException;

/**
 * Galat yang bisa dimengerti pengguna dari konteks integrasi.
 *
 * MENG-EXTEND RuntimeException, bukan menggantikannya. Modul ini satu-
 * satunya yang belum punya exception sendiri dan sudah melempar
 * RuntimeException polos di beberapa tempat; membuat kelas yang tidak
 * berkerabat akan membuat penangkap lama diam-diam melewatkan galat baru.
 * Dengan meng-extend, kode lama tetap menangkapnya sementara kode baru
 * bisa membedakan galat integrasi dari galat sistem.
 */
class IntegrationException extends RuntimeException
{
}
