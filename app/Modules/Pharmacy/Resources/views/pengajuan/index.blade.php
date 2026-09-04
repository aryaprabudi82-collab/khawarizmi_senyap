@extends('layouts.app')

@section('title', 'Farmasi — Pengajuan Obat & BHP')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Pengajuan Obat & BHP dari Unit')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
  @can('pengadaan_obat')
    <a href="{{ route('pharmacy.po.index') }}" class="btn btn-outline-primary">Pemesanan &amp; PO</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Pengajuan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.pengajuan.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Unit Pemohon</label>
          <select name="unit_id" class="form-select" required>
            @foreach ($unit as $u)
              <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Catatan</label>
          <input type="text" name="notes" class="form-control">
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat/Alkes/BHP</th><th>Satuan</th><th style="width:140px">Jumlah</th></tr></thead>
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

      <button class="btn btn-primary">Ajukan Pengajuan</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Pengajuan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pengajuan</th><th>Unit</th><th>Obat/BHP</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengajuan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->requisition_number }}</td>
            <td>{{ $p->unit_name }}</td>
            <td class="text-secondary small">
              @foreach ($p->items as $baris)
                {{ $baris->drug_name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity_requested, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td>
              @php $warna = ['diajukan' => 'yellow', 'disetujui' => 'blue', 'ditolak' => 'red', 'selesai' => 'green'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
              @if ($p->status === 'ditolak' && $p->rejection_reason)
                <div class="text-secondary small">{{ $p->rejection_reason }}</div>
              @endif
            </td>
            <td>
              @if ($p->status === 'diajukan')
                <div class="btn-group">
                  <form method="POST" action="{{ route('pharmacy.pengajuan.setuju', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-success">Setuju</button>
                  </form>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tolak-{{ $p->id }}">Tolak</button>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada pengajuan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pengajuan as $p)
  @if ($p->status === 'diajukan')
    <div class="modal fade" id="tolak-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('pharmacy.pengajuan.tolak', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Tolak {{ $p->requisition_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Alasan</label>
            <textarea name="rejection_reason" class="form-control" required></textarea>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Tolak</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
