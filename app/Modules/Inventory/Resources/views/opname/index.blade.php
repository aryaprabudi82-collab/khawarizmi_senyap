@extends('layouts.app')

@section('title', 'Logistik — Stok Opname')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Stok Opname Barang Non-Medis')

@section('actions')
  <a href="{{ route('inventory.laporan.index') }}" class="btn btn-link">&larr; Riwayat &amp; Sirkulasi</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Buka Sesi Opname</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('inventory.opname.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-8">
        <label class="form-label">Catatan</label>
        <input type="text" name="notes" class="form-control">
      </div>
      <div class="col-12 col-md-4 d-flex align-items-end">
        <button class="btn btn-primary w-100">Buka Opname</button>
      </div>
    </form>
    <div class="form-hint mt-2">Seluruh barang aktif akan disiapkan untuk dihitung ulang.</div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Sesi Opname Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Opname</th><th class="text-center">Jumlah Barang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($opname as $o)
          <tr>
            <td class="font-monospace small">{{ $o->opname_number }}</td>
            <td class="text-center">{{ $o->items->count() }}</td>
            <td><span class="badge bg-{{ $o->status === 'selesai' ? 'green' : 'yellow' }}-lt">{{ $o->status }}</span></td>
            <td><a href="{{ route('inventory.opname.show', $o) }}" class="btn btn-sm btn-outline-primary">Detail</a></td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada sesi opname.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
