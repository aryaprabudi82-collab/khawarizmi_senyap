@extends('layouts.app')

@section('title', 'Farmasi — Mutasi Obat & BHP')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Mutasi Obat, Alkes & BHP Antar Lokasi')

@section('actions')
  <a href="{{ route('pharmacy.laporan-stok.index') }}" class="btn btn-link">&larr; Laporan Stok</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Mutasi Baru</h3></div>
  <div class="card-body">
    <form method="GET" class="row g-2 mb-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Lokasi Asal</label>
        <select name="from_location_id" class="form-select" onchange="this.form.submit()">
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}" @selected($l->id == $lokasiAsal)>{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
    </form>

    <form method="POST" action="{{ route('pharmacy.mutasi.simpan') }}">
      @csrf
      <input type="hidden" name="from_location_id" value="{{ $lokasiAsal }}">
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
          <label class="form-label">Lokasi Tujuan</label>
          <select name="to_location_id" class="form-select" required>
            @foreach ($lokasi as $l)
              @if ($l->id != $lokasiAsal)
                <option value="{{ $l->id }}">{{ $l->name }}</option>
              @endif
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label">Catatan</label>
          <input type="text" name="notes" class="form-control">
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat</th><th>Batch</th><th class="text-end">Tersedia</th><th style="width:120px">Jumlah Mutasi</th></tr></thead>
          <tbody>
            @forelse ($batchAsal as $b)
              <tr>
                <td>
                  {{ $b->drug->name }}
                  <input type="hidden" name="batch_id[]" value="{{ $b->id }}">
                </td>
                <td class="text-secondary small">{{ $b->batch_number }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $b->quantity_on_hand, 2, ',', '.'), '0'), ',') }}</td>
                <td><input type="number" step="0.01" min="0" max="{{ $b->quantity_on_hand }}" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada batch dengan stok di lokasi asal ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Mutasikan</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Mutasi Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Mutasi</th><th>Dari</th><th>Ke</th><th>Obat/BHP</th><th>Tanggal</th></tr></thead>
      <tbody>
        @forelse ($mutasi as $m)
          <tr>
            <td class="font-monospace small">{{ $m->transfer_number }}</td>
            <td>{{ $m->fromLocation->name }}</td>
            <td>{{ $m->toLocation->name }}</td>
            <td class="text-secondary small">
              @foreach ($m->items as $baris)
                {{ $baris->drug->name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td class="text-secondary small">{{ $m->transferred_at->format('d-m-Y H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada mutasi.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
