@extends('layouts.app')

@section('title', 'Parkir — Jenis & Kartu')
@section('breadcrumb', 'Konteks parking')
@section('heading', 'Jenis Parkir & Stok Kartu')

@section('actions')
  <a href="{{ route('parking.sesi.index') }}" class="btn btn-link">Gerbang Parkir &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tambah Jenis Parkir</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('parking.master.tarif.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" maxlength="8" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Nama Jenis</label><input type="text" name="name" class="form-control" placeholder="mis. Motor" required></div>
      <div class="col-6 col-md-2"><label class="form-label">Tarif (Rp)</label><input type="number" name="fee" class="form-control" min="0" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Basis</label>
        <select name="basis" class="form-select" required>
          <option value="jam">Per Jam</option>
          <option value="harian">Per Hari</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Bebas Biaya (menit)</label>
        <input type="number" name="free_minutes" class="form-control" min="0" max="1440" value="0">
        <small class="text-secondary">Toleransi awal, mis. antar-jemput pasien.</small>
      </div>
      <div class="col-12"><button class="btn btn-primary">Simpan Jenis</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Jenis &amp; Tarif</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Tarif</th><th>Basis</th><th>Bebas Biaya</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($tarif as $t)
          <tr>
            <td class="font-monospace small">{{ $t->code }}</td>
            <td>{{ $t->name }}</td>
            <td>Rp {{ number_format($t->fee, 0, ',', '.') }}</td>
            <td>{{ $t->basis === 'jam' ? 'per jam' : 'per hari' }}</td>
            <td class="text-secondary small">{{ $t->free_minutes }} menit</td>
            <td>
              @if ($t->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#tarif-{{ $t->id }}">Ubah</button></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada jenis parkir.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftarkan Kartu Barcode</h3></div>
  <div class="card-body">
    <p class="text-secondary small">Stok kartu fisik yang dibagikan di gerbang. Satu kartu dipakai berulang lintas sesi.</p>
    <form method="POST" action="{{ route('parking.master.kartu.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4"><label class="form-label">Kode Barcode</label><input type="text" name="barcode" class="form-control" maxlength="32" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Nomor Kartu</label><input type="text" name="card_number" class="form-control" maxlength="8" required></div>
      <div class="col-12"><button class="btn btn-primary">Daftarkan Kartu</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Stok Kartu</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor Kartu</th><th>Kode Barcode</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($kartu as $k)
          <tr>
            <td>{{ $k->card_number }}</td>
            <td class="font-monospace small">{{ $k->barcode }}</td>
            <td>
              @if ($k->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td>
              @if ($k->is_active)
                <form method="POST" action="{{ route('parking.master.kartu.nonaktifkan', $k) }}">
                  @csrf
                  <button class="btn btn-sm btn-outline-danger">Nonaktifkan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada kartu terdaftar.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($tarif as $t)
  <div class="modal fade" id="tarif-{{ $t->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('parking.master.tarif.perbarui', $t) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah {{ $t->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama Jenis</label><input type="text" name="name" class="form-control" value="{{ $t->name }}" required></div>
          <div class="mb-2"><label class="form-label">Tarif (Rp)</label><input type="number" name="fee" class="form-control" min="0" value="{{ $t->fee }}" required></div>
          <div class="mb-2">
            <label class="form-label">Basis</label>
            <select name="basis" class="form-select" required>
              <option value="jam" @selected($t->basis === 'jam')>Per Jam</option>
              <option value="harian" @selected($t->basis === 'harian')>Per Hari</option>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Bebas Biaya (menit)</label><input type="number" name="free_minutes" class="form-control" min="0" max="1440" value="{{ $t->free_minutes }}"></div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($t->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
