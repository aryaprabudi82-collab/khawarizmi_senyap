<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * barcoderalan/barcoderanap (Khanza domain B "Barcode & Lab Kesling") —
 * tercatat context=envlab di katalog (ikut tertarik penamaan menu "Barcode
 * & Lab Kesling"), tapi fungsinya cetak label barcode untuk KUNJUNGAN
 * pasien (dipasang di gelang/spesimen), genuinely encounter — envlab
 * nantinya khusus sampel lingkungan/K3, tidak ada hubungannya dengan
 * kunjungan pasien. Permission-nya berbeda per jenis rawat (barcoderalan
 * untuk ralan/igd, barcoderanap untuk ranap), tidak bisa digerbangi lewat
 * middleware 'can:' statis karena baru diketahui dari data registrasi saat
 * runtime — pola sama dengan OrderController::assertAccess().
 */
class BarcodeController
{
    public function print(Request $request, Registration $registrasi): View
    {
        $permission = $registrasi->care_type === 'ranap' ? 'barcoderanap' : 'barcoderalan';

        abort_unless($request->user()?->can($permission) === true, 403);

        return view('encounter::barcode.cetak', ['kunjungan' => $registrasi]);
    }
}
