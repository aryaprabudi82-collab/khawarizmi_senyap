<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    #[Test]
    public function halaman_depan_mengarahkan_tamu_ke_halaman_masuk(): void
    {
        // Tidak ada halaman publik: seluruh isi SIMRS berada di balik autentikasi.
        $this->get('/')->assertRedirect(route('masuk'));
    }

    #[Test]
    public function endpoint_kesehatan_aplikasi_menyala(): void
    {
        $this->get('/up')->assertOk();
    }
}
