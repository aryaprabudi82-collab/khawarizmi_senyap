<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Beranda') &middot; SIMRS RSP UI</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">

  <style>
    :root { --tblr-font-sans-serif: "Plus Jakarta Sans", -apple-system, system-ui, sans-serif; }
    body { font-family: var(--tblr-font-sans-serif); }
    .navbar-brand-simrs { color:#fff; font-weight:700; letter-spacing:.3px; text-decoration:none; }
    .queue-number { font-variant-numeric: tabular-nums; font-weight:700; font-size:1.15rem; }
    .table td, .table th { vertical-align: middle; }
  </style>
  @stack('styles')
</head>
<body class="antialiased">

<header class="navbar navbar-expand-md navbar-dark sticky-top d-print-none"
        style="background: linear-gradient(135deg,#1d4ed8,#0e5aa7); box-shadow:0 4px 25px rgba(29,78,216,.18);">
  <div class="container-xl">
    <a href="{{ route('beranda') }}" class="navbar-brand navbar-brand-simrs d-flex align-items-center gap-2">
      <span class="avatar avatar-sm bg-white text-primary fw-bold">RS</span>
      <span class="d-none d-md-inline">SIMRS RSP UI</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu-utama">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="menu-utama">
      <ul class="navbar-nav me-auto">
        {{--
          Satu dropdown per departemen, bukan satu per konteks — dengan 18
          bounded context, satu item nav per konteks meluber ke luar layar
          (lihat riwayat commit). Pengelompokan ini cuma tampilan; setiap
          tautan tetap ke route yang sama dan tetap digerbangi @can/@canany
          per permission-nya sendiri, jadi satu pengguna cuma melihat
          tautan yang haknya dia punya, sekalipun dropdown-nya digabung.
        --}}
        @canany(['registrasi', 'penilaian_awal_medis_ralan', 'periksa_lab', 'periksa_radiologi', 'pemeriksaan_lab_pa', 'resep_obat', 'persetujuan_penolakan_tindakan', 'surat_keterangan_sehat', 'tindakan_ranap', 'rujukan_keluar', 'igd', 'booking_mcu_perusahaan', 'booking_operasi', 'layanan_program_kfr'])
          <li class="nav-item dropdown {{ request()->routeIs(['registrasi.*', 'rme.*', 'order.*', 'resep.*', 'correspondence.persetujuan.*', 'correspondence.keterangan.*', 'inpatient.*', 'rujukan-keluar.*', 'igd.*', 'mcu-perusahaan.*', 'booking-operasi.*', 'program-kfr.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Pelayanan</a>
            <div class="dropdown-menu">
              @can('registrasi')
                <a class="dropdown-item" href="{{ route('registrasi.index') }}">Pendaftaran</a>
              @endcan
              @can('booking_mcu_perusahaan')
                <a class="dropdown-item" href="{{ route('mcu-perusahaan.index') }}">Booking MCU Perusahaan</a>
              @endcan
              @can('booking_operasi')
                <a class="dropdown-item" href="{{ route('booking-operasi.index') }}">Jadwal Operasi</a>
              @endcan
              @can('igd')
                <a class="dropdown-item" href="{{ route('igd.index') }}">IGD/UGD</a>
              @endcan
              @can('tindakan_ranap')
                <a class="dropdown-item" href="{{ route('inpatient.index') }}">Rawat Inap</a>
              @endcan
              @can('penilaian_awal_medis_ralan')
                <a class="dropdown-item" href="{{ route('rme.index') }}">Rekam Medis</a>
              @endcan
              @can('periksa_lab')
                <a class="dropdown-item" href="{{ route('order.index', 'lab') }}">Laboratorium</a>
              @endcan
              @can('periksa_radiologi')
                <a class="dropdown-item" href="{{ route('order.index', 'radiologi') }}">Radiologi</a>
              @endcan
              @can('pemeriksaan_lab_pa')
                <a class="dropdown-item" href="{{ route('order.index', 'pa') }}">Patologi Anatomi</a>
              @endcan
              @can('resep_obat')
                <a class="dropdown-item" href="{{ route('resep.index') }}">Farmasi</a>
              @endcan
              @canany(['persetujuan_penolakan_tindakan', 'surat_keterangan_sehat', 'rujukan_keluar', 'layanan_program_kfr'])
                <div class="dropdown-divider"></div>
              @endcanany
              @can('persetujuan_penolakan_tindakan')
                <a class="dropdown-item" href="{{ route('correspondence.persetujuan.index') }}">Persetujuan Tindakan</a>
              @endcan
              @can('surat_keterangan_sehat')
                <a class="dropdown-item" href="{{ route('correspondence.keterangan.index') }}">Surat Keterangan</a>
              @endcan
              @can('rujukan_keluar')
                <a class="dropdown-item" href="{{ route('rujukan-keluar.index') }}">Rujukan Keluar</a>
              @endcan
              @can('layanan_program_kfr')
                <a class="dropdown-item" href="{{ route('program-kfr.index') }}">Program KFR</a>
              @endcan
            </div>
          </li>
        @endcanany

        @canany(['pembayaran_ralan', 'bayar_piutang', 'deposit_pasien', 'perkiraan_biaya_ranap'])
          <li class="nav-item dropdown {{ request()->routeIs(['tagihan.*', 'piutang.*', 'deposit.*', 'estimasi-ranap.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Keuangan</a>
            <div class="dropdown-menu">
              @can('pembayaran_ralan')
                <a class="dropdown-item" href="{{ route('tagihan.index') }}">Kasir</a>
              @endcan
              @can('bayar_piutang')
                <a class="dropdown-item" href="{{ route('piutang.index') }}">Piutang</a>
              @endcan
              @can('deposit_pasien')
                <a class="dropdown-item" href="{{ route('deposit.index') }}">Deposit Pasien</a>
              @endcan
              @can('perkiraan_biaya_ranap')
                <a class="dropdown-item" href="{{ route('estimasi-ranap.index') }}">Perkiraan Biaya Ranap</a>
              @endcan
            </div>
          </li>
        @endcanany

        @canany(['tarif_ralan', 'pasien'])
          <li class="nav-item dropdown {{ request()->routeIs(['master.*', 'pasien.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Data Master</a>
            <div class="dropdown-menu">
              @can('tarif_ralan')
                <a class="dropdown-item" href="{{ route('master.index') }}">Layanan &amp; Tarif</a>
                <a class="dropdown-item" href="{{ route('master.organisasi') }}">Unit &amp; Praktisi</a>
              @endcan
              @can('pasien')
                <a class="dropdown-item" href="{{ route('pasien.index') }}">Pasien</a>
              @endcan
            </div>
          </li>
        @endcanany

        @canany(['inventaris_inventaris', 'perbaikan_inventaris', 'sirkulasi_cssd', 'limbah_b3_medis', 'ipsrs_barang', 'pengajuan_barang_nonmedis', 'utd_pendonor', 'utd_stok_darah'])
          <li class="nav-item dropdown {{ request()->routeIs(['asset.*', 'inventory.*', 'blood.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Penunjang</a>
            <div class="dropdown-menu">
              @can('inventaris_inventaris')
                <a class="dropdown-item" href="{{ route('asset.index') }}">Aset &amp; Inventaris</a>
              @endcan
              @can('perbaikan_inventaris')
                <a class="dropdown-item" href="{{ route('asset.pemeliharaan.index') }}">Pemeliharaan Aset</a>
              @endcan
              @can('sirkulasi_cssd')
                <a class="dropdown-item" href="{{ route('asset.cssd.index') }}">CSSD</a>
              @endcan
              @can('limbah_b3_medis')
                <a class="dropdown-item" href="{{ route('asset.kesling.index') }}">Kesehatan Lingkungan</a>
              @endcan
              @canany(['ipsrs_barang', 'pengajuan_barang_nonmedis'])
                <div class="dropdown-divider"></div>
              @endcanany
              @can('ipsrs_barang')
                <a class="dropdown-item" href="{{ route('inventory.index') }}">Barang Logistik</a>
              @endcan
              @can('pengajuan_barang_nonmedis')
                <a class="dropdown-item" href="{{ route('inventory.permintaan.index') }}">Permintaan Logistik</a>
              @endcan
              @canany(['utd_pendonor', 'utd_stok_darah'])
                <div class="dropdown-divider"></div>
              @endcanany
              @can('utd_pendonor')
                <a class="dropdown-item" href="{{ route('blood.pendonor.index') }}">Pendonor Darah</a>
              @endcan
              @can('utd_stok_darah')
                <a class="dropdown-item" href="{{ route('blood.stok.index') }}">Stok Darah</a>
              @endcan
            </div>
          </li>
        @endcanany

        @canany(['pegawai_user', 'pengajuan_cuti', 'presensi_harian', 'insiden_keselamatan_pasien', 'pcra_icra_pengkajian_risiko_prakonstruksi', 'audit_kepatuhan_apd', 'peristiwa_k3rs'])
          <li class="nav-item dropdown {{ request()->routeIs(['hr.*', 'quality.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">SDM &amp; Mutu</a>
            <div class="dropdown-menu">
              @can('pegawai_user')
                <a class="dropdown-item" href="{{ route('hr.index') }}">Pegawai</a>
              @endcan
              @can('pengajuan_cuti')
                <a class="dropdown-item" href="{{ route('hr.cuti.index') }}">Cuti</a>
              @endcan
              @can('presensi_harian')
                <a class="dropdown-item" href="{{ route('hr.presensi.index') }}">Presensi</a>
              @endcan
              @canany(['insiden_keselamatan_pasien', 'pcra_icra_pengkajian_risiko_prakonstruksi', 'audit_kepatuhan_apd', 'peristiwa_k3rs'])
                <div class="dropdown-divider"></div>
              @endcanany
              @can('insiden_keselamatan_pasien')
                <a class="dropdown-item" href="{{ route('quality.insiden.index') }}">Insiden Keselamatan (IKP)</a>
              @endcan
              @can('pcra_icra_pengkajian_risiko_prakonstruksi')
                <a class="dropdown-item" href="{{ route('quality.icra.index') }}">PCRA/ICRA</a>
              @endcan
              @can('audit_kepatuhan_apd')
                <a class="dropdown-item" href="{{ route('quality.ppi.index') }}">Audit PPI</a>
              @endcan
              @can('peristiwa_k3rs')
                <a class="dropdown-item" href="{{ route('quality.k3.index') }}">Insiden K3</a>
              @endcan
            </div>
          </li>
        @endcanany

        @canany(['surat_masuk', 'pengumuman_epasien', 'rekap_kunjungan', 'bpjs_cek_kartu', 'satu_sehat_referensi_pasien', 'user'])
          <li class="nav-item dropdown {{ request()->routeIs(['correspondence.*', 'reporting.*', 'integrasi.*', 'platform.*']) ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Administrasi</a>
            <div class="dropdown-menu">
              @can('surat_masuk')
                <a class="dropdown-item" href="{{ route('correspondence.index') }}">Surat</a>
              @endcan
              @can('pengumuman_epasien')
                <a class="dropdown-item" href="{{ route('correspondence.pengumuman.index') }}">Pengumuman E-Pasien</a>
              @endcan
              @can('rekap_kunjungan')
                <a class="dropdown-item" href="{{ route('reporting.dashboard') }}">Laporan</a>
              @endcan
              @canany(['bpjs_cek_kartu', 'satu_sehat_referensi_pasien'])
                <div class="dropdown-divider"></div>
              @endcanany
              @can('bpjs_cek_kartu')
                <a class="dropdown-item" href="{{ route('integrasi.bpjs.index') }}">Integrasi BPJS</a>
              @endcan
              @can('satu_sehat_referensi_pasien')
                <a class="dropdown-item" href="{{ route('integrasi.satusehat.index') }}">Integrasi SATUSEHAT</a>
              @endcan
              @can('user')
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('platform.pengguna.index') }}">Kelola Pengguna</a>
                <a class="dropdown-item" href="{{ route('platform.peran.index') }}">Kelola Peran</a>
              @endcan
            </div>
          </li>
        @endcanany
      </ul>

      <div class="navbar-nav flex-row order-md-last">
        <div class="nav-item dropdown">
          <a href="#" class="nav-link d-flex align-items-center gap-2 text-white" data-bs-toggle="dropdown">
            <span class="avatar avatar-sm bg-white text-primary fw-bold">
              {{ Str::upper(Str::substr(auth()->user()->name ?? '?', 0, 1)) }}
            </span>
            <span class="d-none d-xl-block">
              <div>{{ auth()->user()->name ?? '' }}</div>
              <div class="small text-white-50">{{ auth()->user()?->roles->pluck('name')->join(', ') }}</div>
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-end">
            <form method="POST" action="{{ route('keluar') }}">
              @csrf
              <button type="submit" class="dropdown-item">Keluar</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="page-wrapper">
  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="row g-2 align-items-center">
        <div class="col">
          @hasSection('breadcrumb')
            <div class="page-pretitle">@yield('breadcrumb')</div>
          @endif
          <h2 class="page-title">@yield('heading', View::getSection('title'))</h2>
        </div>
        <div class="col-auto ms-auto d-print-none">@yield('actions')</div>
      </div>
    </div>
  </div>

  <div class="page-body">
    <div class="container-xl">

      @if (session('sukses'))
        <div class="alert alert-success alert-dismissible" role="alert">
          {{ session('sukses') }}
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      @endif

      @if (session('galat'))
        <div class="alert alert-danger alert-dismissible" role="alert">
          {{ session('galat') }}
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      @endif

      @if ($errors->any())
        <div class="alert alert-danger">
          <h4 class="alert-title">Periksa kembali isian berikut</h4>
          <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $pesan)
              <li>{{ $pesan }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @yield('content')
    </div>
  </div>

  <footer class="footer footer-transparent d-print-none">
    <div class="container-xl text-muted small">
      SIMRS Mandiri RSP UI &middot; Rekam medis elektronik mengacu Permenkes 24/2022
    </div>
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
@stack('scripts')
</body>
</html>
