@extends('layouts.app')

@section('title', 'Penagihan Piutang Pasien')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Penagihan Piutang Pasien')

@section('actions')
  <a href="{{ route('piutang-pasien.index') }}" class="btn btn-link">&larr; Piutang Pasien</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Layar ini mencatat upaya penagihannya, bukan uangnya.</b>
  Sisa piutang diturunkan dari sisa tagihan pasien, dan pembayarannya dicatat lewat pembayaran tagihan
  seperti biasa &mdash; kalau uang punya dua tempat pencatatan, dua angkanya cepat atau lambat berbeda
  dan tidak ada yang tahu mana yang benar.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-4">
        <label class="form-label">Jenis rawat</label>
        <select name="jenis_rawat" class="form-select">
          <option value="">Semua</option>
          <option value="ralan" @selected($jenisRawat === 'ralan')>Rawat Jalan</option>
          <option value="ranap" @selected($jenisRawat === 'ranap')>Rawat Inap</option>
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

@if ($belumDitagih->isNotEmpty())
  <div class="alert alert-warning">
    <b>{{ $belumDitagih->count() }} piutang belum pernah ditagih sama sekali</b>
    (Rp {{ number_format($belumDitagih->sum('sisa'), 0, ',', '.') }}).
    Piutang menumpuk paling sering bukan karena pasien menolak, melainkan karena tidak pernah ada yang menagih &mdash;
    tanpa memisahkannya, kedua sebab itu terlihat sama.
  </div>
@endif

<div class="row">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Umur Piutang</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelompok</th><th class="text-end">Piutang</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($umur as $b)
              <tr>
                <td class="{{ $b->kelompok === 'lebih dari 90 hari' ? 'text-danger' : '' }}">{{ $b->kelompok }}</td>
                <td class="text-end">{{ $b->piutang }}</td>
                <td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada piutang bersisa.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per Cara Bayar</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Penjamin</th><th class="text-end">Piutang</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perPenjamin as $b)
              <tr><td>{{ $b->payer_name }}</td><td class="text-end">{{ $b->piutang }}</td><td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per Cara Bayar per Bulan</h3></div>
      <div class="table-responsive" style="max-height:240px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Bulan</th><th>Penjamin</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perBulan as $b)
              <tr><td>{{ $b->bulan }}</td><td class="small">{{ $b->payer_name }}</td><td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@if ($menungguVerifikasi->isNotEmpty())
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Catatan Penagihan Menunggu Verifikasi</h3>
      <div class="card-subtitle">Tidak bisa diverifikasi oleh penagihnya sendiri &mdash; catatan ini dasar penghapusan piutang</div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Tanggal</th><th>Pasien</th><th>Kanal</th><th>Hasil</th><th>Penagih</th><th></th></tr></thead>
        <tbody>
          @foreach ($menungguVerifikasi as $b)
            <tr>
              <td>{{ $b->contacted_on }}</td>
              <td>{{ $b->patient_name }}</td>
              <td class="small text-secondary">{{ $b->channel }}</td>
              <td>{{ $daftarHasil[$b->outcome] ?? $b->outcome }}</td>
              <td class="small text-secondary">{{ $b->contacted_by_name ?: '—' }}</td>
              <td class="text-end">
                <form method="POST" action="{{ route('penagihan-piutang.verifikasi', $b->id) }}">
                  @csrf
                  <button class="btn btn-sm btn-success">Verifikasi</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="card">
  <div class="card-header"><h3 class="card-title">Piutang Bersisa</h3><div class="card-subtitle">Sisa diturunkan dari sisa tagihannya, bukan angka tersendiri</div></div>
  <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead>
        <tr><th>Pasien</th><th>Tagihan</th><th>Penjamin</th><th>Jatuh tempo</th><th class="text-end">Sisa</th><th class="text-end">Upaya</th><th>Terakhir</th><th style="width:30%"></th></tr>
      </thead>
      <tbody>
        @forelse ($terutang as $b)
          <tr>
            <td>{{ $b->patient_name }}</td>
            <td class="font-monospace small">{{ $b->invoice_number }}</td>
            <td class="small text-secondary">{{ $b->payer_name }}</td>
            <td class="{{ $b->due_date < now()->toDateString() ? 'text-danger' : '' }}">{{ $b->due_date }}</td>
            <td class="text-end"><b>{{ number_format($b->sisa, 0, ',', '.') }}</b></td>
            <td class="text-end {{ $b->upaya_tagih == 0 ? 'text-danger' : '' }}">{{ $b->upaya_tagih }}</td>
            <td class="small text-secondary">{{ $b->tagih_terakhir ?: '—' }}</td>
            <td>
              <form method="POST" action="{{ route('penagihan-piutang.simpan', $b->id) }}" class="d-flex gap-1">
                @csrf
                <input name="contacted_on" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                <select name="channel" class="form-select form-select-sm" required>
                  @foreach ($daftarKanal as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                </select>
                <select name="outcome" class="form-select form-select-sm" required>
                  @foreach ($daftarHasil as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
                <button class="btn btn-sm btn-primary">Catat</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-secondary py-3">Tidak ada piutang bersisa.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
