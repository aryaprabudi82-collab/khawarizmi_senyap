@extends('layouts.app')

@section('title', 'Rekap Biaya')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Rekap Biaya')

@section('actions')
  <a href="{{ route('rekap.pembayaran') }}" class="btn btn-link">Rekap Pembayaran &rarr;</a>
@endsection

@section('content')

<div class="alert alert-info">
  Semua angka di sini adalah <b>yang ditagihkan</b>, bukan yang sudah dibayar. Tagihan yang dibatalkan tidak ikut terhitung.
</div>

@include('billing::rekap._saring')

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per jenis biaya</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jenis</th><th class="text-end">Baris</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perSumber as $b)
              <tr>
                <td>{{ str_replace('_', ' ', $b->source_type) }}</td>
                <td class="text-end">{{ $b->jumlah_baris }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada biaya pada rentang ini.</td></tr>
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
          <thead><tr><th>Unit</th><th class="text-end">Tagihan</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perUnit as $b)
              <tr>
                <td>{{ $b->unit_name }}</td>
                <td class="text-end">{{ $b->jumlah_tagihan }}</td>
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

@if ($sumber)
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">{{ str_replace('_', ' ', $sumber) }} per hari</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Tanggal</th><th class="text-end">Baris</th><th class="text-end">Jumlah</th></tr></thead>
        <tbody>
          @forelse ($harian as $b)
            <tr>
              <td>{{ \Illuminate\Support\Carbon::parse($b->tanggal)->format('d-m-Y') }}</td>
              <td class="text-end">{{ $b->jumlah_baris }}</td>
              <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Per pasien</h3><div class="card-subtitle">200 teratas menurut nilai</div></div>
  <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. RM</th><th>Pasien</th><th class="text-end">Baris</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($perPasien as $b)
          <tr>
            <td class="font-monospace small">{{ $b->patient_mrn }}</td>
            <td>{{ $b->patient_name }}</td>
            <td class="text-end">{{ $b->jumlah_baris }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $b->total, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Rincian baris biaya</h3><div class="card-subtitle">500 terbaru</div></div>
  <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Waktu</th><th>Jenis</th><th>Uraian</th><th>Pasien</th><th>Unit</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($rincian as $b)
          <tr>
            <td class="text-secondary small">{{ \Illuminate\Support\Carbon::parse($b->charged_at)->format('d-m-Y H:i') }}</td>
            <td class="text-secondary small">{{ str_replace('_', ' ', $b->source_type) }}</td>
            <td>{{ $b->description }}</td>
            <td class="small">{{ $b->patient_name }}</td>
            <td class="text-secondary small">{{ $b->unit_name }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $b->amount, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
