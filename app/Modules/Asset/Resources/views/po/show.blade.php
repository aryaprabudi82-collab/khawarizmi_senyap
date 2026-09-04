@extends('layouts.app')

@section('title', 'PO ' . $po->po_number)
@section('breadcrumb', 'Konteks asset')
@section('heading', 'PO ' . $po->po_number . ' — ' . $po->supplier->name)

@section('actions')
  <a href="{{ route('asset.po.index') }}" class="btn btn-link">&larr; Daftar PO</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rincian PO</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Barang</th><th class="text-end">Dipesan</th><th class="text-end">Diterima</th><th class="text-end">Harga</th></tr></thead>
          <tbody>
            @foreach ($po->items as $baris)
              <tr>
                <td>{{ $baris->item_name }} <span class="text-secondary small">({{ $baris->category->name ?? '—' }})</span></td>
                <td class="text-end font-monospace">{{ (int) $baris->quantity_ordered }}</td>
                <td class="text-end font-monospace">{{ (int) $baris->quantity_received }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $baris->unit_price, 0, ',', '.') }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr><th colspan="3" class="text-end">Total</th><th class="text-end font-monospace">Rp {{ number_format((float) $po->total_amount, 0, ',', '.') }}</th></tr>
          </tfoot>
        </table>
      </div>
      <div class="card-body border-top d-flex gap-2">
        @if ($po->status === 'draf')
          <form method="POST" action="{{ route('asset.po.kirim', $po) }}">
            @csrf
            <button class="btn btn-sm btn-outline-primary">Kirim ke Suplier</button>
          </form>
        @endif
        @if (! in_array($po->status, ['diterima', 'dibatalkan']))
          <form method="POST" action="{{ route('asset.po.batal', $po) }}" onsubmit="return confirm('Batalkan PO ini?')">
            @csrf
            <button class="btn btn-sm btn-outline-danger">Batalkan</button>
          </form>
        @endif
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    @if (in_array($po->status, ['dipesan', 'diterima-sebagian']))
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Terima Barang</h3></div>
        <div class="card-body">
          <form method="POST" action="{{ route('asset.penerimaan.simpan', $po) }}">
            @csrf
            <table class="table table-sm mb-3">
              <thead><tr><th>Barang</th><th style="width:100px">Terima</th></tr></thead>
              <tbody>
                @foreach ($po->items as $baris)
                  @if ($baris->remainingQuantity() > 0)
                    <tr>
                      <td class="small">
                        {{ $baris->item_name }}
                        <input type="hidden" name="purchase_order_item_id[]" value="{{ $baris->id }}">
                        <div class="text-secondary">sisa {{ (int) $baris->remainingQuantity() }}</div>
                      </td>
                      <td><input type="number" step="1" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                    </tr>
                  @endif
                @endforeach
              </tbody>
            </table>
            <div class="form-hint mb-2">Tiap unit diterima membuat baris aset baru sendiri-sendiri (asset_number masing-masing).</div>
            <button class="btn btn-sm btn-primary w-100">Terima Barang</button>
          </form>
        </div>
      </div>
    @endif

    <div class="card">
      <div class="card-header"><h3 class="card-title">Riwayat Penerimaan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Terima</th><th>Tanggal</th></tr></thead>
          <tbody>
            @forelse ($po->receipts as $r)
              <tr>
                <td class="font-monospace small">{{ $r->receipt_number }}</td>
                <td class="text-secondary small">{{ $r->received_at->format('d-m-Y') }}</td>
              </tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada penerimaan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
