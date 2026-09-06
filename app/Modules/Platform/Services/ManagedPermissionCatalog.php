<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Permission;

/**
 * Permission yang benar-benar dipakai layar "Kelola Peran", dikelompokkan per modul.
 *
 * Katalog Khanza berisi 1.183 kode permission, tapi sebagian besar belum punya
 * layar sungguhan — mencentang salah satunya tidak membuka akses ke apa pun.
 * Daftar di bawah dipersempit lewat sapuan langsung ke seluruh route (setiap
 * middleware `can:`, route fluent `->can()`, dan pemeriksaan imperatif
 * `$request->user()->can()`) sehingga peran custom yang dibuat lewat layar ini
 * hanya bisa mencentang kapabilitas yang sungguh menggerbangi sesuatu.
 *
 * Sebelas kode (audit_kepatuhan_apd, peristiwa_k3rs, jenis_cidera_k3rstahun,
 * insiden_keselamatan_pasien, tindakan_ranap, diet_pasien, deposit_pasien,
 * perkiraan_biaya_ranap, operasi, barcoderalan, barcoderanap) masih tercatat
 * context=hr/clinical/encounter/envlab di platform.permissions — peninggalan salah-taut domain huruf Khanza
 * (lihat catatan di database/data/roles.json). Layarnya sendiri sudah
 * dibangun di konteks quality/inpatient/finance/clinical/encounter, jadi
 * dikelompokkan ke situ di sini supaya admin tidak salah kira sedang memberi
 * akses HR/klinis/pendaftaran/lab lingkungan.
 */
class ManagedPermissionCatalog
{
    /**
     * Kode permission yang menggerbangi route sungguhan, per 2026-09-03.
     * Menambah baru di sini berarti sudah ada layar untuk kode tersebut.
     */
    private const MANAGED_CODES = [
        'audit_kepatuhan_apd', 'barcoderalan', 'barcoderanap', 'bayar_pemesanan_obat', 'bayar_piutang', 'beri_obat', 'booking_mcu_perusahaan', 'booking_operasi', 'bpjs_cek_kartu', 'bpjs_sep', 'dapur_barang', 'dapur_opname', 'dapur_pemesanan', 'dapur_pembelian', 'dapur_returbeli', 'dapur_riwayat_barang', 'deposit_pasien', 'diet_pasien', 'hibah_dapur',
        'igd', 'insiden_keselamatan_pasien', 'inventaris_inventaris', 'inventaris_sirkulasi', 'ipsrs_barang', 'ipsrs_pengadaan_barang', 'ipsrs_rekap_pengadaan', 'ipsrs_returbeli', 'ipsrs_riwayat_barang', 'jadwal_pegawai', 'jenis_cidera_k3rstahun', 'layanan_program_kfr', 'limbah_b3_medis',
        'hibah_aset_inventaris', 'hibah_non_medis', 'hibah_obat_bhp', 'keuntungan_penjualan', 'mapping_poli_bpjs', 'mutasi_barang', 'obat', 'operasi', 'pasien', 'pcra_icra_pengkajian_risiko_prakonstruksi', 'pegawai_user',
        'pelanggan_lab_kesehatan_lingkungan', 'pemesanan_obat', 'penugasan_pengujian_sampel_lab_kesehatan_lingkungan',
        'permintaan_pengujian_sampel_lab_kesehatan_lingkungan', 'hasil_pengujian_sampel_lab_kesehatan_lingkungan',
        'verifikasi_pengujian_sampel_lab_kesehatan_lingkungan', 'validasi_pengujian_sampel_lab_kesehatan_lingkungan',
        'pembayaran_pengujian_sampel_lab_kesehatan_lingkungan', 'rekap_pelayanan_lab_kesehatan_lingkungan',
        'parkir_in', 'parkir_jenis', 'pembayaran_ralan', 'pembayaran_ranap', 'potongan_biaya', 'pemeliharaan_inventaris', 'pemeriksaan_lab_pa', 'penerimaan_aset_inventaris', 'penerimaan_non_medis', 'penjualan_obat', 'pengadaan_aset_inventaris', 'pengadaan_obat', 'pengajuan_asetinventaris', 'pengajuan_barang_dapur', 'pengajuan_barang_medis', 'pengajuan_barang_nonmedis', 'pengajuan_cuti', 'pengeluaran_stok_apotek', 'hutang_obat', 'validasi_tagihan_hutang_obat', 'pengeluaran', 'pendapatan_per_akun', 'pendapatan_per_akun_closing', 'pengumuman_epasien', 'penyakit_ralan', 'piutang_pasien',
        'penggunaan_bhp_ok', 'penilaian_awal_medis_ralan', 'perbaikan_inventaris', 'periksa_lab', 'periksa_radiologi',
        'peristiwa_k3rs', 'perkiraan_biaya_ranap', 'permintaan_stok_obat_pasien', 'persetujuan_penolakan_tindakan', 'presensi_harian', 'registrasi',
        'harian_HAIs', 'lama_pelayanan_pasien', 'rekap_lab_pertahun', 'rekap_jm_dokter', 'rl4a', 'rekap_kunjungan', 'rekap_pembayaran_ralan', 'rekap_obat_pasien', 'rekap_pengadaan_dapur', 'resep_luar', 'resep_obat', 'retur_ke_suplier', 'retur_obat_ranap', 'ringkasan_tindakan', 'rujukan_keluar', 'satu_sehat_kirim_condition', 'satu_sehat_kirim_encounter',
        'satu_sehat_mapping_lokasi', 'satu_sehat_referensi_dokter', 'satu_sehat_referensi_pasien',
        'permintaan_ranap', 'sirkulasi_cssd', 'sensus_harian_ralan', 'sisa_stok', 'skp_penilaian', 'stok_opname_logistik', 'stok_opname_obat', 'suplier_inventaris', 'surat_keterangan_sehat', 'surat_masuk', 'surat_pemesanan_dapur', 'surat_pemesanan_non_medis',
        'tambahan_biaya', 'tarif_ralan', 'telaah_resep', 'tindakan_ranap', 'utd_cekal_darah', 'utd_pemisahan_darah', 'utd_pendonor', 'utd_penyerahan_darah', 'utd_stok_darah',
        'user', 'verifikasi_penerimaan_dapur', 'verifikasi_penerimaan_farmasi', 'verifikasi_penerimaan_logistik',
    ];

    /** Koreksi context Khanza yang salah-taut, lihat catatan kelas. */
    private const CONTEXT_OVERRIDE = [
        'audit_kepatuhan_apd' => 'quality',
        'peristiwa_k3rs' => 'quality',
        'jenis_cidera_k3rstahun' => 'quality',
        'insiden_keselamatan_pasien' => 'quality',
        // tindakan_ranap dan diet_pasien tercatat context=encounter di katalog
        // (domain A Khanza mencampur registrasi dengan tindakan ranap/diet) —
        // layarnya (kamar/bed/admisi/order diet) sungguhan dibangun di konteks
        // inpatient yang baru.
        'tindakan_ranap' => 'inpatient',
        'diet_pasien' => 'inpatient',
        // deposit_pasien dan perkiraan_biaya_ranap tercatat context=encounter
        // di katalog (domain A Khanza) tapi kelasnya berpaket Java "keuangan"
        // (lihat Khanza_Functional_Dependency_Map.xlsx) — layarnya sungguhan
        // dibangun di konteks finance.
        'deposit_pasien' => 'finance',
        'perkiraan_biaya_ranap' => 'finance',
        // operasi tercatat context=encounter di katalog, tanpa penanda paket
        // Java lain — tetap direlokasi ke clinical dengan alasan sama persis
        // dengan tindakan_ralan (lihat migrasi clinical.operations): prosedur
        // yang dilakukan ke pasien adalah rekam medis.
        'operasi' => 'clinical',
        // barcoderalan/barcoderanap tercatat context=envlab di katalog (ikut
        // penamaan menu domain B "Barcode & Lab Kesling"), tapi fungsinya
        // cetak label barcode kunjungan pasien — genuinely encounter, tidak
        // ada hubungan dengan sampel lingkungan/K3 yang akan menghuni envlab.
        'barcoderalan' => 'encounter',
        'barcoderanap' => 'encounter',
    ];

    private const MODULE_LABELS = [
        'encounter' => 'Pendaftaran & Rawat Jalan',
        'inpatient' => 'Rawat Inap',
        'clinical' => 'Rekam Medis Klinis',
        'pharmacy' => 'Farmasi',
        'billing' => 'Billing Rawat Jalan',
        'finance' => 'Keuangan',
        'inventory' => 'Logistik & Barang Non-Medis',
        'kitchen' => 'Dapur & Gizi',
        'asset' => 'Aset, CSSD & Kesehatan Lingkungan',
        'blood' => 'Unit Transfusi Darah',
        'hr' => 'Kepegawaian',
        'quality' => 'Mutu & Keselamatan Pasien (PMKP)',
        'correspondence' => 'Korespondensi & Dokumen Klinis',
        'integration' => 'Integrasi BPJS & SATUSEHAT',
        'reporting' => 'Pelaporan',
        'platform' => 'Pengaturan Aplikasi',
    ];

    /**
     * @return list<array{context: string, label: string, permissions: \Illuminate\Support\Collection}>
     *         Diurutkan sesuai MODULE_LABELS supaya tampil dalam urutan alur kerja RS,
     *         bukan abjad — pendaftaran dulu baru penunjang, baru administrasi.
     */
    public function grouped(): array
    {
        $permissions = Permission::query()
            ->whereIn('code', self::MANAGED_CODES)
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) {
                $permission->setAttribute(
                    'effective_context',
                    self::CONTEXT_OVERRIDE[$permission->code] ?? $permission->context
                );

                return $permission;
            })
            ->groupBy('effective_context');

        $groups = [];

        foreach (self::MODULE_LABELS as $context => $label) {
            if (! $permissions->has($context)) {
                continue;
            }

            $groups[] = [
                'context' => $context,
                'label' => $label,
                'permissions' => $permissions->get($context),
            ];
        }

        return $groups;
    }
}
