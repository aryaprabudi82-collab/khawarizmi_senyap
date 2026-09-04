@extends('layouts.app')

@section('title', 'Farmasi — Penggunaan BHP OK/VK')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Penggunaan BHP OK/VK')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Penggunaan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.bhp-ok.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-2">
          <label class="form-label">Ruangan</label>
          <select name="room" class="form-select" required>
            <option value="OK">OK</option>
            <option value="VK">VK</option>
          </select>
        </div>
        <div class="col-12 col-md-4"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
        <div class="col-12 col-md-3"><label class="form-label">No. RM (opsional)</label><input type="text" name="patient_mrn" class="form-control"></div>
        <div class="col-12 col-md-3"><label class="form-label">ID Operasi (opsional)</label><input type="number" name="operation_id" class="form-control" placeholder="Jika tercatat di clinical.operations"></div>
        <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat/Alkes/BHP</th><th>Satuan</th><th style="width:140px">Jumlah Dipakai</th></tr></thead>
          <tbody>
            @foreach ($obat as $o)
              <tr>
                <td>
                  {{ $o->name }}
                  <input type="hidden" name="drug_id[]" value="{{ $o->id }}">
                </td>
                <td class="text-secondary">{{ $o->unit }}</td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Simpan Penggunaan</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Penggunaan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Penggunaan</th><th>Ruangan</th><th>Pasien</th><th>Obat/BHP</th><th>Waktu</th></tr></thead>
      <tbody>
        @forelse ($penggunaan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->usage_number }}</td>
            <td><span class="badge bg-secondary-lt">{{ $p->room }}</span></td>
            <td>{{ $p->patient_name }}</td>
            <td class="text-secondary small">
              @foreach ($p->items as $baris)
                {{ $baris->drug_name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td class="text-secondary small">{{ $p->used_at->format('d-m-Y H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada penggunaan BHP tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
