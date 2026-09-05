@extends('layouts.app')

@section('title', 'Aset — Pemeliharaan Terjadwal')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Pemeliharaan Terjadwal (Preventif)')

@section('actions')
  <a href="{{ route('asset.pemeliharaan.index') }}" class="btn btn-link">Perbaikan (Reaktif) &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Buat Jadwal Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('asset.jadwal.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-3">
        <label class="form-label">Target</label>
        <select name="target_type" class="form-select" id="target-type" required>
          <option value="aset">Aset/Peralatan</option>
          <option value="gedung">Gedung/Ruang</option>
        </select>
      </div>
      <div class="col-12 col-md-3" id="pilih-aset">
        <label class="form-label">Aset</label>
        <select name="asset_id" class="form-select">
          @foreach ($aset as $a)
            <option value="{{ $a->id }}">{{ $a->asset_number }} &middot; {{ $a->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-3" id="pilih-lokasi" style="display:none">
        <label class="form-label">Gedung/Ruang</label>
        <select name="location_id" class="form-select">
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}">{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Judul</label>
        <input type="text" name="title" class="form-control" placeholder="mis. Kalibrasi Tahunan" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Interval (bulan)</label>
        <input type="number" step="1" min="1" name="interval_months" class="form-control" value="3" required>
      </div>
      <div class="col-6 col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Buat Jadwal</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Jadwal</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Jadwal</th><th>Target</th><th>Judul</th><th>Interval</th><th>Jatuh Tempo</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($jadwal as $j)
          <tr>
            <td class="font-monospace small">{{ $j->schedule_number }}</td>
            <td>{{ $j->targetLabel() }} <span class="text-secondary small">({{ $j->asset_id ? 'aset' : 'gedung' }})</span></td>
            <td>{{ $j->title }}</td>
            <td class="text-secondary small">{{ $j->interval_months }} bulan</td>
            <td>
              <span class="{{ $j->isOverdue() ? 'text-danger fw-bold' : '' }}">{{ $j->next_due_date->format('d-m-Y') }}</span>
              @if ($j->isOverdue())
                <span class="badge bg-red-lt ms-1">Terlambat</span>
              @endif
            </td>
            <td><span class="badge bg-{{ $j->is_active ? 'green' : 'secondary' }}-lt">{{ $j->is_active ? 'aktif' : 'nonaktif' }}</span></td>
            <td>
              @if ($j->is_active)
                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#catat-{{ $j->id }}">Catat Pelaksanaan</button>
                  <form method="POST" action="{{ route('asset.jadwal.nonaktifkan', $j) }}" onsubmit="return confirm('Nonaktifkan jadwal ini?')">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary">Nonaktifkan</button>
                  </form>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada jadwal pemeliharaan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($jadwal as $j)
  @if ($j->is_active)
    <div class="modal fade" id="catat-{{ $j->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('asset.jadwal.laksanakan', $j) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Catat Pelaksanaan {{ $j->schedule_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label">Tanggal Pelaksanaan</label><input type="date" name="performed_at" class="form-control" value="{{ now()->toDateString() }}"></div>
            <div class="mb-2"><label class="form-label">Catatan</label><textarea name="notes" class="form-control"></textarea></div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Simpan</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

<script>
  document.getElementById('target-type').addEventListener('change', function () {
    var aset = document.getElementById('pilih-aset');
    var lokasi = document.getElementById('pilih-lokasi');
    if (this.value === 'gedung') {
      aset.style.display = 'none';
      lokasi.style.display = '';
    } else {
      aset.style.display = '';
      lokasi.style.display = 'none';
    }
  });
</script>

@endsection
