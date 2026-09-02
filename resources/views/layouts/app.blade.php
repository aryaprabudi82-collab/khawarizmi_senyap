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
        @can('registrasi')
          <li class="nav-item {{ request()->routeIs('registrasi.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('registrasi.index') }}">Pendaftaran</a>
          </li>
        @endcan
        @can('penilaian_awal_medis_ralan')
          <li class="nav-item {{ request()->routeIs('rme.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('rme.index') }}">Rekam Medis</a>
          </li>
        @endcan
        @can('resep_obat')
          <li class="nav-item {{ request()->routeIs('resep.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('resep.index') }}">Farmasi</a>
          </li>
        @endcan
        @can('pembayaran_ralan')
          <li class="nav-item {{ request()->routeIs('tagihan.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tagihan.index') }}">Kasir</a>
          </li>
        @endcan
        @can('periksa_lab')
          <li class="nav-item {{ request()->routeIs('order.*') && request()->route('kategori') === 'lab' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('order.index', 'lab') }}">Laboratorium</a>
          </li>
        @endcan
        @can('periksa_radiologi')
          <li class="nav-item {{ request()->routeIs('order.*') && request()->route('kategori') === 'radiologi' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('order.index', 'radiologi') }}">Radiologi</a>
          </li>
        @endcan
        @can('bayar_piutang')
          <li class="nav-item {{ request()->routeIs('piutang.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('piutang.index') }}">Piutang</a>
          </li>
        @endcan
        @can('tarif_ralan')
          <li class="nav-item dropdown {{ request()->routeIs('master.*') ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Data Master</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('master.index') }}">Layanan &amp; Tarif</a>
              <a class="dropdown-item" href="{{ route('master.organisasi') }}">Unit &amp; Praktisi</a>
            </div>
          </li>
        @endcan
        @can('pasien')
          <li class="nav-item {{ request()->routeIs('pasien.*') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('pasien.index') }}">Pasien</a>
          </li>
        @endcan
        @canany(['bpjs_cek_kartu', 'satu_sehat_referensi_pasien'])
          <li class="nav-item dropdown {{ request()->routeIs('integrasi.*') ? 'active' : '' }}">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Integrasi</a>
            <div class="dropdown-menu">
              @can('bpjs_cek_kartu')
                <a class="dropdown-item" href="{{ route('integrasi.bpjs.index') }}">BPJS</a>
              @endcan
              @can('satu_sehat_referensi_pasien')
                <a class="dropdown-item" href="{{ route('integrasi.satusehat.index') }}">SATUSEHAT</a>
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
