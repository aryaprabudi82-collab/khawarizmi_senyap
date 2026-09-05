@extends('layouts.app')

@section('title', 'Aset — Sirkulasi')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Sirkulasi/Mutasi Aset Antar Lokasi')

@section('actions')
  <a href="{{ route('asset.index') }}" class="btn btn-link">&larr; Aset</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pindahkan Aset</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('asset.sirkulasi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Aset</label>
        <select name="asset_id" class="form-select" required>
          @foreach ($aset as $a)
            <option value="{{ $a->id }}">{{ $a->asset_number }} &middot; {{ $a->name }} (skrg: {{ $a->location->name ?? '—' }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Lokasi Tujuan</label>
        <select name="to_location_id" class="form-select" required>
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}">{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Catatan</label>
        <input type="text" name="notes" class="form-control">
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Pindahkan</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Mutasi Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Mutasi</th><th>Aset</th><th>Dari</th><th>Ke</th><th>Tanggal</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($mutasi as $m)
          <tr>
            <td class="font-monospace small">{{ $m->transfer_number }}</td>
            <td>{{ $m->asset->asset_number }} &middot; {{ $m->asset->name }}</td>
            <td class="text-secondary small">{{ $m->fromLocation->name ?? '—' }}</td>
            <td class="text-secondary small">{{ $m->toLocation->name }}</td>
            <td class="text-secondary small">{{ $m->transferred_at->format('d-m-Y H:i') }}</td>
            <td class="text-secondary small">{{ $m->notes }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada mutasi.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
