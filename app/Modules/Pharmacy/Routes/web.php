<?php

use App\Modules\Pharmacy\Http\Controllers\DonationReceiptController;
use App\Modules\Pharmacy\Http\Controllers\DrugRequisitionController;
use App\Modules\Pharmacy\Http\Controllers\ExternalPrescriptionController;
use App\Modules\Pharmacy\Http\Controllers\GoodsReceiptController;
use App\Modules\Pharmacy\Http\Controllers\MasterDataController;
use App\Modules\Pharmacy\Http\Controllers\PatientDrugReturnController;
use App\Modules\Pharmacy\Http\Controllers\PatientStockRequestController;
use App\Modules\Pharmacy\Http\Controllers\PharmacyRecapController;
use App\Modules\Pharmacy\Http\Controllers\PrescriptionController;
use App\Modules\Pharmacy\Http\Controllers\ProcedureBhpUsageController;
use App\Modules\Pharmacy\Http\Controllers\PurchaseOrderController;
use App\Modules\Pharmacy\Http\Controllers\RetailSaleController;
use App\Modules\Pharmacy\Http\Controllers\StockOpnameController;
use App\Modules\Pharmacy\Http\Controllers\StockReportController;
use App\Modules\Pharmacy\Http\Controllers\StockTransferController;
use App\Modules\Pharmacy\Http\Controllers\SupplierReturnController;
use App\Modules\Pharmacy\Http\Controllers\WardStockRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Domain D item 1 (master data) — obat digerbangi 'obat', menaungi 8 kode
    // master data farmasi lain sebagai satu layar gabungan (jenis_barang sudah
    // terpenuhi drugs.category, tidak ada di sini — lihat catatan migrasi).
    Route::middleware('can:obat')->prefix('farmasi/master')->name('pharmacy.master.')->group(function () {
        Route::get('/', [MasterDataController::class, 'index'])->name('index');
        Route::post('/obat', [MasterDataController::class, 'storeDrug'])->name('obat.simpan');
        Route::post('/kategori', [MasterDataController::class, 'storeDrugCategory'])->name('kategori.simpan');
        Route::post('/golongan', [MasterDataController::class, 'storeDrugClass'])->name('golongan.simpan');
        Route::post('/satuan', [MasterDataController::class, 'storeUnit'])->name('satuan.simpan');
        Route::post('/suplier', [MasterDataController::class, 'storeSupplier'])->name('suplier.simpan');
        Route::post('/industri', [MasterDataController::class, 'storeManufacturer'])->name('industri.simpan');
        Route::post('/metode-racik', [MasterDataController::class, 'storeCompoundingMethod'])->name('metode-racik.simpan');
        Route::post('/obat/{obat}/konversi', [MasterDataController::class, 'storeDrugUnit'])->name('konversi.simpan');
    });

    // Domain D item 2 — rantai pengadaan (pengajuan -> PO -> terima+bayar ->
    // verifikasi -> retur). Desain dikonfirmasi user, lihat catatan migrasi
    // 2026_09_27_000001_create_pharmacy_procurement_tables.
    Route::middleware('can:pengajuan_barang_medis')->prefix('farmasi/pengajuan')->name('pharmacy.pengajuan.')->group(function () {
        Route::get('/', [DrugRequisitionController::class, 'index'])->name('index');
        Route::post('/', [DrugRequisitionController::class, 'store'])->name('simpan');
        Route::post('/{pengajuan}/setuju', [DrugRequisitionController::class, 'approve'])->name('setuju');
        Route::post('/{pengajuan}/tolak', [DrugRequisitionController::class, 'reject'])->name('tolak');
    });

    Route::middleware('can:pengadaan_obat')->prefix('farmasi/po')->name('pharmacy.po.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/{po}', [PurchaseOrderController::class, 'show'])->name('show');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('simpan');
        Route::post('/{po}/kirim', [PurchaseOrderController::class, 'submit'])->name('kirim');
        Route::post('/{po}/batal', [PurchaseOrderController::class, 'cancel'])->name('batal');
    });

    // pemesanan_obat (Surat Pemesanan) — cetak dari data PO yang sama, gerbang terpisah.
    Route::middleware('can:pemesanan_obat')->get('/farmasi/po/{po}/cetak', [PurchaseOrderController::class, 'print'])->name('pharmacy.po.cetak');

    Route::middleware('can:bayar_pemesanan_obat')->prefix('farmasi/penerimaan')->name('pharmacy.penerimaan.')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
        Route::post('/po/{po}', [GoodsReceiptController::class, 'store'])->name('simpan');
    });

    Route::middleware('can:verifikasi_penerimaan_farmasi')->post('/farmasi/penerimaan/{penerimaan}/verifikasi', [GoodsReceiptController::class, 'verify'])->name('pharmacy.penerimaan.verifikasi');

    Route::middleware('can:retur_ke_suplier')->prefix('farmasi/retur')->name('pharmacy.retur.')->group(function () {
        Route::get('/', [SupplierReturnController::class, 'index'])->name('index');
        Route::post('/', [SupplierReturnController::class, 'store'])->name('simpan');
        Route::post('/{retur}/selesai', [SupplierReturnController::class, 'complete'])->name('selesai');
    });

    // Domain D item 3 — stok & batch ops. stok_opname_obat dan mutasi_barang
    // transaksi baru; ppn_obat jadi kolom di layar master item 1 (bukan di
    // sini). 12 kode laporan/sirkulasi digabung StockReportController,
    // digerbangi sisa_stok. Lihat catatan migrasi 2026_09_28_000001.
    Route::middleware('can:stok_opname_obat')->prefix('farmasi/opname')->name('pharmacy.opname.')->group(function () {
        Route::get('/', [StockOpnameController::class, 'index'])->name('index');
        Route::get('/{opname}', [StockOpnameController::class, 'show'])->name('show');
        Route::post('/', [StockOpnameController::class, 'store'])->name('simpan');
        Route::post('/{opname}/hitung', [StockOpnameController::class, 'recordCount'])->name('hitung');
        Route::post('/{opname}/selesai', [StockOpnameController::class, 'complete'])->name('selesai');
    });

    Route::middleware('can:mutasi_barang')->prefix('farmasi/mutasi')->name('pharmacy.mutasi.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::post('/', [StockTransferController::class, 'store'])->name('simpan');
    });

    Route::middleware('can:sisa_stok')->prefix('farmasi/laporan-stok')->name('pharmacy.laporan-stok.')->group(function () {
        Route::get('/', [StockReportController::class, 'index'])->name('index');
        Route::get('/batch/{batchId}/riwayat', [StockReportController::class, 'batchHistory'])->name('riwayat-batch');
    });

    // Domain D item 4 — permintaan farmasi ruangan/pasien. resep_dokter dan
    // permintaan_resep_pulang tidak di sini, sudah terpenuhi resep.index
    // (lihat catatan di PrescriptionController::index()). pengambilan_utd
    // dinaungi pengeluaran_stok_apotek, stok_obat_pasien dinaungi
    // permintaan_stok_obat_pasien — lihat catatan migrasi 2026_09_29_000001.
    Route::middleware('can:pengeluaran_stok_apotek')->prefix('farmasi/permintaan-ruangan')->name('pharmacy.permintaan-ruangan.')->group(function () {
        Route::get('/', [WardStockRequestController::class, 'index'])->name('index');
        Route::post('/', [WardStockRequestController::class, 'store'])->name('simpan');
        Route::post('/{permintaan}/tolak', [WardStockRequestController::class, 'reject'])->name('tolak');
        Route::post('/{permintaan}/keluarkan', [WardStockRequestController::class, 'issue'])->name('keluarkan');
    });

    Route::middleware('can:permintaan_stok_obat_pasien')->prefix('farmasi/permintaan-pasien')->name('pharmacy.permintaan-pasien.')->group(function () {
        Route::get('/', [PatientStockRequestController::class, 'index'])->name('index');
        Route::get('/cari-kunjungan', [PatientStockRequestController::class, 'searchRegistration'])->name('cari-kunjungan');
        Route::post('/', [PatientStockRequestController::class, 'store'])->name('simpan');
        Route::post('/{permintaan}/tolak', [PatientStockRequestController::class, 'reject'])->name('tolak');
        Route::post('/{permintaan}/keluarkan', [PatientStockRequestController::class, 'issue'])->name('keluarkan');
    });

    Route::middleware('can:resep_luar')->prefix('farmasi/resep-luar')->name('pharmacy.resep-luar.')->group(function () {
        Route::get('/', [ExternalPrescriptionController::class, 'index'])->name('index');
        Route::post('/', [ExternalPrescriptionController::class, 'store'])->name('simpan');
        Route::post('/{resep}/serahkan', [ExternalPrescriptionController::class, 'dispense'])->name('serahkan');
        Route::post('/{resep}/batal', [ExternalPrescriptionController::class, 'cancel'])->name('batal');
    });

    Route::middleware('can:penggunaan_bhp_ok')->prefix('farmasi/bhp-ok')->name('pharmacy.bhp-ok.')->group(function () {
        Route::get('/', [ProcedureBhpUsageController::class, 'index'])->name('index');
        Route::post('/', [ProcedureBhpUsageController::class, 'store'])->name('simpan');
    });

    // Domain D item 5 — retail, retur & untung. penjualan_obat menaungi
    // piutang_obat (dibedakan payment_status) + retur_dari_pembeli +
    // retur_piutang_pasien (satu mekanisme retur atas retail_sales yang
    // sama). asal_hibah menaungi ke hibah_obat_bhp. keuntungan_penjualan
    // menaungi 2 kode keuntungan_* lain + 6 kode ringkasan_*. Lihat
    // catatan migrasi 2026_09_30_000001.
    Route::middleware('can:penjualan_obat')->prefix('farmasi/penjualan')->name('pharmacy.penjualan.')->group(function () {
        Route::get('/', [RetailSaleController::class, 'index'])->name('index');
        Route::post('/', [RetailSaleController::class, 'store'])->name('simpan');
        Route::post('/{penjualan}/retur', [RetailSaleController::class, 'storeReturn'])->name('retur');
    });

    Route::middleware('can:retur_obat_ranap')->prefix('farmasi/retur-ranap')->name('pharmacy.retur-ranap.')->group(function () {
        Route::get('/', [PatientDrugReturnController::class, 'index'])->name('index');
        Route::get('/cari-kunjungan', [PatientDrugReturnController::class, 'searchRegistration'])->name('cari-kunjungan');
        Route::post('/', [PatientDrugReturnController::class, 'store'])->name('simpan');
    });

    Route::middleware('can:hibah_obat_bhp')->prefix('farmasi/hibah')->name('pharmacy.hibah.')->group(function () {
        Route::get('/', [DonationReceiptController::class, 'index'])->name('index');
        Route::post('/donor', [DonationReceiptController::class, 'storeDonor'])->name('donor.simpan');
        Route::post('/', [DonationReceiptController::class, 'store'])->name('simpan');
    });

    Route::middleware('can:keuntungan_penjualan')->get('/farmasi/rekap', [PharmacyRecapController::class, 'index'])->name('pharmacy.rekap.index');

    Route::prefix('resep')->name('resep.')->group(function () {
        // Antrean farmasi dan rincian resep: apoteker maupun dokter penulis.
        Route::get('/', [PrescriptionController::class, 'index'])->name('index');
        Route::get('/cari-obat', [PrescriptionController::class, 'searchDrugs'])->name('cari-obat');
        Route::get('/{resep}', [PrescriptionController::class, 'show'])->name('show');

        // Penyusunan resep oleh dokter.
        Route::middleware('can:resep_obat')->group(function () {
            Route::post('/kunjungan/{registrasi}', [PrescriptionController::class, 'createForRegistration'])->name('buat');
            Route::post('/{resep}/item', [PrescriptionController::class, 'storeItem'])->name('item.simpan');
            Route::delete('/item/{item}', [PrescriptionController::class, 'destroyItem'])->name('item.hapus');
            Route::post('/{resep}/kirim', [PrescriptionController::class, 'submit'])->name('kirim');
        });

        // Telaah dan penyerahan oleh apoteker.
        Route::post('/{resep}/telaah', [PrescriptionController::class, 'review'])
            ->name('telaah')->middleware('can:telaah_resep');
        Route::post('/{resep}/serah', [PrescriptionController::class, 'dispense'])
            ->name('serah')->middleware('can:beri_obat');

        Route::post('/{resep}/batal', [PrescriptionController::class, 'cancel'])->name('batal');
    });
});
