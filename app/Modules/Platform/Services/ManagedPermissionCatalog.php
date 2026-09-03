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
 * Tiga kode (audit_kepatuhan_apd, peristiwa_k3rs, insiden_keselamatan_pasien)
 * masih tercatat context=hr/clinical di platform.permissions — peninggalan
 * salah-taut domain huruf Khanza (lihat catatan di database/data/roles.json).
 * Layarnya sendiri sudah dibangun di konteks quality, jadi dikelompokkan ke
 * situ di sini supaya admin tidak salah kira sedang memberi akses HR/klinis.
 */
class ManagedPermissionCatalog
{
    /**
     * Kode permission yang menggerbangi route sungguhan, per 2026-09-03.
     * Menambah baru di sini berarti sudah ada layar untuk kode tersebut.
     */
    private const MANAGED_CODES = [
        'audit_kepatuhan_apd', 'bayar_piutang', 'beri_obat', 'bpjs_cek_kartu', 'bpjs_sep',
        'insiden_keselamatan_pasien', 'inventaris_inventaris', 'ipsrs_barang', 'limbah_b3_medis',
        'mapping_poli_bpjs', 'pasien', 'pcra_icra_pengkajian_risiko_prakonstruksi', 'pegawai_user',
        'pembayaran_ralan', 'pengajuan_barang_nonmedis', 'pengajuan_cuti', 'pengumuman_epasien',
        'penilaian_awal_medis_ralan', 'perbaikan_inventaris', 'periksa_lab', 'periksa_radiologi',
        'peristiwa_k3rs', 'persetujuan_penolakan_tindakan', 'presensi_harian', 'registrasi',
        'rekap_kunjungan', 'resep_obat', 'satu_sehat_kirim_condition', 'satu_sehat_kirim_encounter',
        'satu_sehat_mapping_lokasi', 'satu_sehat_referensi_dokter', 'satu_sehat_referensi_pasien',
        'sirkulasi_cssd', 'surat_keterangan_sehat', 'surat_masuk', 'tarif_ralan', 'telaah_resep',
        'utd_pendonor', 'utd_penyerahan_darah', 'utd_stok_darah',
        'user',
    ];

    /** Koreksi context Khanza yang salah-taut, lihat catatan kelas. */
    private const CONTEXT_OVERRIDE = [
        'audit_kepatuhan_apd' => 'quality',
        'peristiwa_k3rs' => 'quality',
        'insiden_keselamatan_pasien' => 'quality',
    ];

    private const MODULE_LABELS = [
        'encounter' => 'Pendaftaran & Rawat Jalan',
        'clinical' => 'Rekam Medis Klinis',
        'pharmacy' => 'Farmasi',
        'billing' => 'Billing Rawat Jalan',
        'finance' => 'Keuangan',
        'inventory' => 'Logistik & Barang Non-Medis',
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
