@extends('layouts.app')

@section('title', 'Rekap Lab Kesling')
@section('breadcrumb', 'Konteks envlab')
@section('heading', 'Rekap Pelayanan & Pembayaran Lab Kesling')

@section('content')

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="dari">Dari</label>
        <input type="date" id="dari" name="dari" class="form-control" value="{{ $dari->toDateString() }}">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="sampai">Sampai</label>
        <input type="date" id="sampai" name="sampai" class="form-control" value="{{ $sampai->toDateString() }}">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row row-deck row-cards mb-3">
  <div class="col-6 col-md-2">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Total Permintaan</div>
      <div class="h1 mb-0">{{ $total }}</div>
    </div></div>
  </div>
  @foreach (['diminta' => 'Diminta', 'diproses' => 'Diproses', 'hasil-tersedia' => 'Hasil Tersedia', 'terverifikasi' => 'Terverifikasi', 'selesai' => 'Selesai', 'ditolak' => 'Ditolak'] as $kode => $label)
    <div class="col-6 col-md-2">
      <div class="card"><div class="card-body py-3">
        <div class="text-secondary small">{{ $label }}</div>
        <div class="h1 mb-0">{{ $perStatus[$kode] ?? 0 }}</div>
      </div></div>
    </div>
  @endforeach
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Rekap Pembayaran</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Status</th><th class="text-center">Jumlah</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <tr>
              <td><span class="badge bg-green-lt">Lunas</span></td>
              <td class="text-center">{{ $pembayaran['lunas']->jumlah ?? 0 }}</td>
              <td class="text-end">Rp {{ number_format((float) ($pembayaran['lunas']->total ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
              <td><span class="badge bg-orange-lt">Belum Bayar</span></td>
              <td class="text-center">{{ $pembayaran['belum-bayar']->jumlah ?? 0 }}</td>
              <td class="text-end">—</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Rekap per Pelanggan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pelanggan</th><th class="text-center">Jumlah Permintaan</th></tr></thead>
          <tbody>
            @forelse ($perPelanggan as $p)
              <tr><td>{{ $p->customer_name }}</td><td class="text-center">{{ $p->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
