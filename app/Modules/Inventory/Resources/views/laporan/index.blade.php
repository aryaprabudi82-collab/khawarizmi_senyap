@extends('layouts.app')

@section('title', 'Logistik — Riwayat & Sirkulasi')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Riwayat & Sirkulasi Barang Non-Medis')

@section('actions')
  <a href="{{ route('inventory.opname.index') }}" class="btn btn-link">Stok Opname &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Filter</h3></div>
  <div class="card-body">
    <form method="GET" action="{{ route('inventory.laporan.index') }}" class="row g-2">
      <div class="col-12 col-md-4">
        <label class="form-label">Barang (riwayat per barang)</label>
        <select name="item_id" class="form-select">
          <option value="">— pilih barang —</option>
          @foreach ($barang as $b)
            <option value="{{ $b->id }}" @selected($itemId === $b->id)>{{ $b->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis</label>
        <select name="kind" class="form-select">
          <option value="">Semua</option>
          <option value="masuk" @selected(request('kind') === 'masuk')>Masuk</option>
          <option value="keluar" @selected(request('kind') === 'keluar')>Keluar</option>
          <option value="opname" @selected(request('kind') === 'opname')>Opname</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Dari</label>
        <input type="date" name="dari" class="form-control" value="{{ request('dari') }}">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Sampai</label>
        <input type="date" name="sampai" class="form-control" value="{{ request('sampai') }}">
      </div>
      <div class="col-6 col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

@if ($itemId)
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Riwayat Barang Terpilih</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Tanggal</th><th>Jenis</th><th>Sumber</th><th class="text-end">Jumlah</th><th class="text-end">Saldo</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($riwayat as $m)
          <tr>
            <td class="text-secondary small">{{ \Illuminate\Support\Carbon::parse($m->moved_at)->format('d-m-Y H:i') }}</td>
            <td><span class="badge bg-{{ $m->kind === 'masuk' ? 'green' : ($m->kind === 'keluar' ? 'red' : 'blue') }}-lt">{{ $m->kind }}</span></td>
            <td class="text-secondary small">{{ $m->source }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->quantity, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->balance_after, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-secondary small">{{ $m->note }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pergerakan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Sirkulasi (Buku Besar Terfilter)</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Tanggal</th><th>Barang</th><th>Jenis</th><th>Sumber</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($sirkulasi as $m)
          <tr>
            <td class="text-secondary small">{{ \Illuminate\Support\Carbon::parse($m->moved_at)->format('d-m-Y H:i') }}</td>
            <td>{{ $m->item_name }} <span class="text-secondary small">({{ $m->unit_of_measure }})</span></td>
            <td><span class="badge bg-{{ $m->kind === 'masuk' ? 'green' : ($m->kind === 'keluar' ? 'red' : 'blue') }}-lt">{{ $m->kind }}</span></td>
            <td class="text-secondary small">{{ $m->source }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->quantity, 2, ',', '.'), '0'), ',') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada data untuk filter ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Rekap Sirkulasi Bulanan</h3>
    <form method="GET" action="{{ route('inventory.laporan.index') }}" class="ms-auto">
      <input type="month" name="bulan" class="form-control form-control-sm" value="{{ $bulan }}" onchange="this.form.submit()">
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Barang</th><th class="text-end">Total Masuk</th><th class="text-end">Total Keluar</th><th class="text-end">Koreksi Opname</th></tr></thead>
      <tbody>
        @forelse ($sirkulasiBulanan as $r)
          <tr>
            <td>{{ $r->item_name }} <span class="text-secondary small">({{ $r->unit_of_measure }})</span></td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $r->total_masuk, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $r->total_keluar, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $r->total_koreksi_opname, 2, ',', '.'), '0'), ',') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada pergerakan bulan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
