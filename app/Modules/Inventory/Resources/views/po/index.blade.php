@extends('layouts.app')

@section('title', 'Logistik — Pengadaan')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Pengadaan & Pemesanan Barang Non-Medis')

@section('actions')
  <a href="{{ route('inventory.permintaan.index') }}" class="btn btn-link">&larr; Permintaan</a>
  @can('penerimaan_non_medis')
    <a href="{{ route('inventory.penerimaan.index') }}" class="btn btn-outline-primary">Penerimaan</a>
  @endcan
  @can('ipsrs_returbeli')
    <a href="{{ route('inventory.retur.index') }}" class="btn btn-outline-primary">Retur</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Buat PO Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('inventory.po.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Suplier</label>
          <select name="supplier_id" class="form-select" required>
            @foreach ($suplier as $s)
              <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Dari Pengajuan (opsional)</label>
          <select name="requisition_id" class="form-select">
            <option value="">— berdiri sendiri —</option>
            @foreach ($pengajuanTerbuka as $p)
              <option value="{{ $p->id }}">{{ $p->requisition_number }} &middot; {{ $p->unit_name }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Barang</th><th style="width:100px">Satuan</th><th style="width:110px">Jumlah</th><th style="width:150px">Harga Satuan</th></tr></thead>
          <tbody>
            @foreach ($barang as $b)
              <tr>
                <td>
                  {{ $b->name }}
                  <input type="hidden" name="item_id[]" value="{{ $b->id }}">
                </td>
                <td><input type="text" name="unit_of_measure[]" class="form-control form-control-sm" value="{{ $b->unit_of_measure }}"></td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Buat PO</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">PO Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. PO</th><th>Suplier</th><th class="text-end">Total</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($po as $p)
          <tr>
            <td class="font-monospace small">{{ $p->po_number }}</td>
            <td>{{ $p->supplier->name }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $p->total_amount, 0, ',', '.') }}</td>
            <td>
              @php $warna = ['draf' => 'secondary', 'dipesan' => 'yellow', 'diterima-sebagian' => 'blue', 'diterima' => 'green', 'dibatalkan' => 'red'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
            </td>
            <td><a href="{{ route('inventory.po.show', $p) }}" class="btn btn-sm btn-outline-primary">Detail</a></td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada PO.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
