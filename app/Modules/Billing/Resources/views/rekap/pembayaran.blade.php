@extends('layouts.app')

@section('title', 'Rekap Pembayaran')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Rekap Pembayaran')

@section('actions')
  <a href="{{ route('tagihan.index') }}" class="btn btn-link">&larr; Kasir</a>
@endsection

@section('content')

<div class="alert alert-info">
  Pembayaran yang dibatalkan tidak ikut terhitung di mana pun &mdash; uangnya memang tidak pernah jadi milik rumah sakit.
</div>

@include('billing::rekap._saring')

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per hari</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th class="text-end">Transaksi</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($harian as $b)
              <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($b->tanggal)->format('d-m-Y') }}</td>
                <td class="text-end">{{ $b->jumlah }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pembayaran pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per unit/poliklinik</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th class="text-end">Transaksi</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perUnit as $b)
              <tr>
                <td>{{ $b->unit_name }}</td>
                <td class="text-end">{{ $b->jumlah }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Per petugas penerima</h3>
    <div class="card-subtitle">Dipakai saat tutup kas: siapa memegang berapa. Tetap benar walau petugas bertukar giliran.</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Petugas</th><th class="text-end">Transaksi</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($perPetugas as $b)
          <tr>
            <td>{{ $b->received_by_name }}</td>
            <td class="text-end">{{ $b->jumlah }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
