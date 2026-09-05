@extends('layouts.app')

@section('title', 'UTD — Pendonor')
@section('breadcrumb', 'Konteks blood')
@section('heading', 'Pendonor Darah')

@section('actions')
  <a href="{{ route('blood.stok.index') }}" class="btn btn-link">Stok Darah &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftarkan Pendonor</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('blood.pendonor.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-3"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-4 col-md-2">
        <label class="form-label">Golongan</label>
        <select name="blood_type" class="form-select" required>
          <option value="A">A</option><option value="B">B</option><option value="AB">AB</option><option value="O">O</option>
        </select>
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Rhesus</label>
        <select name="rhesus" class="form-select" required>
          <option value="+">+</option><option value="-">-</option>
        </select>
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Jenis Kelamin</label>
        <select name="sex" class="form-select" required>
          <option value="L">Laki-laki</option><option value="P">Perempuan</option>
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Tanggal Lahir</label><input type="date" name="birth_date" class="form-control"></div>
      <div class="col-6 col-md-3"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control"></div>
      <div class="col-12 col-md-6"><label class="form-label">Alamat</label><input type="text" name="address" class="form-control"></div>
      <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Pendonor</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pendonor</th><th>Nama</th><th>Golongan</th><th>Status</th><th>Cekal</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pendonor as $p)
          <tr>
            <td class="font-monospace small">{{ $p->donor_number }}</td>
            <td>{{ $p->name }}</td>
            <td><span class="badge bg-red-lt">{{ $p->blood_type }}{{ $p->rhesus }}</span></td>
            <td>
              @if ($p->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td>
              @if ($p->isBlocked())
                <span class="badge bg-red-lt" title="{{ $p->block_reason }}">
                  Dicekal{{ $p->blocked_until ? ' s.d. ' . $p->blocked_until->format('d-m-Y') : ' (permanen)' }}
                </span>
              @else
                <span class="text-secondary small">—</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-{{ $p->id }}">Ubah</button>
                @can('utd_cekal_darah')
                  @if ($p->isBlocked())
                    <form method="POST" action="{{ route('blood.pendonor.cabut-cekal', $p) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Cabut Cekal</button>
                    </form>
                  @else
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cekal-{{ $p->id }}">Cekal</button>
                  @endif
                @endcan
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pendonor.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pendonor as $p)
  <div class="modal fade" id="edit-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('blood.pendonor.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Pendonor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $p->name }}" required></div>
          <div class="mb-2"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control" value="{{ $p->phone }}"></div>
          <div class="mb-2"><label class="form-label">Alamat</label><input type="text" name="address" class="form-control" value="{{ $p->address }}"></div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($p->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>

  @can('utd_cekal_darah')
    <div class="modal fade" id="cekal-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('blood.pendonor.cekal', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Cekal Pendonor {{ $p->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label">Alasan</label><textarea name="block_reason" class="form-control" required placeholder="mis. baru pulang dari daerah endemis malaria"></textarea></div>
            <div class="mb-2">
              <label class="form-label">Cekal Sampai Tanggal</label>
              <input type="date" name="blocked_until" class="form-control">
              <small class="text-secondary">Kosongkan untuk cekal permanen.</small>
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Cekal</button></div>
        </form>
      </div>
    </div>
  @endcan
@endforeach

@endsection
