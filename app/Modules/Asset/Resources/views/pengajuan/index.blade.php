@extends('layouts.app')

@section('title', 'Aset — Pengajuan')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Pengajuan Aset/Inventaris dari Unit')

@section('actions')
  <a href="{{ route('asset.index') }}" class="btn btn-link">&larr; Aset</a>
  @can('pengadaan_aset_inventaris')
    <a href="{{ route('asset.po.index') }}" class="btn btn-outline-primary">Pengadaan</a>
  @endcan
  @can('hibah_aset_inventaris')
    <a href="{{ route('asset.hibah.index') }}" class="btn btn-outline-primary">Hibah</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Pengadaan Aset Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('asset.pengajuan.simpan') }}">
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
        <table class="table table-sm" id="baris-pengajuan">
          <thead><tr><th>Nama Barang</th><th style="width:180px">Kategori</th><th style="width:100px">Jumlah</th></tr></thead>
          <tbody>
            @for ($i = 0; $i < 3; $i++)
              <tr>
                <td><input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Nama aset yang diminta"></td>
                <td>
                  <select name="category_id[]" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach ($kategori as $k)
                      <option value="{{ $k->id }}">{{ $k->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" step="1" min="0" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @endfor
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Ajukan Pengajuan</button>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pengajuan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pengajuan</th><th>Unit</th><th>Barang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengajuan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->requisition_number }}</td>
            <td>{{ $p->unit_name }}</td>
            <td class="text-secondary small">
              @foreach ($p->items as $baris)
                {{ $baris->item_name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity_requested, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
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
                  <form method="POST" action="{{ route('asset.pengajuan.setuju', $p) }}">
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

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Rekap Pengajuan per Departemen</h3>
    <form method="GET" action="{{ route('asset.pengajuan.index') }}" class="ms-auto d-flex gap-2">
      <input type="date" name="dari" class="form-control form-control-sm" value="{{ $dari }}">
      <input type="date" name="sampai" class="form-control form-control-sm" value="{{ $sampai }}">
      <button class="btn btn-sm btn-outline-primary">Tampilkan</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Unit</th><th>Status</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($rekapDepartemen as $unitName => $baris)
          @foreach ($baris as $b)
            <tr>
              <td>{{ $loop->first ? $unitName : '' }}</td>
              <td>{{ $b->status }}</td>
              <td class="text-end font-monospace">{{ $b->jumlah }}</td>
            </tr>
          @endforeach
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pengajuan di rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pengajuan as $p)
  @if ($p->status === 'diajukan')
    <div class="modal fade" id="tolak-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('asset.pengajuan.tolak', $p) }}">
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
