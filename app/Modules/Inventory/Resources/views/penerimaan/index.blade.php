@extends('layouts.app')

@section('title', 'Logistik — Penerimaan Barang')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Penerimaan Barang Non-Medis')

@section('actions')
  <a href="{{ route('inventory.po.index') }}" class="btn btn-link">&larr; PO</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header"><h3 class="card-title">Penerimaan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Terima</th><th>PO</th><th>Suplier</th><th>Tanggal</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($penerimaan as $r)
          <tr>
            <td class="font-monospace small">{{ $r->receipt_number }}</td>
            <td class="font-monospace small">{{ $r->purchaseOrder->po_number }}</td>
            <td>{{ $r->purchaseOrder->supplier->name }}</td>
            <td class="text-secondary small">{{ $r->received_at->format('d-m-Y') }}</td>
            <td><span class="badge bg-{{ $r->status === 'terverifikasi' ? 'green' : 'yellow' }}-lt">{{ $r->status }}</span></td>
            <td>
              @can('verifikasi_penerimaan_logistik')
                @if ($r->status !== 'terverifikasi')
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#verifikasi-{{ $r->id }}">Verifikasi</button>
                @endif
              @endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada penerimaan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($penerimaan as $r)
  @if ($r->status !== 'terverifikasi')
    <div class="modal fade" id="verifikasi-{{ $r->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('inventory.penerimaan.verifikasi', $r) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Verifikasi {{ $r->receipt_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Hasil</label>
            <select name="verification_outcome" class="form-select mb-3" required>
              <option value="sesuai">Sesuai</option>
              <option value="tidak-sesuai">Tidak Sesuai</option>
            </select>
            <label class="form-label">Catatan (opsional)</label>
            <textarea name="verification_note" class="form-control"></textarea>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan Verifikasi</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
