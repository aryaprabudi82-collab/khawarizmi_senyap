@extends('layouts.app')

@section('title', 'Logistik — Permintaan Barang')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Permintaan Barang dari Unit')

@section('actions')
  <a href="{{ route('inventory.index') }}" class="btn btn-link">&larr; Barang</a>
  @can('ipsrs_pengadaan_barang')
    <a href="{{ route('inventory.po.index') }}" class="btn btn-outline-primary">Pengadaan</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Permintaan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('inventory.permintaan.simpan') }}">
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
          <thead><tr><th>Barang</th><th>Satuan</th><th style="width:140px">Jumlah</th></tr></thead>
          <tbody>
            @foreach ($barang as $b)
              <tr>
                <td>
                  {{ $b->name }}
                  <input type="hidden" name="item_id[]" value="{{ $b->id }}">
                </td>
                <td class="text-secondary">{{ $b->unit_of_measure }}</td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Ajukan Permintaan</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Permintaan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Permintaan</th><th>Unit</th><th>Barang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($permintaan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->requisition_number }}</td>
            <td>{{ $p->unit_name }}</td>
            <td class="text-secondary small">
              @foreach ($p->items as $baris)
                {{ $baris->item->name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity_requested, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
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
                  <form method="POST" action="{{ route('inventory.permintaan.setuju', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-success">Setuju</button>
                  </form>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tolak-{{ $p->id }}">Tolak</button>
                </div>
              @elseif ($p->status === 'disetujui')
                <form method="POST" action="{{ route('inventory.permintaan.keluarkan', $p) }}">
                  @csrf
                  <button class="btn btn-sm btn-outline-primary">Keluarkan Barang</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada permintaan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($permintaan as $p)
  @if ($p->status === 'diajukan')
    <div class="modal fade" id="tolak-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('inventory.permintaan.tolak', $p) }}">
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
