@extends('layouts.app')

@section('title', 'Aset — Pemeliharaan')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Permintaan Perbaikan')

@section('actions')
  <a href="{{ route('asset.index') }}" class="btn btn-link">&larr; Aset</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Laporkan Kerusakan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('asset.pemeliharaan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Aset</label>
        <select name="asset_id" class="form-select" required>
          @foreach ($aset as $a)
            <option value="{{ $a->id }}">{{ $a->asset_number }} — {{ $a->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-6"><label class="form-label">Keluhan</label><input type="text" name="description" class="form-control" required></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Laporkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Permintaan Perbaikan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Permintaan</th><th>Aset</th><th>Keluhan</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($permintaan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->request_number }}</td>
            <td>{{ $p->asset->name }}</td>
            <td class="text-secondary small">{{ $p->description }}</td>
            <td>
              @php $warna = ['diajukan' => 'yellow', 'dikerjakan' => 'blue', 'selesai' => 'green', 'ditolak' => 'red'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
              @if ($p->status === 'ditolak' && $p->rejection_reason)
                <div class="text-secondary small">{{ $p->rejection_reason }}</div>
              @endif
            </td>
            <td>
              @if ($p->status === 'diajukan')
                <div class="btn-group">
                  <form method="POST" action="{{ route('asset.pemeliharaan.mulai', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary">Mulai</button>
                  </form>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tolak-{{ $p->id }}">Tolak</button>
                </div>
              @elseif ($p->status === 'dikerjakan')
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#selesai-{{ $p->id }}">Selesai</button>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada permintaan perbaikan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($permintaan as $p)
  @if ($p->status === 'diajukan')
    <div class="modal fade" id="tolak-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('asset.pemeliharaan.tolak', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Tolak {{ $p->request_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body"><label class="form-label">Alasan</label><textarea name="rejection_reason" class="form-control" required></textarea></div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Tolak</button></div>
        </form>
      </div>
    </div>
  @endif
  @if ($p->status === 'dikerjakan')
    <div class="modal fade" id="selesai-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('asset.pemeliharaan.selesai', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Selesaikan {{ $p->request_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label">Catatan Penyelesaian</label><textarea name="resolution_notes" class="form-control" required></textarea></div>
            <div class="mb-2">
              <label class="form-label">Kondisi Akhir</label>
              <select name="condition" class="form-select">
                <option value="baik">Baik</option>
                <option value="rusak-ringan">Rusak Ringan</option>
                <option value="rusak-berat">Rusak Berat</option>
              </select>
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Selesai</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
