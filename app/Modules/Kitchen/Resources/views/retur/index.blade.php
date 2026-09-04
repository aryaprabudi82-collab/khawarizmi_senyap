@extends('layouts.app')

@section('title', 'Dapur — Retur Ke Suplier')
@section('breadcrumb', 'Konteks kitchen')
@section('heading', 'Retur Barang Dapur Ke Suplier')

@section('actions')
  <a href="{{ route('kitchen.po.index') }}" class="btn btn-link">&larr; PO</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Retur</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('kitchen.retur.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
          <label class="form-label">Suplier</label>
          <select name="supplier_id" class="form-select" required>
            @foreach ($suplier as $s)
              <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Terkait Penerimaan (opsional)</label>
          <select name="goods_receipt_id" class="form-select">
            <option value="">— tidak terkait —</option>
            @foreach ($penerimaan as $r)
              <option value="{{ $r->id }}">{{ $r->receipt_number }} &middot; PO {{ $r->purchaseOrder->po_number }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Alasan</label>
          <input type="text" name="reason" class="form-control" required>
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Barang</th><th style="width:120px">Jumlah</th></tr></thead>
          <tbody>
            @foreach ($barang as $b)
              <tr>
                <td>
                  {{ $b->name }}
                  <input type="hidden" name="item_id[]" value="{{ $b->id }}">
                </td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Ajukan Retur</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Retur Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Retur</th><th>Suplier</th><th>Barang</th><th>Alasan</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($retur as $r)
          <tr>
            <td class="font-monospace small">{{ $r->return_number }}</td>
            <td>{{ $r->supplier->name }}</td>
            <td class="text-secondary small">
              @foreach ($r->items as $baris)
                {{ $baris->item->name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td class="text-secondary small">{{ $r->reason }}</td>
            <td><span class="badge bg-{{ $r->status === 'selesai' ? 'green' : 'yellow' }}-lt">{{ $r->status }}</span></td>
            <td>
              @if ($r->status === 'diajukan')
                <form method="POST" action="{{ route('kitchen.retur.selesai', $r) }}" onsubmit="return confirm('Selesaikan retur ini? Stok terkait akan dikurangi.')">
                  @csrf
                  <button class="btn btn-sm btn-outline-success">Selesaikan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada retur.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
