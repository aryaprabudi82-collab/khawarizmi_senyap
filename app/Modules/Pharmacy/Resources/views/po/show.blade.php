@extends('layouts.app')

@section('title', 'PO ' . $po->po_number)
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'PO ' . $po->po_number . ' — ' . $po->supplier->name)

@section('actions')
  <a href="{{ route('pharmacy.po.index') }}" class="btn btn-link">&larr; Daftar PO</a>
  @can('pemesanan_obat')
    <a href="{{ route('pharmacy.po.cetak', $po) }}" class="btn btn-outline-secondary" target="_blank">Cetak Surat Pemesanan</a>
  @endcan
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rincian PO</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat/Alkes/BHP</th><th>Satuan</th><th class="text-end">Dipesan</th><th class="text-end">Diterima</th><th class="text-end">Harga</th></tr></thead>
          <tbody>
            @foreach ($po->items as $baris)
              <tr>
                <td>{{ $baris->drug_name }}</td>
                <td class="text-secondary small">{{ $baris->unit }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $baris->quantity_ordered, 2, ',', '.'), '0'), ',') }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $baris->quantity_received, 2, ',', '.'), '0'), ',') }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $baris->unit_price, 0, ',', '.') }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr><th colspan="4" class="text-end">Total</th><th class="text-end font-monospace">Rp {{ number_format((float) $po->total_amount, 0, ',', '.') }}</th></tr>
          </tfoot>
        </table>
      </div>
      <div class="card-body border-top d-flex gap-2">
        @if ($po->status === 'draf')
          <form method="POST" action="{{ route('pharmacy.po.kirim', $po) }}">
            @csrf
            <button class="btn btn-sm btn-outline-primary">Kirim ke Suplier</button>
          </form>
        @endif
        @if (! in_array($po->status, ['diterima', 'dibatalkan']))
          <form method="POST" action="{{ route('pharmacy.po.batal', $po) }}" onsubmit="return confirm('Batalkan PO ini?')">
            @csrf
            <button class="btn btn-sm btn-outline-danger">Batalkan</button>
          </form>
        @endif
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    @can('bayar_pemesanan_obat')
      @if (in_array($po->status, ['dipesan', 'diterima-sebagian']))
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Terima Barang</h3></div>
          <div class="card-body">
            <form method="POST" action="{{ route('pharmacy.penerimaan.simpan', $po) }}">
              @csrf
              <table class="table table-sm mb-3">
                <thead><tr><th>Obat</th><th style="width:100px">Terima</th><th style="width:130px">No. Batch</th><th style="width:130px">Kedaluwarsa</th></tr></thead>
                <tbody>
                  @foreach ($po->items as $baris)
                    @if ($baris->remainingQuantity() > 0)
                      <tr>
                        <td class="small">
                          {{ $baris->drug_name }}
                          <input type="hidden" name="purchase_order_item_id[]" value="{{ $baris->id }}">
                          <div class="text-secondary">sisa {{ rtrim(rtrim(number_format($baris->remainingQuantity(), 2, ',', '.'), '0'), ',') }} {{ $baris->unit }}</div>
                        </td>
                        <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                        <td><input type="text" name="batch_number[]" class="form-control form-control-sm"></td>
                        <td><input type="date" name="expiry_date[]" class="form-control form-control-sm"></td>
                      </tr>
                    @endif
                  @endforeach
                </tbody>
              </table>
              <div class="row g-2 mb-3">
                <div class="col-6"><input type="text" name="invoice_number" class="form-control form-control-sm" placeholder="No. Faktur"></div>
                <div class="col-6"><input type="number" step="0.01" min="0" name="paid_amount" class="form-control form-control-sm" placeholder="Jumlah dibayar"></div>
              </div>
              <button class="btn btn-sm btn-primary w-100">Terima &amp; Catat Pembayaran</button>
            </form>
          </div>
        </div>
      @endif
    @endcan

    <div class="card">
      <div class="card-header"><h3 class="card-title">Riwayat Penerimaan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Terima</th><th>Tanggal</th><th>Status</th></tr></thead>
          <tbody>
            @forelse ($po->receipts as $r)
              <tr>
                <td class="font-monospace small">{{ $r->receipt_number }}</td>
                <td class="text-secondary small">{{ $r->received_at->format('d-m-Y') }}</td>
                <td><span class="badge bg-{{ $r->status === 'terverifikasi' ? 'green' : 'yellow' }}-lt">{{ $r->status }}</span></td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada penerimaan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
