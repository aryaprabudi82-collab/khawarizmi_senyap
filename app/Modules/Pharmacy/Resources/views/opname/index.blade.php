@extends('layouts.app')

@section('title', 'Farmasi — Stok Opname')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Stok Opname Obat & BHP')

@section('actions')
  <a href="{{ route('pharmacy.laporan-stok.index') }}" class="btn btn-link">&larr; Laporan Stok</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Buka Sesi Opname</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.opname.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Lokasi</label>
        <select name="location_id" class="form-select" required>
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}">{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Catatan</label>
        <input type="text" name="notes" class="form-control">
      </div>
      <div class="col-12 col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Buka Opname</button>
      </div>
    </form>
    <div class="form-hint mt-2">Seluruh batch dengan stok &gt; 0 di lokasi terpilih akan disiapkan untuk dihitung ulang.</div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Sesi Opname Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Opname</th><th>Lokasi</th><th class="text-center">Jumlah Batch</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($opname as $o)
          <tr>
            <td class="font-monospace small">{{ $o->opname_number }}</td>
            <td>{{ $o->location->name }}</td>
            <td class="text-center">{{ $o->items->count() }}</td>
            <td><span class="badge bg-{{ $o->status === 'selesai' ? 'green' : 'yellow' }}-lt">{{ $o->status }}</span></td>
            <td><a href="{{ route('pharmacy.opname.show', $o) }}" class="btn btn-sm btn-outline-primary">Detail</a></td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada sesi opname.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
