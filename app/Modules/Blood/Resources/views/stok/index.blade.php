@extends('layouts.app')

@section('title', 'UTD — Stok Darah')
@section('breadcrumb', 'Konteks blood')
@section('heading', 'Stok Unit Darah')

@section('actions')
  <a href="{{ route('blood.pendonor.index') }}" class="btn btn-link">&larr; Pendonor</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Pengambilan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('blood.stok.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-3">
        <label class="form-label">Pendonor</label>
        <select name="donor_id" class="form-select">
          <option value="">— tanpa pendonor terdaftar —</option>
          @foreach ($pendonorAktif as $p)
            <option value="{{ $p->id }}">{{ $p->donor_number }} — {{ $p->name }} ({{ $p->blood_type }}{{ $p->rhesus }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Golongan</label>
        <select name="blood_type" class="form-select" required>
          <option value="A">A</option><option value="B">B</option><option value="AB">AB</option><option value="O">O</option>
        </select>
      </div>
      <div class="col-4 col-md-1">
        <label class="form-label">Rh</label>
        <select name="rhesus" class="form-select" required>
          <option value="+">+</option><option value="-">-</option>
        </select>
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Komponen</label>
        <select name="component" class="form-select" required>
          <option value="whole-blood">Whole Blood</option>
          <option value="prc">PRC</option>
          <option value="plasma">Plasma</option>
          <option value="platelet">Platelet</option>
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Volume (ml)</label><input type="number" name="volume_ml" class="form-control" required></div>
      <div class="col-6 col-md-2"><label class="form-label">Waktu Ambil</label><input type="datetime-local" name="collected_at" class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
      <div class="col-12 col-md-3"><label class="form-label">Kedaluwarsa</label><input type="date" name="expiry_date" class="form-control" required></div>
      <div class="col-12"><button class="btn btn-primary">Simpan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Unit Darah</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Unit</th><th>Golongan</th><th>Komponen</th><th>Kedaluwarsa</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($unit as $u)
          <tr>
            <td class="font-monospace small">{{ $u->unit_number }}</td>
            <td><span class="badge bg-red-lt">{{ $u->blood_type }}{{ $u->rhesus }}</span></td>
            <td class="text-uppercase">{{ $u->component }}</td>
            <td class="text-secondary small">{{ $u->expiry_date->format('d-m-Y') }}</td>
            <td>
              @php
                $warna = ['karantina' => 'yellow', 'tersedia' => 'green', 'ditahan' => 'orange', 'dikeluarkan' => 'blue', 'kedaluwarsa' => 'secondary', 'ditolak' => 'red'][$u->status];
              @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $u->status }}</span>
            </td>
            <td>
              <div class="btn-group">
                @if (in_array($u->status, ['karantina', 'ditahan']))
                  <form method="POST" action="{{ route('blood.stok.rilis', $u) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-success">Rilis</button>
                  </form>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tolak-{{ $u->id }}">Tolak</button>
                @elseif ($u->status === 'tersedia')
                  <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#tahan-{{ $u->id }}">Tahan</button>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#serahkan-{{ $u->id }}">Serahkan</button>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada unit darah tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($unit as $u)
  <div class="modal fade" id="tolak-{{ $u->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('blood.stok.tolak', $u) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Tolak Unit {{ $u->unit_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><label class="form-label">Alasan</label><textarea name="reason" class="form-control" required></textarea></div>
        <div class="modal-footer"><button type="submit" class="btn btn-danger">Tolak</button></div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="tahan-{{ $u->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('blood.stok.tahan', $u) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Tahan Unit {{ $u->unit_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><label class="form-label">Alasan</label><textarea name="reason" class="form-control" required></textarea></div>
        <div class="modal-footer"><button type="submit" class="btn btn-warning">Tahan</button></div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="serahkan-{{ $u->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('blood.stok.serahkan', $u) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Serahkan Unit {{ $u->unit_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Indikasi Klinis</label><textarea name="indication" class="form-control"></textarea></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Serahkan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
