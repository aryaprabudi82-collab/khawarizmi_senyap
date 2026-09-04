@extends('layouts.app')

@section('title', 'Farmasi — Penjualan Bebas')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Penjualan Obat & BHP (Bebas/Kredit)')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
  @can('keuntungan_penjualan')
    <a href="{{ route('pharmacy.rekap.index') }}" class="btn btn-outline-primary">Rekap Untung</a>
  @endcan
@endsection

@section('content')

<p class="text-secondary small mb-3">Penjualan tanpa resep (obat bebas/OTC) &mdash; beda dari Resep Luar yang wajib ada resep dokter. Piutang dibedakan lewat cara bayar, bukan layar terpisah.</p>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Penjualan Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.penjualan.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-4"><label class="form-label">Nama Pembeli</label><input type="text" name="customer_name" class="form-control" required></div>
        <div class="col-12 col-md-3"><label class="form-label">No. Identitas (opsional)</label><input type="text" name="customer_identity_number" class="form-control"></div>
        <div class="col-12 col-md-2">
          <label class="form-label">Cara Bayar</label>
          <select name="payment_status" class="form-select" required>
            <option value="lunas">Lunas</option>
            <option value="piutang">Piutang</option>
          </select>
        </div>
        <div class="col-12 col-md-3"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat/Alkes/BHP</th><th style="width:120px">Jumlah</th><th style="width:160px">Harga Satuan</th></tr></thead>
          <tbody>
            @foreach ($obat as $o)
              <tr>
                <td>
                  {{ $o->name }}
                  <input type="hidden" name="drug_id[]" value="{{ $o->id }}">
                </td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm" value="{{ $o->sell_price }}"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Simpan Penjualan</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Penjualan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Jual</th><th>Pembeli</th><th class="text-end">Total</th><th>Bayar</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($penjualan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->sale_number }}</td>
            <td>{{ $p->customer_name }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $p->total_amount, 0, ',', '.') }}</td>
            <td><span class="badge bg-{{ $p->payment_status === 'lunas' ? 'green' : 'orange' }}-lt">{{ $p->payment_status }}</span></td>
            <td>
              @php $warna = ['selesai' => 'secondary', 'retur-sebagian' => 'yellow', 'retur-penuh' => 'red', 'dibatalkan' => 'red'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
            </td>
            <td>
              @if (! in_array($p->status, ['retur-penuh', 'dibatalkan']))
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#retur-{{ $p->id }}">Retur</button>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada penjualan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($penjualan as $p)
  @if (! in_array($p->status, ['retur-penuh', 'dibatalkan']))
    <div class="modal fade" id="retur-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('pharmacy.penjualan.retur', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Retur {{ $p->sale_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Alasan</label>
            <textarea name="reason" class="form-control mb-3" required></textarea>
            <table class="table table-sm">
              <thead><tr><th>Obat</th><th style="width:100px">Sisa</th><th style="width:100px">Retur</th></tr></thead>
              <tbody>
                @foreach ($p->items as $baris)
                  @if ($baris->remainingQuantity() > 0)
                    <tr>
                      <td class="small">
                        {{ $baris->drug_name }}
                        <input type="hidden" name="sale_item_id[]" value="{{ $baris->id }}">
                      </td>
                      <td>{{ rtrim(rtrim(number_format($baris->remainingQuantity(), 2, ',', '.'), '0'), ',') }}</td>
                      <td><input type="number" step="0.01" min="0" max="{{ $baris->remainingQuantity() }}" name="quantity[]" class="form-control form-control-sm"></td>
                    </tr>
                  @endif
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Simpan Retur</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
